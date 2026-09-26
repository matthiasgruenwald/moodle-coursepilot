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

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\skill_corpus;

defined('MOODLE_INTERNAL') || die();

/**
 * Die Lieferung eines einzelnen Skill-Korpus-Eintrags (Spec 0020 §4, Issue
 * #450): Inhalt, Namen der referenzierten Teile, Korpus-Stand.
 *
 * $name ist ein Bezeichner, kein Pfad: {@see skill_corpus::get()} prueft
 * ausschliesslich gegen die aus dem Verzeichnis gescannten Namen - ein
 * unbekannter oder pfadartiger Name (`../`, fuehrender `/`, Backslash,
 * kodierte Variante) wird gleichermassen abgewiesen, die Meldung nennt die
 * gueltigen Namen. Absichtlich PARAM_TEXT statt eines alphanumerischen
 * Filters: der Name kommt unveraendert bei der Pruefung an, die Ablehnung
 * ist eine Frage der Verzeichnisliste, nicht der Zeichenbereinigung.
 *
 * Unmittelbar englisch deklariert (#571, Spec 0025 §A): "referenced_parts"
 * statt "referenzierte_teile", "corpus_version" statt "korpus_stand" - der
 * zugrundeliegende Skill-Korpus ({@see \local_coursepilot\skill_corpus})
 * bleibt intern deutsch, die Uebersetzung geschieht hier.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class get_skill extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT, 'Skill identifier from coursepilot_list_skills, not a path'),
        ]);
    }

    /**
     * @param string $name
     * @return array
     * @throws \moodle_exception unknownskillname, wenn $name nicht im Korpus-Verzeichnis steht.
     */
    public static function execute(string $name): array {
        $params = self::validate_parameters(self::execute_parameters(), ['name' => $name]);
        self::validate_context(context_system::instance());
        require_capability('local/coursepilot:use', context_system::instance());

        $entry = skill_corpus::get($params['name']);
        return [
            'content' => $entry['content'],
            'referenced_parts' => $entry['referenzierte_teile'],
            'corpus_version' => $entry['korpus_stand'],
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'content' => new external_value(PARAM_RAW, 'Markdown content'),
            'referenced_parts' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Name of a corpus part referenced in the content')
            ),
            'corpus_version' => new external_value(PARAM_TEXT, 'Plugin release and version of the delivered corpus'),
        ]);
    }
}
