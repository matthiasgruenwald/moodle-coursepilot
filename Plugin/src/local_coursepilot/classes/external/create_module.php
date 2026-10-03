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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\module_catalog;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\write_target;
use local_coursepilot\material_files;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Der zweite Schreibvorgang (Spec 0015 §3.4, Ticket #389, Phase 3): legt eine
 * neue Aktivitaet ueber den nativen Formularweg an (can_add_moduleinfo() fuer
 * die native Berechtigungspruefung plus Modul-/Abschnittsermittlung,
 * add_moduleinfo() zum Schreiben) - keine Handaenderung, die ueberleben
 * muesste, deshalb kein Vorher/Nachher-Diff wie bei {@see update_module_settings}.
 *
 * Anders als beim Patch (Ticket #388, "Vollersatz verworfen") gilt hier die
 * entgegengesetzte Regel: fehlende Felder werden mit dem katalogisierten
 * FORMULAR-Default aufgefuellt (nicht dem DB-Spalten-Default, die weichen bei
 * mehreren Feldern ab, siehe {@see \local_coursepilot\catalog\choice} Feld
 * "includeinactive") - beim Anlegen gibt es keine Handaenderung, die ein
 * stiller Reset zerstoeren koennte. Ein Pflichtfeld ganz ohne Formular-Default
 * (Kategorie "required" ohne "default" im Katalog) scheitert stattdessen mit
 * einer Meldung, die das Feld nennt (Spec 0015 §3.4).
 *
 * "resource" verlangt seit Spec 0018 (§4/§7, Issue #434) das Pflichtfeld
 * "files" (Liste von Materialordner-Pfaden) im selben Aufruf - ohne
 * Hauptdatei entsteht eine kaputte Aktivitaetsseite
 * (mod/resource/view.php: resource_print_filenotfound()), die Pruefung
 * laeuft deshalb VOR add_moduleinfo() ueber den normalen
 * Pflichtfeld-Mechanismus ({@see \local_coursepilot\catalog\write_target::create()}).
 * "folder" bleibt anlegbar - ein leerer Ordner ist gueltig, "files" ist dort
 * optional und akzeptiert mehrere Pfade samt Zielunterordner (Spec 0018 §4.2).
 * Normalisierung, Pruefung, Dateiaufloesung und add_moduleinfo() laufen als
 * eine Folge in {@see \local_coursepilot\catalog\write_target::create_activity()}
 * (#647) - dieser Endpunkt ist nur noch der External-Adapter.
 *
 * Feldbuendel (Spec 0015 §2.4) sind bewusst KEIN eigener Endpunkt-Parameter:
 * "Sie überleben als benannte Feldbündel im Katalog, nicht als
 * Endpunkt-Parameter" - describe_module_fields liefert das Buendel, die KI
 * mischt es selbst in fields_json (ein Buendelwert gilt nur fuer Felder, die
 * fields_json nicht schon selbst nennt). Dieser Endpunkt sieht deshalb nur
 * das bereits gemischte Ergebnis.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_module extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'modname' => new external_value(PARAM_PLUGIN, 'Activity type, e.g. page, label, url, choice, forum, assign'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => value - missing fields are filled with the catalog form default. '
                    . 'A field bundle (describe_module_fields) is mixed in here BEFORE the call (Spec 0015 §2.4: '
                    . 'bundles are not an endpoint parameter) - a bundle value only applies to fields this object '
                    . 'does not already name itself.'
            ),
            'location' => material_files::location_parameter(),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param string $fieldsjson
     * @param string $location
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum,
        string $modname,
        string $fieldsjson,
        string $location = material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'modname' => $modname,
            'fields_json' => $fieldsjson,
            'location' => $location,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        self::authorise($params['courseid']);

        $modname = $params['modname'];
        $catalogclass = self::catalog_for($modname);
        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // sperrt nur DIESE Aktivitaetsart, wenn ein erkannter Moodle-Versionswechsel
        // eine Katalogabweichung ergeben hat. Lesen bleibt unberuehrt.
        write_gate::assert_writable($modname);

        $merged = json_decode($params['fields_json'], true);
        if (!is_array($merged) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        // Rules, file checks and the native sequence live in the catalog core (#646, #647).
        ['cmid' => $cmid, 'changes' => $merged] = write_target::create_activity(
            $catalogclass,
            get_course($params['courseid']),
            $params['sectionnum'],
            $merged,
            $params['location'],
            $params[learner_locks::PARAMETER]
        );

        $after = self::read_settings($cmid);
        [$createdfields, $sideeffects] = self::report_and_side_effects($modname, $merged, $after);

        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'message' => self::build_message($modname, $createdfields, $sideeffects),
            'created_fields' => $createdfields,
            'side_effects' => $sideeffects,
        ];
    }

    /**
     * Prueft Kontext und Capabilities fuer den Kurs (Issue #523: aus
     * execute() ausgelagert, um die Funktion unter der 50-Zeilen-Grenze zu
     * halten).
     *
     * @param int $courseid
     * @return \context_course
     */
    private static function authorise(int $courseid): \context_course {
        $coursecontext = context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Native Berechtigungspruefung vorgezogen (Spec 0015 §3.4, wie
        // {@see update_module_settings}): can_add_moduleinfo() prueft dieselbe
        // Capability spaeter ohnehin erneut - der Aufruf hier ist billig und
        // stellt sicher, dass eine fehlende Bearbeiten-Berechtigung nicht
        // hinter einer Feldvalidierungsmeldung versteckt bleibt.
        require_capability('moodle/course:manageactivities', $coursecontext);

        return $coursecontext;
    }

    /**
     * Die Katalogklasse fuer $modname, sofern der Schreibweg dieser Endpunkt
     * ist (Spec 0015 §3.1: manche Aktivitaetsarten haben ein eigenes
     * Einzelwerkzeug, z.B. quiz -> update_quiz_settings) - identische Pruefung
     * wie {@see update_module_settings::catalog_for()}.
     *
     * @param string $modname
     * @return class-string<module_catalog>
     * @throws moodle_exception unknownmodname|writevehicleblocked
     */
    private static function catalog_for(string $modname): string {
        $catalogclass = registry::require_catalogued($modname);
        $writeroute = $catalogclass::write_route();
        if ($writeroute !== null) {
            throw new moodle_exception(
                'writevehicleblocked',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'write_route' => $writeroute]
            );
        }
        return $catalogclass;
    }

    /**
     * Ist-Stand nach dem Anlegen als assoziatives Array - dieselbe
     * Zusammenstellung wie {@see get_module_settings}, wiederverwendet statt
     * dupliziert (wie {@see update_module_settings::read_settings()}).
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        $settings = json_decode($result['settings_json'], true);
        $fieldname = registry::for($result['modname'])::write_options()['intro_image_field'] ?? null;
        if ($fieldname !== null) {
            $files = get_file_storage()->get_area_files(
                \context_module::instance($cmid)->id, 'mod_' . $result['modname'], 'intro', 0, 'filename', false);
            $settings[$fieldname] = array_values(array_map(
                static fn(\stored_file $file): string => $file->get_filename(), $files));
        }
        return $settings;
    }

    /**
     * Die tatsaechlich vom Patch/Buendel gesetzten Felder mit ihrem
     * persistierten Wert (nicht dem rohen Eingabewert - Moodle normalisiert
     * manche Felder beim Schreiben, z.B. url_fix_submitted_url()), plus
     * ausgeloeste Nebenwirkungen. Katalog-Defaults, die die Lehrkraft nicht
     * genannt hat, tauchen hier bewusst nicht auf - sie sind stille
     * Voreinstellung, keine "Aenderung".
     *
     * @param string $modname
     * @param array $merged
     * @param array $after
     * @return array{0: array, 1: string[]}
     */
    private static function report_and_side_effects(string $modname, array $merged, array $after): array {
        $createdfields = [];
        $sideeffects = [];
        $triggers = registry::for($modname)::write_options()['side_effect_triggers'] ?? [];

        foreach (array_keys($merged) as $fieldname) {
            $value = $after[$fieldname] ?? null;
            $createdfields[] = [
                'field' => $fieldname,
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            if (isset($triggers[$fieldname][$value])) {
                $sideeffects[] = $triggers[$fieldname][$value];
            }
        }

        return [$createdfields, $sideeffects];
    }

    /**
     * Die Lehrkraft-deutsche Anlegemeldung (Spec 0015 §3.4: "die Antwort ist
     * die Aenderungsmeldung").
     *
     * @param string $modname
     * @param array $createdfields
     * @param string[] $sideeffects
     * @return string
     */
    private static function build_message(string $modname, array $createdfields, array $sideeffects): string {
        $parts = [];
        foreach ($createdfields as $field) {
            $parts[] = '"' . $field['field'] . '" = ' . $field['value_json'];
        }
        $message = 'Aktivität "' . $modname . '" angelegt';
        $message .= $parts ? (': ' . implode(', ', $parts) . '.') : '.';

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the newly created activity'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German creation message'),
            'created_fields' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'value_json' => new external_value(PARAM_RAW, 'JSON-encoded, actually persisted value'),
                ]),
                'One entry per field set by the patch/bundle - silent catalog defaults are deliberately absent here'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing German side-effect note'),
                'Triggered side effects from catalog category 5, empty when none were triggered'
            ),
        ]);
    }
}
