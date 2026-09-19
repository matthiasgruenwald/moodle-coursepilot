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
 * Fassade ueber dem XML-Kern (Spec 0017 §7.1, Ticket #418): eine Lehrkraft
 * legt eine Multiple-Choice-Frage weiterhin ueber typisierte Felder an und
 * sieht nie XML - der SERVER baut die XML aus einer festen Vorlage und
 * schreibt ueber {@see \local_coursepilot\external\import_questions_xml}
 * (T4), inklusive Round-Trip-Pruefung und Rollback. Fuer Multiple-Choice
 * schreibt die KI damit strukturell nie fehlerhafte XML.
 *
 * Verdachtsfall-Gate (T414-Format): eine Neuanlage bringt nie eine idnumber
 * mit, gegen die gematcht werden koennte - anders als bei
 * import_questions_xml ist deshalb bereits ein gleichnamiger Eintrag in der
 * Zielkategorie der Verdachtsfall, nicht erst eine idnumber-Kollision.
 * Dieses Gate wird VOR dem Bau der XML geprueft; ein Verdachtsfall schreibt
 * nichts. Erst ein erneuter, ausdruecklich bestaetigter Aufruf schreibt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_mc_question extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID der Ziel-Fragenbank-Kategorie'),
            'name' => new external_value(PARAM_TEXT, 'Eindeutiger Name der Frage innerhalb der Kategorie'),
            'questiontext' => new external_value(PARAM_RAW, 'Fragetext (HTML)'),
            'selectionmode' => new external_value(PARAM_ALPHA, 'single oder multiple', VALUE_DEFAULT, 'single'),
            'answers' => new external_multiple_structure(new external_single_structure([
                'answer' => new external_value(PARAM_RAW, 'Antworttext (HTML)'),
                'fraction' => new external_value(PARAM_FLOAT, 'Gewicht zwischen -1 und 1'),
                'feedback' => new external_value(PARAM_RAW, 'Antwortspezifisches Feedback (HTML)', VALUE_DEFAULT, ''),
            ]), 'Antwortoptionen, mindestens 2'),
            'defaultmark' => new external_value(PARAM_FLOAT, 'Standard-Punktzahl der Frage', VALUE_DEFAULT, 1.0),
            'generalfeedback' => new external_value(PARAM_RAW, 'Allgemeines Feedback (HTML, optional)', VALUE_DEFAULT, ''),
            'bestaetigt' => new external_value(
                PARAM_BOOL,
                'true bestaetigt ausdruecklich einen zuvor gemeldeten Verdachtsfall (gleichnamiger Eintrag in der '
                    . 'Zielkategorie) und legt die Frage trotzdem als neuen Eintrag an. Beim ersten Aufruf weglassen '
                    . 'oder false.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param int $categoryid
     * @param string $name
     * @param string $questiontext
     * @param string $selectionmode
     * @param array $answers
     * @param float $defaultmark
     * @param string $generalfeedback
     * @param bool $bestaetigt
     * @return array
     */
    public static function execute(
        int $categoryid,
        string $name,
        string $questiontext,
        string $selectionmode,
        array $answers,
        float $defaultmark = 1.0,
        string $generalfeedback = '',
        bool $bestaetigt = false
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
            'bestaetigt' => $bestaetigt,
        ]);

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        self::validate_answers($params['answers'], $params['selectionmode']);

        // Verdachtsfall-Gate VOR dem Bau der XML: eine Neuanlage bringt nie
        // eine idnumber mit, gegen die gematcht werden koennte - deshalb
        // zaehlt bereits ein gleichnamiger Eintrag in der Zielkategorie als
        // Verdachtsfall (anders als bei import_questions_xml).
        $candidates = question_suspect_gate::find_name_candidates((int) $params['categoryid'], $params['name']);
        if (!empty($candidates) && !$params['bestaetigt']) {
            return array_merge(
                [
                    'name' => $params['name'],
                    'questionid' => 0,
                    'questionbankentryid' => 0,
                    'version' => 0,
                    'status' => 'verdachtsfall',
                    'meldung' => 'Verdachtsfall: In der Zielkategorie gibt es bereits einen Eintrag mit dem Namen "'
                        . $params['name'] . '". Nichts wurde angelegt. Zum Anlegen als neuer Eintrag trotzdem '
                        . 'erneut mit bestaetigt=true aufrufen.',
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
                'meldung' => 'MC-Frage "' . $params['name'] . '" angelegt (Bank-Eintrag '
                    . $question['questionbankentryid'] . ', Version ' . $question['version'] . ').',
            ],
            question_suspect_gate::empty_result()
        );
    }

    /**
     * Prueft die Antwortoptionen gegen dieselben Regeln wie das lokale
     * Vorbild ({@see \local_coursepilot\external\create_mc_question}):
     * mindestens 2 Antworten, fraction/correct-Konsistenz, genau eine
     * richtige Antwort bei "single", positive fractions summieren zu genau 1
     * (qtype_multichoice::save_question_options() verlangt das zwingend -
     * sonst interner Moodle-Fehler statt sauberer Rueckmeldung).
     *
     * Public: wiederverwendet von {@see \local_coursepilot\external\update_mc_question}
     * (Ticket #419), das dieselben schlichten Felder patcht statt neu anlegt.
     *
     * @param array $answers
     * @param string $selectionmode
     * @return void
     */
    public static function validate_answers(array $answers, string $selectionmode): void {
        if (count($answers) < 2) {
            throw new \invalid_parameter_exception('Eine Multiple-Choice-Frage braucht mindestens 2 Antworten.');
        }
        if (!in_array($selectionmode, ['single', 'multiple'], true)) {
            throw new \invalid_parameter_exception('selectionmode muss single oder multiple sein.');
        }
        foreach ($answers as $answer) {
            if ($answer['fraction'] < -1 || $answer['fraction'] > 1) {
                throw new \invalid_parameter_exception('fraction muss zwischen -1 und 1 liegen.');
            }
        }
        $correctcount = count(array_filter($answers, static fn($answer) => (float) $answer['fraction'] > 0));
        if ($selectionmode === 'single' && $correctcount !== 1) {
            throw new \invalid_parameter_exception('Eine Einfachauswahl braucht genau eine richtige Antwort.');
        }
        if ($correctcount === 0) {
            throw new \invalid_parameter_exception('Mindestens eine Antwort muss eine positive fraction haben.');
        }
        $positivesum = round(array_sum(array_map(
            static fn($answer) => max(0.0, (float) $answer['fraction']), $answers)), 2);
        if (abs($positivesum - 1.0) > 0.001) {
            throw new \invalid_parameter_exception(
                'Die positiven fraction-Werte muessen in Summe genau 1 ergeben (aktuell ' . $positivesum . ').');
        }
    }

    /**
     * Baut die Moodle-XML fuer eine multichoice-Frage aus einer festen
     * Vorlage - die KI schreibt fuer Multiple-Choice nie XML, nur der
     * Server. Keine idnumber im XML: das laesst den XML-Kern
     * (import_questions_xml) eine neue generieren und vergeben - "echter
     * Erstimport", kein weiteres Gate dort (das Gate dieses Endpunkts hat
     * bereits VOR diesem Aufruf entschieden).
     *
     * @param array $params
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
     * Macht einen Text fuer einen CDATA-Abschnitt sicher.
     *
     * In CDATA gibt es keine Maskierung - die einzige Zeichenfolge, die den
     * Abschnitt beenden kann, ist "]]>". Der uebliche Kniff ist, sie auf zwei
     * Abschnitte aufzuteilen: der erste endet nach "]]", der zweite beginnt
     * vor ">". Der zusammengesetzte Textinhalt bleibt Zeichen fuer Zeichen
     * derselbe.
     *
     * Ohne das zerbricht jede Frage, deren Text "]]>" enthaelt - im
     * Informatikunterricht (XML, HTML, CDATA selbst) Unterrichtsstoff, kein
     * Randfall.
     *
     * @param string $text
     * @return string
     */
    private static function cdata(string $text): string {
        return str_replace(']]>', ']]]]><![CDATA[>', $text);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge(
            [
                'name' => new external_value(PARAM_TEXT, 'Name der Frage'),
                'questionid' => new external_value(PARAM_INT, 'ID der neu angelegten question-Zeile (0 bei "verdachtsfall")'),
                'questionbankentryid' => new external_value(
                    PARAM_INT,
                    'ID des question_bank_entries (Frage-Identitaet, 0 bei "verdachtsfall")'
                ),
                'version' => new external_value(PARAM_INT, 'Versionsnummer (initial 1, 0 bei "verdachtsfall")'),
                'status' => new external_value(PARAM_ALPHA, '"erstimport" | "verdachtsfall"'),
                'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Meldung mit Bank-Eintrag und Version'),
            ],
            question_suspect_gate::response_fields()
        ));
    }
}
