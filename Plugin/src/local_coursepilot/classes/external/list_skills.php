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
use local_coursepilot\pending_write_notice;
use local_coursepilot\location_selection;
use local_coursepilot\skill_corpus;
use local_coursepilot\webdav\webdav_setup_steps;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Katalog des Skill-Korpus (Spec 0020 §4, Issue #450): Name, Auslöser,
 * Art (adapter/referenz) und Umfang je Eintrag - kein Inhalt, das liefert
 * {@see get_skill}. Nicht kursgebunden: geprüft wird lediglich
 * 'local/coursepilot:use' im Systemkontext, keine Kurs-Zustimmung.
 *
 * Meldet zusaetzlich die offenen Eintraege der Ausstandsnotiz (`pending_entries`,
 * Issue #492, ADR 0023 Punkt 4: "Der Server meldet, nicht die KI") -
 * gebuendelt je Zieldatei, aeltester Eintrag zuerst, ohne Netzzugriff.
 *
 * Unmittelbar englisch deklariert (#571, Spec 0025 §A): "trigger"/"kind"/
 * "length" je Skill-Eintrag, "pending_entries" statt "ausstaende" (darin
 * "path"/"entries"/"identifier"/"timestamp"/"operation"/"error_class"/
 * "course_id") und "notices" statt "hinweise" - der zugrundeliegende
 * Skill-Korpus ({@see \local_coursepilot\skill_corpus}) und die
 * Ausstandsnotiz ({@see \local_coursepilot\pending_write_notice}) bleiben
 * als interne Speicherformate unveraendert deutsch, die Uebersetzung
 * geschieht hier an der Werkzeuggrenze.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
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
        require_capability('local/coursepilot:use', context_system::instance());

        $skills = array_map(static fn (array $entry): array => [
            'name' => $entry['name'],
            'trigger' => $entry['ausloeser'],
            'kind' => $entry['art'],
            'length' => $entry['umfang'],
        ], skill_corpus::list());

        global $USER;
        $locationselectionlink = (new \moodle_url(webdav_setup_steps::ORTSWAHL_PAGE))->out(false);
        $notices = [];
        try {
            $document = \local_coursepilot\storage_anchor::read_raw_pointer();
            if ($document !== null) {
                // Vollstaendigkeitspruefung ueber die bestehende Aufloesung
                // (Issue #519, Spec #486 §10: "unlesbar oder unvollstaendig") -
                // wirft pointerincomplete/pointerunreachable/
                // materialbestandimkontext bei einem unvollstaendigen Pointer,
                // denselben Fall wie "unlesbar", ohne die Pruefung hier zu
                // duplizieren. Das aufgeloeste Ziel selbst wird nicht
                // gebraucht.
                \local_coursepilot\context_pointer::resolve_target($document, 'kontextbereich');
            }
            if (location_selection::open_with_access((int) $USER->id)) {
                // Ortswahl offen und Freischaltung vorhanden (Issue #494
                // Akzeptanzkriterium) - ohne Netzzugriff, kein Fakt ohne
                // Freischaltung.
                $notices[] = self::notice('listskillsortswahlhint', $locationselectionlink);
            }
            if (\local_coursepilot\previous_location::open()) {
                // Altbestand offen (Issue #498, Spec #486 §9/§10): ohne
                // Netzzugriff, ohne Zaehlung - nur der Fakt "es gibt einen
                // vorherigen Ort".
                $notices[] = self::notice('listskillsaltbestandhint', $locationselectionlink);
            }
        } catch (\moodle_exception $e) {
            // Kaputter Kontextpointer (unlesbar oder unvollstaendig, Issue
            // #519, Spec #486 §10) darf den Handshake nicht scheitern lassen -
            // der Skillkatalog kommt trotzdem, dazu ein benannter Hinweis statt
            // der beiden obigen Fakten, weiterhin ohne Netzzugriff.
            $notices = [self::notice('listskillspointerbrokenhint', $locationselectionlink)];
        }

        $pending = array_map(static fn (array $group): array => [
            'path' => $group['pfad'],
            'entries' => array_map(static fn (array $entry): array => [
                'identifier' => $entry['kennung'],
                'timestamp' => $entry['zeitpunkt'],
                'operation' => $entry['vorgang'],
                'error_class' => $entry['fehlerklasse'],
                'course_id' => $entry['kursid'],
            ], $group['eintraege']),
        ], pending_write_notice::list_grouped());

        return ['skills' => $skills, 'pending_entries' => $pending, 'notices' => $notices];
    }

    /**
     * Baut einen Eintrag fuer 'notices' (Code-Review Issue #519): Text aus
     * dem Sprachpaket, Link stets die Ortswahlseite - gemeinsam fuer alle
     * drei Fakten dieser Methode.
     *
     * @param string $stringkey Schluessel im Sprachpaket local_coursepilot.
     * @param string $link Bereits aufgeloester Link zur Ortswahlseite.
     * @return array{text: string, link: string}
     */
    private static function notice(string $stringkey, string $link): array {
        return [
            'text' => get_string($stringkey, 'local_coursepilot', webdav_setup_steps::ORTSWAHL_PAGE),
            'link' => $link,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'skills' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Skill identifier, for get_skill(name)'),
                    'trigger' => new external_value(PARAM_TEXT, 'Trigger/description, German'),
                    'kind' => new external_value(PARAM_TEXT, '"adapter" or "referenz" (reference)'),
                    'length' => new external_value(PARAM_INT, 'Length of the content in characters'),
                ])
            ),
            'pending_entries' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_TEXT, 'Relative target file path in the context area'),
                    'entries' => new external_multiple_structure(
                        new external_single_structure([
                            'identifier' => new external_value(PARAM_ALPHANUMEXT, 'Identifier, for pending_entry=<identifier> or coursepilot_dismiss_ausstand'),
                            'timestamp' => new external_value(PARAM_INT, 'Unix timestamp of the failed operation'),
                            'operation' => new external_value(
                                PARAM_TEXT,
                                '"anlegen" (create), "überschreiben" (overwrite) or "anhängen" (append)'
                            ),
                            'error_class' => new external_value(PARAM_TEXT, 'Named error class, never free text'),
                            'course_id' => new external_value(PARAM_INT, 'Course ID, 0 if the call was not tied to a course'),
                        ])
                    ),
                ]),
                'Open pending entries, bundled per target file, oldest first (ADR 0023)',
                VALUE_DEFAULT,
                []
            ),
            'notices' => new external_multiple_structure(
                new external_single_structure([
                    'text' => new external_value(PARAM_TEXT, 'Notice text, German'),
                    'link' => new external_value(PARAM_URL, 'Target page of the notice'),
                ]),
                'Notices determined without network access, e.g. open location selection with existing enablement (Issue #494)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
