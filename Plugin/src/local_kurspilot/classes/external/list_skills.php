<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\external;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\ausstand_notice;
use local_kurspilot\ortswahl_lib;
use local_kurspilot\skill_corpus;
use local_kurspilot\webdav\webdav_setup_steps;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Katalog des Skill-Korpus (Spec 0020 §4, Issue #450): Name, Auslöser,
 * Art (adapter/referenz) und Umfang je Eintrag - kein Inhalt, das liefert
 * {@see get_skill}. Nicht kursgebunden: geprüft wird lediglich
 * 'local/kurspilot:use' im Systemkontext, keine Kurs-Zustimmung.
 *
 * Meldet zusaetzlich die offenen Eintraege der Ausstandsnotiz (`ausstaende`,
 * Issue #492, ADR 0023 Punkt 4: "Der Server meldet, nicht die KI") -
 * gebuendelt je Zieldatei, aeltester Eintrag zuerst, ohne Netzzugriff.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_skills extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        self::validate_context(context_system::instance());
        require_capability('local/kurspilot:use', context_system::instance());

        $skills = array_map(static fn (array $entry): array => [
            'name' => $entry['name'],
            'ausloeser' => $entry['ausloeser'],
            'art' => $entry['art'],
            'umfang' => $entry['umfang'],
        ], skill_corpus::list());

        global $USER;
        $hinweise = [];
        if (ortswahl_lib::open_with_access((int) $USER->id)) {
            // Ortswahl offen und Freischaltung vorhanden (Issue #494
            // Akzeptanzkriterium) - ohne Netzzugriff, kein Fakt ohne
            // Freischaltung.
            $hinweise[] = [
                'text' => get_string('listskillsortswahlhint', 'local_kurspilot', webdav_setup_steps::ORTSWAHL_PAGE),
                'link' => (new \moodle_url(webdav_setup_steps::ORTSWAHL_PAGE))->out(false),
            ];
        }
        if (\local_kurspilot\altbestand::open()) {
            // Altbestand offen (Issue #498, Spec #486 §9/§10): ohne
            // Netzzugriff, ohne Zaehlung - nur der Fakt "es gibt einen
            // vorherigen Ort".
            $hinweise[] = [
                'text' => get_string('listskillsaltbestandhint', 'local_kurspilot', webdav_setup_steps::ORTSWAHL_PAGE),
                'link' => (new \moodle_url(webdav_setup_steps::ORTSWAHL_PAGE))->out(false),
            ];
        }

        return ['skills' => $skills, 'ausstaende' => ausstand_notice::list_grouped(), 'hinweise' => $hinweise];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'skills' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Skill-Bezeichner, fuer get_skill(name)'),
                    'ausloeser' => new external_value(PARAM_TEXT, 'Auslöser/Beschreibung, Deutsch'),
                    'art' => new external_value(PARAM_TEXT, '"adapter" oder "referenz"'),
                    'umfang' => new external_value(PARAM_INT, 'Umfang des Inhalts in Zeichen'),
                ])
            ),
            'ausstaende' => new external_multiple_structure(
                new external_single_structure([
                    'pfad' => new external_value(PARAM_TEXT, 'Relativer Zieldateipfad im Kontextbereich'),
                    'eintraege' => new external_multiple_structure(
                        new external_single_structure([
                            'kennung' => new external_value(PARAM_ALPHANUMEXT, 'Kennung, fuer ausstand=<Kennung> oder kurspilot_dismiss_ausstand'),
                            'zeitpunkt' => new external_value(PARAM_INT, 'Unix-Zeitstempel des gescheiterten Vorgangs'),
                            'vorgang' => new external_value(PARAM_TEXT, '"anlegen", "überschreiben" oder "anhängen"'),
                            'fehlerklasse' => new external_value(PARAM_TEXT, 'Benannte Fehlerklasse, nie ein Freitext'),
                        ])
                    ),
                ]),
                'Offene Ausstaende, gebuendelt je Zieldatei, die aeltesten zuerst (ADR 0023)',
                VALUE_DEFAULT,
                []
            ),
            'hinweise' => new external_multiple_structure(
                new external_single_structure([
                    'text' => new external_value(PARAM_TEXT, 'Hinweistext, Deutsch'),
                    'link' => new external_value(PARAM_URL, 'Zielseite des Hinweises'),
                ]),
                'Ohne Netzzugriff ermittelte Hinweise, z.B. offene Ortswahl bei vorhandener Freischaltung (Issue #494)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
