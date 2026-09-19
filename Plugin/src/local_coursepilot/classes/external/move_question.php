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
 * Verschiebt einen Fragenbank-Eintrag samt aller Versionen in eine andere
 * Kategorie (Spec 0017 §7.1, Ticket #414) - Portierung von
 * local_coursepilot\external\move_question, jetzt mit Verdachtsfall-Gate
 * *vor* dem Umzug.
 *
 * Der Core (question_move_questions_to_category() in lib/questionlib.php,
 * ueber core_question\external\move_questions ->
 * qbank_bulkmove\helper::bulk_move_questions()) loest eine
 * idnumber-Kollision in der Zielkategorie still mit einem "_N"-Suffix -
 * genau in dem Moment, in dem die Lehrkraft glaubt, nur aufzuraeumen, zerreisst
 * das die Abstammung (ADR 0015). Dieser Endpunkt prueft dieselbe Kollision
 * (eindeutiger DB-Index (questioncategoryid, idnumber), siehe
 * lib/db/install.xml) *vor* dem Aufruf und meldet sie ueber das gemeinsame
 * Verdachtsfall-Gate-Format ({@see \local_coursepilot\question_suspect_gate}),
 * statt sie dem Core-Suffix-Mechanismus stillschweigend zu ueberlassen.
 * Bestaetigt die Lehrkraft ausdruecklich ("bestaetigt": true), laeuft der
 * Core-Suffix-Mechanismus bewusst mit - er ist non-destruktiv (Suffix statt
 * Ueberschreiben) und war bereits vor diesem Ticket der Weg, auf dem eine
 * Frage ihre alte idnumber im Zweifel behaelt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class move_question extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'questionid einer beliebigen Version der zu verschiebenden Frage'),
            'targetcategoryid' => new external_value(PARAM_INT, 'ID der Ziel-Fragenbank-Kategorie'),
            'bestaetigt' => new external_value(
                PARAM_BOOL,
                'true bestaetigt ausdruecklich einen zuvor gemeldeten Verdachtsfall (idnumber-Kollision in der '
                    . 'Zielkategorie) und fuehrt den Umzug trotzdem aus. Beim ersten Aufruf weglassen oder false.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param int $questionid
     * @param int $targetcategoryid
     * @param bool $bestaetigt
     * @return array
     */
    public static function execute(int $questionid, int $targetcategoryid, bool $bestaetigt = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'targetcategoryid' => $targetcategoryid,
            'bestaetigt' => $bestaetigt,
        ]);

        $version = $DB->get_record('question_versions', ['questionid' => $params['questionid']], '*', MUST_EXIST);
        $entry = $DB->get_record('question_bank_entries', ['id' => $version->questionbankentryid], '*', MUST_EXIST);
        $sourcecategory = $DB->get_record('question_categories', ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $targetcategory = $DB->get_record('question_categories', ['id' => $params['targetcategoryid']], '*', MUST_EXIST);

        $sourcecontext = context::instance_by_id((int) $sourcecategory->contextid, MUST_EXIST);
        self::validate_context($sourcecontext);
        require_capability('local/coursepilot:use', $sourcecontext);

        $targetcontext = context::instance_by_id((int) $targetcategory->contextid, MUST_EXIST);
        if ((int) $targetcontext->id !== (int) $sourcecontext->id) {
            self::validate_context($targetcontext);
        }
        require_capability('local/coursepilot:use', $targetcontext);
        require_capability('moodle/question:add', $targetcontext);

        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id], 'version ASC');
        $questionids = array_values(array_map(static fn($item): int => (int) $item->questionid, $versions));
        foreach ($questionids as $versionquestionid) {
            $question = $DB->get_record('question', ['id' => $versionquestionid], '*', MUST_EXIST);
            question_require_capability_on($question, 'move');
        }

        $idnumber = (string) ($entry->idnumber ?? '');
        $collision = question_suspect_gate::find_idnumber_collision(
            (int) $targetcategory->id,
            $idnumber,
            (int) $entry->id
        );

        if ($collision !== null && !$params['bestaetigt']) {
            // Verdachtsfall: nichts wird geschrieben (ADR 0015, Spec 0017 §7.1).
            $latestversion = end($versions);
            $newquestiontext = $latestversion
                ? (string) $DB->get_field('question', 'questiontext', ['id' => (int) $latestversion->questionid], MUST_EXIST)
                : '';

            return array_merge(
                [
                    'status' => 'verdachtsfall',
                    'questionbankentryid' => (int) $entry->id,
                    'versionids' => [],
                    'idnumber_disambiguiert' => false,
                    'meldung' => 'Verdachtsfall: In der Zielkategorie gibt es bereits einen Eintrag mit der '
                        . 'idnumber "' . $idnumber . '". Nichts wurde verschoben. Zum Verschieben trotz Kollision '
                        . 'erneut mit bestaetigt=true aufrufen.',
                ],
                question_suspect_gate::response($collision, (int) $targetcategory->id, $newquestiontext)
            );
        }

        // Core-Eigenheit (lib/questionlib.php::question_move_questions_to_category()):
        // die Funktion verschiebt pro *Version* in $questionids, nicht pro
        // Bank-Eintrag - bei mehreren mitgegebenen Versionsids derselben
        // Frage kollidiert der zweite Durchlauf mit dem bereits verschobenen
        // Eintrag der ersten Iteration und haengt der idnumber faelschlich
        // einen Suffix an, obwohl gar keine echte Kollision vorliegt. Ein
        // Bank-Eintrag ist EIN Umzug (die entryid traegt questioncategoryid,
        // nicht die einzelne Version) - genau eine Versionsid genuegt, alle
        // Versionen haengen an derselben entryid und ziehen mit.
        //
        // Core-Eigenheit #2: move_questions::execute() ist als "?string"
        // deklariert, faellt bei leerem $returnurlstring aber ohne
        // "return null;" durch - ein TypeError ("none returned"). Ein
        // Platzhalter-Pfad umgeht das, wie im lokalen Vorbild
        // local_coursepilot\external\move_question - die zurueckgegebene URL
        // wird hier ohnehin verworfen.
        \core_question\external\move_questions::execute(
            $targetcontext->id,
            $targetcategory->id,
            (string) $questionids[0],
            '/question/bank/managecategories/category.php'
        );

        $entry = $DB->get_record('question_bank_entries', ['id' => $entry->id], '*', MUST_EXIST);
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id], 'version ASC');
        $newidnumber = (string) ($entry->idnumber ?? '');
        $idnumberdisambiguiert = $collision !== null && $newidnumber !== $idnumber;

        $meldung = 'Frage in Zielkategorie verschoben.';
        if ($idnumberdisambiguiert) {
            $meldung .= ' Die idnumber "' . $idnumber . '" war in der Zielkategorie bereits vergeben und wurde '
                . 'auf "' . $newidnumber . '" umbenannt.';
        }

        return array_merge(
            [
                'status' => 'verschoben',
                'questionbankentryid' => (int) $entry->id,
                'versionids' => array_values(array_map(static fn($item): int => (int) $item->questionid, $versions)),
                'idnumber_disambiguiert' => $idnumberdisambiguiert,
                'meldung' => $meldung,
            ],
            question_suspect_gate::empty_result()
        );
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge(
            [
                'status' => new external_value(PARAM_ALPHA, '"verschoben" oder "verdachtsfall"'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Unveraenderte Identitaet des question_bank_entries'),
                'versionids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'questionid einer erhaltenen Version'),
                    'Alle Versionen der Frage in Versionsreihenfolge (leer bei "verdachtsfall")'
                ),
                'idnumber_disambiguiert' => new external_value(
                    PARAM_BOOL,
                    'true, wenn beim bestaetigten Umzug eine idnumber-Kollision durch den Core-Suffix-Mechanismus aufgeloest wurde'
                ),
                'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Meldung'),
            ],
            question_suspect_gate::response_fields()
        ));
    }
}
