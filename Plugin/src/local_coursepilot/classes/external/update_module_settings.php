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

use context_module;
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
 * Der erste Schreibvorgang (Spec 0015 §3.3, Ticket #388, Phase 3): patcht
 * einzelne Einstellungen einer bestehenden Aktivitaet ueber den nativen
 * Formularweg (get_moduleinfo_data() lesen, ueberlagern, update_moduleinfo()
 * schreiben) - kein Konfliktschutz, kein expected_version, eine parallele
 * Handaenderung an einem ANDEREN Feld ueberlebt (Spec 0015 §3.3).
 *
 * Alles oder nichts: jede Validierung (unbekanntes Feld, gesperrtes Feld,
 * unerlaubter Wert, Kombinationsregel) laeuft VOR dem einzigen Schreibaufruf
 * - kein Teilergebnis moeglich.
 *
 * Keine eigene Coursepilot-Schreib-Capability: get_moduleinfo_data() ruft
 * intern can_update_moduleinfo(), das 'moodle/course:manageactivities' im
 * Modulkontext verlangt - das ist die native Pruefung, die Spec 0015 §3.3
 * verlangt. 'local/coursepilot:use' bleibt die Basis-Zugriffspruefung wie bei
 * jedem anderen Werkzeug.
 *
 * Direkte DB-Schreibung wird bewusst nicht genutzt (ADR 0016): sie loest
 * kein course_module_updated aus, der Aenderungsverlauf (#385-387) bliebe
 * dafuer blind.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class update_module_settings extends external_api {

    /**
     * The material reference pseudofields (write_options() "material_reference_fields")
     * of an activity type, for {@see \local_coursepilot\external\restore_activity_version},
     * which needs the same component/filearea set to bring replaced files back
     * from the trash ({@see \local_coursepilot\activity_file_trash}, Spec 0018
     * §9.1, issue #432).
     *
     * @param string $modname
     * @return array<string, array{component: string, filearea: string}>
     */
    public static function material_reference_specs(string $modname): array {
        $catalogclass = registry::for($modname);
        return $catalogclass === null ? [] : ($catalogclass::write_options()['material_reference_fields'] ?? []);
    }

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => new value - only the fields to change (patch, not a full state)'
            ),
            'location' => material_files::location_parameter(),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Roher Schreibweg fuer genau EIN Materialreferenz-Pseudofeld
     * ({@see self::material_reference_specs()}), mit einem bereits
     * fertigen Dateimanager-Entwurf statt Materialordner-Pfaden - fuer
     * {@see \local_coursepilot\external\restore_activity_version}, das Dateien
     * aus dem Papierkorb ({@see \local_coursepilot\activity_file_trash}) statt
     * aus dem Materialordner zurueckschreibt (Spec 0018 §9.1, Issue #432).
     *
     * Kein eigener Feld-Patch-Validierungsdurchlauf: der Aufrufer hat
     * moodle/course:manageactivities und local/coursepilot:restoreversion
     * bereits geprueft, und der Entwurfsinhalt stammt ausschliesslich aus
     * dem eigenen Aenderungsverlauf/Papierkorb, nicht aus Client-Eingaben.
     *
     * @param int $cmid
     * @param string $fieldname One of this activity type's material_reference_specs() fields.
     * @param int $draftitemid Fertiger Dateimanager-Entwurf, z.B. aus
     *        {@see \local_coursepilot\activity_file_trash::resolve_restore_into_draft()}.
     * @return void
     */
    public static function write_pseudofield_draft(int $cmid, string $fieldname, int $draftitemid): void {
        global $CFG;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        $moduleinfo->{$fieldname} = $draftitemid;
        \update_moduleinfo($cm, $moduleinfo, $course);
    }

    /**
     * @param int $cmid
     * @param string $fieldsjson
     * @param string $location
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $cmid,
        string $fieldsjson,
        string $location = material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'fields_json' => $fieldsjson,
            'location' => $location,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        self::authorise($cm);

        $modname = (string) $cm->modname;
        $catalogclass = self::catalog_for($modname);
        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // sperrt nur DIESE Aktivitaetsart, wenn ein erkannter Moodle-Versionswechsel
        // eine Katalogabweichung ergeben hat. Lesen bleibt unberuehrt (kein
        // Lese-Werkzeug ruft assert_writable() auf).
        write_gate::assert_writable($modname);

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        $before = self::read_settings($cmid);
        // Rules, file checks and the native sequence live in the catalog core (#646, #647).
        $patch = write_target::update_activity(
            $catalogclass,
            $cm,
            get_course((int) $cm->course),
            $patch,
            $before,
            $params['location'],
            $params[learner_locks::PARAMETER]
        );

        $after = self::read_settings($cmid);
        [$changes, $sideeffects] = self::diff_and_side_effects($modname, $patch, $before, $after);

        return [
            'cmid' => (int) $cmid,
            'modname' => $modname,
            'message' => self::build_message($changes, $sideeffects, self::written_pseudofields($catalogclass, $patch)),
            'changes' => $changes,
            'side_effects' => $sideeffects,
        ];
    }

    /**
     * Prueft Kontext und Capabilities (Issue #523: aus execute() ausgelagert,
     * um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param \stdClass $cm
     * @return \context_module
     */
    private static function authorise(\stdClass $cm): \context_module {
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung vorgezogen (Spec 0015 §3.3: "im Kurs
        // einer Kollegin: lesen ja, schreiben nein - mit klarer Meldung").
        // get_moduleinfo_data() prueft dieselbe Capability spaeter ohnehin
        // erneut ueber can_update_moduleinfo() - der Aufruf hier ist billig
        // (nur require_capability(), kein DB-Zugriff) und stellt sicher, dass
        // eine fehlende Bearbeiten-Berechtigung nicht hinter einer
        // Feldvalidierungsmeldung versteckt bleibt.
        require_capability('moodle/course:manageactivities', $context);

        return $context;
    }

    /**
     * Die Katalogklasse fuer $modname, sofern der Schreibweg dieser Endpunkt
     * ist (Spec 0015 §3.1: manche Aktivitaetsarten haben ein eigenes
     * Einzelwerkzeug, z.B. quiz -> update_quiz_settings).
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
     * Ist-Stand als assoziatives Array - dieselbe Zusammenstellung wie
     * {@see get_module_settings}, ueber deren settings_json wiederverwendet
     * statt dupliziert (Ticket #384: "gleiche Bauform fuer Read-Teil
     * wiederverwendbar").
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Vorher-/Nachher-Werte je tatsaechlich geaendertem Feld, plus
     * ausgeloeste Nebenwirkungen - aus einem echten Vorher-/Nachher-Vergleich
     * (nicht aus dem Patch selbst uebernommen), damit eine parallele
     * Handaenderung an einem anderen Feld korrekt unerwaehnt bleibt und ein
     * Patch, der den bestehenden Wert nur wiederholt, nicht als Aenderung
     * gemeldet wird.
     *
     * @param string $modname
     * @param array $patch
     * @param array $before
     * @param array $after
     * @return array{0: array, 1: string[]}
     */
    private static function diff_and_side_effects(string $modname, array $patch, array $before, array $after): array {
        $changes = [];
        $sideeffects = [];
        $catalogclass = registry::for($modname);
        $triggers = $catalogclass::write_options()['side_effect_triggers'] ?? [];

        foreach (array_keys($patch) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $after[$fieldname] ?? null;
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'field' => $fieldname,
                    'before_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            if (isset($triggers[$fieldname][$newvalue]) && $oldvalue != $newvalue) {
                $sideeffects[] = $triggers[$fieldname][$newvalue];
            }
        }

        return [$changes, $sideeffects];
    }

    /**
     * Die Pseudofelder aus dem Patch - die, die der Vorher/Nachher-Vergleich
     * grundsaetzlich nicht sehen kann (#403).
     *
     * Pseudofelder haben per Definition keine Spalte in der Instanztabelle
     * ("assignsubmission_file_enabled" steht in assign_plugin_config, die
     * choice-Optionen in choice_options). read_settings() liest den Ist-Stand
     * der Datenbankfelder, dort stehen sie vorher wie nachher als null - der
     * Diff bleibt leer, obwohl geschrieben wurde. Ein echter Vergleich
     * braeuchte eine Leseschicht je Aktivitaetsart; stattdessen sagt die
     * Meldung ausdruecklich, was sie nicht vergleichen kann.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array $patch
     * @return array<string, mixed> Feldname => gesetzter Wert.
     */
    private static function written_pseudofields(string $catalogclass, array $patch): array {
        $names = array_column($catalogclass::pseudofields(), 'name');
        return array_intersect_key($patch, array_flip($names));
    }

    /**
     * Die Lehrkraft-deutsche Aenderungsmeldung (Spec 0015 §3.3: "die Antwort
     * ist die Aenderungsmeldung").
     *
     * @param array $changes
     * @param string[] $sideeffects
     * @param array<string, mixed> $pseudofields Geschriebene Pseudofelder, siehe
     *        {@see self::written_pseudofields()} - nicht vergleichbar, aber gesetzt.
     * @return string
     */
    private static function build_message(array $changes, array $sideeffects, array $pseudofields = []): string {
        if (!$changes && !$pseudofields) {
            return 'Keine Aenderung: der Patch stimmte bereits mit dem aktuellen Stand ueberein.';
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['field'] . '" von ' . $change['before_json'] . ' auf ' . $change['after_json'];
        }
        $message = $parts ? ('Geaendert: ' . implode(', ', $parts) . '.') : '';

        if ($pseudofields) {
            $set = [];
            foreach ($pseudofields as $fieldname => $value) {
                $set[] = '"' . $fieldname . '" = '
                    . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $message .= ($message ? ' ' : '')
                . 'Gesetzt, aber ohne Datenbankfeld und deshalb nicht mit dem Vorher-Stand vergleichbar: '
                . implode(', ', $set) . '.';
        }

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
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing German side-effect note'),
                'Triggered side effects from catalog category 5, empty when none were triggered'
            ),
        ]);
    }
}
