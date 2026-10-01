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

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

/**
 * Das gemeinsame Verdachtsfall-Gate-Antwortformat (ADR 0015, Spec 0017 §7.1,
 * Ticket #414) - "ein Gate, das je Endpunkt anders aussieht, sind vier
 * Gates". Ab diesem Ticket traegt {@see \local_coursepilot\external\move_question}
 * dieses Format; import_questions_xml, create_mc_question und die
 * Klon-Nachbereitung sollen es spaeter uebernehmen statt eigene Formen zu
 * bauen.
 *
 * Ein Verdachtsfall schreibt nichts - die Antwort nennt die mitgebrachte
 * idnumber, die Zielkategorie, nahe Kandidaten und, wo vorhanden, alten und
 * neuen Fragetext. Erst der erneute, ausdruecklich bestaetigte Aufruf
 * schreibt (Parameter "bestaetigt" je Endpunkt, wie ueberall im Bestand
 * z.B. set_completion, restore_activity_version).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class question_suspect_gate {

    /**
     * Felddefinitionen des Gates - von jedem Endpunkt in die eigene
     * execute_returns()-Struktur zu mischen (array_merge). Immer vorhanden
     * (mit leeren Standardwerten ausserhalb eines Verdachtsfalls), damit
     * jeder Endpunkt dieselbe feste Antwortform hat, unabhaengig davon, ob
     * gerade ein Verdachtsfall vorliegt.
     *
     * @return array<string, \core_external\external_description>
     */
    public static function response_fields(): array {
        return [
            'idnumber' => new external_value(
                PARAM_TEXT,
                'Mitgebrachte idnumber des Verdachtsfalls (leer ausserhalb eines Verdachtsfalls)',
                VALUE_DEFAULT,
                ''
            ),
            'categoryid' => new external_value(
                PARAM_INT,
                'Zielkategorie des Verdachtsfalls (0 ausserhalb eines Verdachtsfalls)',
                VALUE_DEFAULT,
                0
            ),
            'candidates' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT, 'questionid der aktuellsten Version des Kandidaten'),
                    'name' => new external_value(PARAM_TEXT, 'Fragename des Kandidaten'),
                    'idnumber' => new external_value(PARAM_TEXT, 'idnumber des Kandidaten'),
                ]),
                'Nahe Kandidaten in der Zielkategorie (leer ausserhalb eines Verdachtsfalls)',
                VALUE_DEFAULT,
                []
            ),
            'questiontext_old' => new external_value(
                PARAM_RAW,
                'Fragetext des bestehenden Kandidaten, wo vorhanden',
                VALUE_DEFAULT,
                ''
            ),
            'questiontext_new' => new external_value(
                PARAM_RAW,
                'Fragetext der zu schreibenden/verschobenen Frage, wo vorhanden',
                VALUE_DEFAULT,
                ''
            ),
        ];
    }

    /**
     * Leere Gate-Felder fuer den Nicht-Verdachtsfall - denselben Schluesseln
     * wie {@see response_fields()}, damit jeder Endpunkt eine feste
     * Antwortform ausliefert.
     *
     * @return array{idnumber: string, categoryid: int, candidates: array, questiontext_old: string, questiontext_new: string}
     */
    public static function empty_result(): array {
        return [
            'idnumber' => '',
            'categoryid' => 0,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => '',
        ];
    }

    /**
     * Sucht einen bestehenden Fragenbank-Eintrag mit derselben idnumber in
     * der Zielkategorie - ausser dem eigenen Eintrag (sonst waere jeder
     * Umzug einer Frage mit idnumber in ihre eigene Kategorie ein falscher
     * Treffer). Die (questioncategoryid, idnumber)-Kombination ist in Moodle
     * eindeutig indiziert - es kann also hoechstens einen Kandidaten geben.
     *
     * @param int $categoryid
     * @param string $idnumber
     * @param int $excludeentryid questionbankentryid, der nie als Kollision zaehlt
     * @return \stdClass|null {entryid, questionid, name, idnumber, questiontext} oder null ohne Kollision
     */
    public static function find_idnumber_collision(int $categoryid, string $idnumber, int $excludeentryid): ?\stdClass {
        global $DB;

        if ($idnumber === '') {
            return null;
        }

        $sql = 'SELECT qbe.id AS entryid, qbe.idnumber AS idnumber, q.id AS questionid, q.name AS name,
                       q.questiontext AS questiontext
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND qbe.idnumber = :idnumber
                   AND qbe.id <> :excludeentryid
              ORDER BY qv.version DESC';
        $rows = $DB->get_records_sql($sql, [
            'catid' => $categoryid,
            'idnumber' => $idnumber,
            'excludeentryid' => $excludeentryid,
        ], 0, 1);

        return $rows ? reset($rows) : null;
    }

    /**
     * Baut die Gate-Antwort (Verdachtsfall) aus einem Kollisionstreffer.
     *
     * @param \stdClass $collision Ergebnis von {@see find_idnumber_collision()}
     * @param int $categoryid Zielkategorie
     * @param string $newquestiontext Fragetext der zu schreibenden/verschobenen Frage
     * @return array{idnumber: string, categoryid: int, candidates: array, questiontext_old: string, questiontext_new: string}
     */
    public static function response(\stdClass $collision, int $categoryid, string $newquestiontext): array {
        return [
            'idnumber' => (string) $collision->idnumber,
            'categoryid' => $categoryid,
            'candidates' => [[
                'questionid' => (int) $collision->questionid,
                'name' => (string) $collision->name,
                'idnumber' => (string) $collision->idnumber,
            ]],
            'questiontext_old' => (string) $collision->questiontext,
            'questiontext_new' => $newquestiontext,
        ];
    }

    /**
     * Nahe Kandidaten fuer einen namensbasierten Verdachtsfall: gleichnamige
     * Eintraege in der Zielkategorie. Gemeinsam genutzt von
     * {@see \local_coursepilot\external\import_questions_xml} (Kandidaten zu
     * einer mitgebrachten idnumber ohne Treffer) und
     * {@see \local_coursepilot\external\create_mc_question} (Kandidaten zu
     * einem gleichnamigen Eintrag, da eine Neuanlage nie eine idnumber
     * mitbringt, gegen die gematcht werden koennte).
     *
     * @param int $categoryid
     * @param string $name
     * @return array
     */
    public static function find_name_candidates(int $categoryid, string $name): array {
        global $DB;

        $sql = 'SELECT DISTINCT qbe.id AS entryid, qbe.idnumber AS idnumber
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q           ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.name = :name';
        $rows = $DB->get_records_sql($sql, ['catid' => $categoryid, 'name' => $name]);

        $candidates = [];
        foreach ($rows as $row) {
            $latest = self::latest_version_question((int) $row->entryid);
            $candidates[] = [
                'questionid' => (int) $latest->id,
                'name' => (string) $latest->name,
                'idnumber' => (string) ($row->idnumber ?? ''),
            ];
        }
        return $candidates;
    }

    /**
     * Laedt die question-Zeile der neuesten Version eines Fragenbank-Eintrags.
     *
     * @param int $entryid
     * @return \stdClass
     */
    public static function latest_version_question(int $entryid): \stdClass {
        global $DB;
        $version = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$entryid],
            IGNORE_MULTIPLE
        );
        return $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);
    }
}
