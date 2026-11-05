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

namespace local_coursepilot;

use context;
use qformat_xml;
use question_bank;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Shared answer rules and native question XML serialization.
 * Creation keeps its fixed template; updates serialize the patched native object.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class question_xml {
    /**
     * Reject answer sets that Moodle cannot save as multiple-choice options.
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
        self::validate_fraction_range($answers);
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
     * Reject individual weights outside Moodle's supported range.
     *
     * @param mixed[] $answers
     * @return void
     */
    private static function validate_fraction_range(array $answers): void {
        foreach ($answers as $answer) {
            if ($answer['fraction'] < -1 || $answer['fraction'] > 1) {
                throw new \invalid_parameter_exception('fraction must be between -1 and 1.');
            }
        }
    }

    /**
     * Loads the latest question version in the native object shape expected
     * by {@see \qformat_xml::writequestion()}: the question row plus qtype options
     * from {@see \question_type::get_question_options()}. No capability check;
     * the caller checks read permission for export or write permission for
     * the multiple-choice patch.
     *
     * Patching native properties preserves every field outside the patch:
     * name/questiontext/generalfeedback/
     * defaultmark/options->single/options->answers. Other values (penalty,
     * shuffleanswers, answernumbering, combined feedback, tags, hints, etc.)
     * automatically remain untouched because they are never modified.
     *
     * @param int $questionid
     * @return array{0: \stdClass, 1: \stdClass, 2: \context} [native question object, category, context]
     */
    public static function resolve_native_question(int $questionid): array {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $questionid]);
        if (!$version) {
            throw new \moodle_exception(
                'notfound',
                'error',
                '',
                null,
                'No question with questionid ' . $questionid . ' found.'
            );
        }

        $latest = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$version->questionbankentryid],
            IGNORE_MULTIPLE
        );

        $entry = $DB->get_record('question_bank_entries', ['id' => $latest->questionbankentryid], '*', MUST_EXIST);
        $category = $DB->get_record('question_categories', ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);

        $question = $DB->get_record('question', ['id' => $latest->questionid], '*', MUST_EXIST);
        $question->export_process = true;
        $question->categoryobject = $category;

        $qtype = question_bank::get_qtype($question->qtype);
        $qtype->get_question_options($question);

        $question->contextid = (int) $context->id;
        $question->idnumber = (string) $entry->idnumber;

        return [$question, $category, $context];
    }

    /**
     * Writes a native question as Moodle XML, optionally replacing embedded files
     * with named placeholders.
     *
     * @param \stdClass $question
     * @param \stdClass $category
     * @param \context $context
     * @param bool $stripfiles true (default, backward-compatible for existing callers such as
     *        the multiple-choice patch): replace embedded files with placeholders.
     *        false: retain actual base64 unchanged (standard export mode, Spec 0018 §7.2).
     * @return array{0: string, 1: string[]} [XML fragment, removed filenames (empty when not stripped)]
     */
    public static function question_to_xml(
        \stdClass $question,
        \stdClass $category,
        \context $context,
        bool $stripfiles = true
    ): array {
        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);

        $xml = $qformat->writequestion($question);
        if (!$stripfiles) {
            return [$xml, []];
        }
        return self::strip_embedded_files($xml);
    }

    /**
     * Replaces each <file> block (base64 file content) with an XML comment
     * placeholder naming the original file. Never strip without a trace:
     * the teacher/AI must see which file is missing (Spec 0017, image and size rules).
     *
     * @param string $xml
     * @return array{0: string, 1: string[]} [cleaned XML, removed filenames]
     */
    private static function strip_embedded_files(string $xml): array {
        $filenames = [];
        $cleaned = preg_replace_callback(
            '/<file\b[^>]*\bname="([^"]*)"[^>]*>.*?<\/file>\n?/s',
            static function (array $m) use (&$filenames): string {
                $filename = $m[1];
                $filenames[] = $filename;
                // Note: "--" would prematurely close the XML comment.
                $safe = str_replace('--', '- -', $filename);
                return '<!-- File removed (no binary transport in export): ' . $safe . " -->\n";
            },
            $xml
        );

        return [$cleaned ?? $xml, $filenames];
    }
}
