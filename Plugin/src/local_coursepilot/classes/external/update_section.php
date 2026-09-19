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

use coding_exception;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Schreibkern 13 (Spec 0015 Phase 3, Ticket #391): patcht Name, Zusammenfassung
 * und Sichtbarkeit eines bestehenden Abschnitts - nur die uebergebenen Felder
 * aendern sich (Patch, wie {@see update_module_settings}).
 *
 * Schreibt ueber course_update_section() (course/lib.php), das intern
 * {@see \core_courseformat\local\sectionactions::update()} aufruft. Genau
 * diese Methode loest bei einer Sichtbarkeitsaenderung bereits nativ
 * transfer_visibility_to_cms() aus: ein unsichtbar geschalteter Abschnitt
 * macht seine Aktivitaeten unsichtbar, unabhaengig von deren eigenem Wert -
 * Coursepilot erzeugt diesen Nebeneffekt nicht selbst, spricht ihn aber in der
 * Antwort aus (Ticket #391 Abnahmekriterium).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_section extends external_api {

    /** @var string[] Katalog der ueber diesen Endpunkt patchbaren Felder. */
    private const SETTABLE_FIELDS = ['name', 'summary', 'visible'];

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Kurs-ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Abschnittsnummer (0-basiert)'),
            'felder_json' => new external_value(
                PARAM_RAW,
                'JSON-Objekt mit "name" (string), "summary" (string) und/oder "visible" (0|1) - nur die '
                    . 'genannten Felder aendern sich'
            ),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $felderjson
     * @return array
     */
    public static function execute(int $courseid, int $sectionnum, string $felderjson): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'felder_json' => $felderjson,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung: dieselbe Capability, die
        // course/editsection.php beim Speichern verlangt.
        require_capability('moodle/course:update', $context);

        $patch = json_decode($params['felder_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        require_once($CFG->dirroot . '/course/lib.php');
        $course = get_course($params['courseid']);
        $sections = get_fast_modinfo($course)->get_section_info_all();
        if (!array_key_exists($params['sectionnum'], $sections)) {
            throw new moodle_exception('sectionnotfound', 'local_coursepilot', '', ['sectionnum' => $params['sectionnum']]);
        }
        $sectioninfo = $sections[$params['sectionnum']];

        $fields = self::validate_patch($patch);
        $before = [
            'name' => (string) ($sectioninfo->name ?? ''),
            'summary' => (string) ($sectioninfo->summary ?? ''),
            'visible' => (int) $sectioninfo->visible,
        ];

        if ($fields) {
            course_update_section($course, $sectioninfo, $fields);
        }

        $after = get_fast_modinfo($course)->get_section_info_all()[$params['sectionnum']];
        $changes = self::diff($patch, $before, $after);
        $hidesactivities = array_key_exists('visible', $patch) && (int) $patch['visible'] === 0 && $before['visible'] !== 0;

        return [
            'id' => (int) $after->id,
            'sectionnum' => (int) $params['sectionnum'],
            'meldung' => self::build_message($changes, $hidesactivities),
            'aenderungen' => $changes,
        ];
    }

    /**
     * Alles-oder-nichts-Pruefung VOR dem Schreiben: unbekanntes Feld,
     * unerlaubter Wert fuer "visible".
     *
     * @param array $patch
     * @return array Moodle-Feldnamen => Wert, direkt fuer course_update_section().
     * @throws moodle_exception unknownfield|invalidfieldvalue
     */
    private static function validate_patch(array $patch): array {
        $fields = [];
        foreach ($patch as $fieldname => $value) {
            if (!is_string($fieldname)) {
                throw new coding_exception('felder_json muss ein JSON-Objekt sein, kein Array.');
            }
            if (!in_array($fieldname, self::SETTABLE_FIELDS, true)) {
                throw new moodle_exception(
                    'sectionunknownfield',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'felder' => implode(', ', self::SETTABLE_FIELDS)]
                );
            }
            if ($fieldname === 'visible' && !in_array($value, [0, 1], true)) {
                throw new moodle_exception('sectioninvalidvisible', 'local_coursepilot', '', ['value' => json_encode($value)]);
            }
            $fields[$fieldname] = $value;
        }
        if (array_key_exists('summary', $fields) && !array_key_exists('summaryformat', $fields)) {
            $fields['summaryformat'] = FORMAT_HTML;
        }
        return $fields;
    }

    /**
     * @param array $patch
     * @param array $before
     * @param \section_info $after
     * @return array
     */
    private static function diff(array $patch, array $before, \section_info $after): array {
        $changes = [];
        foreach (array_keys($patch) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $fieldname === 'visible' ? (int) $after->visible : (string) ($after->{$fieldname} ?? '');
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'feld' => $fieldname,
                    'von_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'auf_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return $changes;
    }

    /**
     * @param array $changes
     * @param bool $hidesactivities
     * @return string
     */
    private static function build_message(array $changes, bool $hidesactivities): string {
        if (!$changes) {
            $message = 'Keine Änderung: der Patch stimmte bereits mit dem aktuellen Stand überein.';
        } else {
            $parts = [];
            foreach ($changes as $change) {
                $parts[] = '"' . $change['feld'] . '" von ' . $change['von_json'] . ' auf ' . $change['auf_json'];
            }
            $message = 'Geändert: ' . implode(', ', $parts) . '.';
        }

        if ($hidesactivities) {
            $message .= ' Alle Aktivitäten in diesem Abschnitt sind dadurch ebenfalls unsichtbar, '
                . 'unabhängig von ihrer eigenen Sichtbarkeitseinstellung.';
        }

        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Abschnitts-DB-ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Abschnittsnummer (0-basiert)'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Aenderungsmeldung'),
            'aenderungen' => new external_multiple_structure(
                new external_single_structure([
                    'feld' => new external_value(PARAM_TEXT, 'Feldname'),
                    'von_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert vor dem Schreiben'),
                    'auf_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert nach dem Schreiben'),
                ]),
                'Je tatsaechlich geaendertem Feld ein Eintrag'
            ),
        ]);
    }
}
