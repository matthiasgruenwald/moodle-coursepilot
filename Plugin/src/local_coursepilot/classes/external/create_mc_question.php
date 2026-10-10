<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\question_suspect_gate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Facade over the XML core (Spec 0017 §7.1, ticket #418): the teacher
 * creates a multiple-choice question through typed fields and never sees
 * XML. The SERVER builds XML from a fixed template and writes through
 * {@see \local_coursepilot\external\import_questions_xml} (T4), including
 * round-trip verification and rollback. The AI cannot generate structurally
 * invalid XML for multiple-choice questions.
 *
 * Suspect gate (T414 format): creation supplies no idnumber to match.
 * Unlike import_questions_xml, an existing entry with the same name in the
 * target category already counts as suspect, without an idnumber collision.
 * The gate runs BEFORE building XML and writes nothing on suspicion.
 * Only a repeated, explicitly confirmed call writes.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_mc_question extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID of the target question bank category'),
            'name' => new external_value(PARAM_TEXT, 'Unique name of the question within the category'),
            'questiontext' => new external_value(PARAM_RAW, 'Question text (HTML)'),
            'selectionmode' => new external_value(PARAM_ALPHA, 'single or multiple', VALUE_DEFAULT, 'single'),
            'answers' => new external_multiple_structure(new external_single_structure([
                'answer' => new external_value(PARAM_RAW, 'Answer text (HTML)'),
                'fraction' => new external_value(PARAM_FLOAT, 'Weight between -1 and 1'),
                'feedback' => new external_value(PARAM_RAW, 'Answer-specific feedback (HTML)', VALUE_DEFAULT, ''),
            ]), 'Answer options, at least 2'),
            'defaultmark' => new external_value(PARAM_FLOAT, 'Default mark of the question', VALUE_DEFAULT, 1.0),
            'generalfeedback' => new external_value(PARAM_RAW, 'General feedback (HTML, optional)', VALUE_DEFAULT, ''),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms a previously reported suspect case (same-named entry in the '
                    . 'target category) and creates the question anyway as a new entry. Omit or false on the '
                    . 'first call.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Runs the create mc question tool.
     *
     * @param int $categoryid
     * @param string $name
     * @param string $questiontext
     * @param string $selectionmode
     * @param mixed[] $answers
     * @param float $defaultmark
     * @param string $generalfeedback
     * @param bool $confirmed
     * @return mixed[]
     */
    public static function execute(
        int $categoryid,
        string $name,
        string $questiontext,
        string $selectionmode,
        array $answers,
        float $defaultmark = 1.0,
        string $generalfeedback = '',
        bool $confirmed = false
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'name' => $name,
            'questiontext' => $questiontext,
            'selectionmode' => $selectionmode,
            'answers' => $answers,
            'defaultmark' => $defaultmark,
            'generalfeedback' => $generalfeedback,
            'confirmed' => $confirmed,
        ]);

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        self::validate_answers($params['answers'], $params['selectionmode']);

        // Suspect gate BEFORE building XML: creation supplies no idnumber
        // to match, so an existing entry with the same name in the target
        // category already counts as suspect (unlike import_questions_xml).
        $candidates = question_suspect_gate::find_name_candidates((int) $params['categoryid'], $params['name']);
        if (!empty($candidates) && !$params['confirmed']) {
            return array_merge(
                [
                    'name' => $params['name'],
                    'questionid' => 0,
                    'questionbankentryid' => 0,
                    'version' => 0,
                    'status' => 'suspect',
                    'message' => get_string('mcquestionsuspect', 'local_coursepilot', $params['name']),
                ],
                [
                    'idnumber' => '',
                    'categoryid' => (int) $params['categoryid'],
                    'candidates' => $candidates,
                    'questiontext_old' => '',
                    'questiontext_new' => $params['questiontext'],
                ]
            );
        }

        $xml = self::build_xml($params);

        $imported = import_questions_xml::execute((int) $params['categoryid'], $xml);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);
        $question = $imported['questions'][0];

        $entry = $DB->get_record('question_bank_entries', ['id' => $question['questionbankentryid']], '*', MUST_EXIST);
        $latest = question_suspect_gate::latest_version_question((int) $entry->id);

        return array_merge(
            [
                'name' => $question['name'],
                'questionid' => (int) $latest->id,
                'questionbankentryid' => (int) $question['questionbankentryid'],
                'version' => (int) $question['version'],
                'status' => $question['status'],
                'message' => get_string(
                    'mcquestioncreated',
                    'local_coursepilot',
                    (object) [
                        'name' => $params['name'],
                        'entryid' => $question['questionbankentryid'],
                        'version' => $question['version'],
                    ]
                ),
            ],
            question_suspect_gate::empty_result()
        );
    }

    /**
     * Validates answer options against the same rules as the local original
     * ({@see \local_coursepilot\external\create_mc_question}): at least two
     * answers, fraction/correct consistency, exactly one correct answer for
     * "single", and positive fractions summing to exactly 1.
     * qtype_multichoice::save_question_options() requires this; otherwise an
     * internal Moodle error would replace a useful response.
     *
     * Public: reused by {@see \local_coursepilot\external\update_mc_question}
     * (ticket #419), which patches the same simple fields rather than creating.
     *
     * @param mixed[] $answers
     * @param string $selectionmode
     * @return void
     */
    public static function validate_answers(array $answers, string $selectionmode): void {
        if (count($answers) < 2) {
            throw new \invalid_parameter_exception('A multiple-choice question needs at least 2 answers.');
        }
        if (!in_array($selectionmode, ['single', 'multiple'], true)) {
            throw new \invalid_parameter_exception('selectionmode must be single or multiple.');
        }
        foreach ($answers as $answer) {
            if ($answer['fraction'] < -1 || $answer['fraction'] > 1) {
                throw new \invalid_parameter_exception('fraction must be between -1 and 1.');
            }
        }
        $correctcount = count(array_filter($answers, static fn($answer) => (float) $answer['fraction'] > 0));
        if ($selectionmode === 'single' && $correctcount !== 1) {
            throw new \invalid_parameter_exception('Single selection needs exactly one correct answer.');
        }
        if ($correctcount === 0) {
            throw new \invalid_parameter_exception('At least one answer must have a positive fraction.');
        }
        $positivesum = round(array_sum(array_map(
            static fn($answer) => max(0.0, (float) $answer['fraction']),
            $answers
        )), 2);
        if (abs($positivesum - 1.0) > 0.001) {
            throw new \invalid_parameter_exception(
                'Positive fraction values must sum to exactly 1 (currently ' . $positivesum . ').'
            );
        }
    }

    /**
     * Builds Moodle XML for a multichoice question from a fixed template.
     * Only the server writes XML for multiple-choice questions. No idnumber
     * is included: the XML core (import_questions_xml) generates and assigns
     * one for a first import. No further gate is needed there, as this
     * endpoint's gate has already decided BEFORE this call.
     *
     * @param mixed[] $params
     * @return string
     */
    private static function build_xml(array $params): string {
        $answersxml = '';
        foreach ($params['answers'] as $answer) {
            $fractionpercent = round(((float) $answer['fraction']) * 100, 5);
            $answersxml .= '    <answer fraction="' . $fractionpercent . '" format="html">' . "\n"
                . '      <text><![CDATA[' . self::cdata($answer['answer']) . ']]></text>' . "\n"
                . '      <feedback format="html"><text><![CDATA[' . self::cdata($answer['feedback'] ?? '')
                . ']]></text></feedback>' . "\n"
                . '    </answer>' . "\n";
        }

        $single = $params['selectionmode'] === 'single' ? 'true' : 'false';
        $name = htmlspecialchars($params['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $questiontext = self::cdata($params['questiontext']);
        $generalfeedback = self::cdata($params['generalfeedback']);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name><text>{$name}</text></name>
    <questiontext format="html"><text><![CDATA[{$questiontext}]]></text></questiontext>
    <generalfeedback format="html"><text><![CDATA[{$generalfeedback}]]></text></generalfeedback>
    <defaultgrade>{$params['defaultmark']}</defaultgrade>
    <penalty>0.3333333</penalty>
    <hidden>0</hidden>
    <idnumber></idnumber>
    <single>{$single}</single>
    <shuffleanswers>true</shuffleanswers>
    <answernumbering>abc</answernumbering>
    <correctfeedback format="html"><text></text></correctfeedback>
    <partiallycorrectfeedback format="html"><text></text></partiallycorrectfeedback>
    <incorrectfeedback format="html"><text></text></incorrectfeedback>
{$answersxml}  </question>
</quiz>
XML;
    }

    /**
     * Makes text safe for a CDATA section.
     *
     * CDATA has no escaping; only "]]>" can close the section. Split it across
     * two sections: the first ends after "]]", the second starts before ">".
     * The combined text remains identical character by character.
     *
     * Without this, questions containing "]]>" break. In computing lessons
     * (XML, HTML, CDATA itself), that is teaching material, not an edge case.
     *
     * @param string $text
     * @return string
     */
    private static function cdata(string $text): string {
        return str_replace(']]>', ']]]]><![CDATA[>', $text);
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge(
            [
                'name' => new external_value(PARAM_TEXT, 'Name of the question'),
                'questionid' => new external_value(PARAM_INT, 'ID of the newly created question row (0 for "suspect")'),
                'questionbankentryid' => new external_value(
                    PARAM_INT,
                    'ID of the question_bank_entries row (question identity, 0 for "suspect")'
                ),
                'version' => new external_value(PARAM_INT, 'Version number (initially 1, 0 for "suspect")'),
                'status' => new external_value(PARAM_ALPHAEXT, '"first_import" (first import) | "suspect" (suspect case)'),
                'message' => new external_value(PARAM_RAW, 'Teacher-facing message with bank entry and version'),
            ],
            question_suspect_gate::response_fields()
        ));
    }
}
