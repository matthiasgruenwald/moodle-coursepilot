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

use completion_info;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\field;
use local_coursepilot\catalog\registry;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Der einzige Schreibweg fuer Vervollstaendigungsfelder (Spec 0015 §8,
 * Ticket #392): die fuenf "completion*"-Felder sind auf der Sperrliste von
 * update_module_settings/create_module (shared_block::BLOCKLIST) - ein
 * beilaeufiger Patch darf keine Lernendendaten loeschen koennen.
 *
 * Der Grund liegt in course/modlib.php::update_moduleinfo(): ohne
 * "completionunlocked" verwirft Moodle die Felder still (Zeile ~625: nur
 * innerhalb `if (!empty($moduleinfo->completionunlocked))` werden completion/
 * completionview/completionusegrade/completionpassgrade/
 * completiongradeitemnumber tatsaechlich geschrieben); mit "completionunlocked"
 * ruft dieselbe Funktion anschliessend IMMER
 * `completion_info::reset_all_state()` auf (Zeile ~745, unabhaengig davon, ob
 * sich ein Feld tatsaechlich geaendert hat) - das loescht bei manueller
 * Vervollstaendigung (COMPLETION_TRACKING_MANUAL) endgueltig jede
 * course_modules_completion-Zeile dieser Aktivitaet, bei automatischer
 * Vervollstaendigung werden sie geloescht und aus dem aktuellen Zustand neu
 * berechnet (lib/completionlib.php::reset_all_state()/delete_all_state()).
 *
 * "completionexpected" ist die einzige Ausnahme: Moodle schreibt es
 * unabhaengig vom Sperrzustand (modlib.php Zeile ~637, "does not affect users
 * who have completed the activity") - deshalb nie Teil des Datenverlust-
 * Zweitakts.
 *
 * Benannter Zweitakt (Spec 0015 §8): scheitert die Datenverlust-Pruefung
 * (bereits vorhandene Vervollstaendigungsdaten fuer diese cmid UND eine der
 * vier Sperrfeld-Werte aendert sich tatsaechlich) und ist `confirmed` nicht
 * ausdruecklich true, wird NICHTS geschrieben - die Meldung nennt die Anzahl
 * betroffener Lernender. Erst der zweite Aufruf mit `confirmed: true` fuehrt
 * aus. Ohne Datenverlustrisiko (keine vorhandenen Daten, oder nur
 * "completionexpected" geaendert) laeuft der Aufruf ohne Zweitakt durch.
 *
 * Modulspezifische Vervollstaendigungsfelder (Ticket #461) laufen durch
 * denselben Weg: "completionsubmit" ("Abgabe erforderlich" bei assign,
 * "Abstimmung abgegeben" bei choice) ist eine Spalte der Instanztabelle, aber
 * fachlich ein Vervollstaendigungsfeld - und ein Sperrfeld, weil
 * mod_assign::update_instance() es nur mit "completionunlocked" schreibt. Bei
 * jeder anderen Aktivitaetsart scheitert der Patch mit einem Wegweiser statt
 * mit "Unbekanntes Feld" (s. {@see self::MODULE_SPECIFIC_FIELDS}).
 *
 * "completionunlocked" wird ausschliesslich hier und nur unmittelbar vor dem
 * bestaetigten (confirmed) Schreiben gesetzt - nie automatisch, nie in
 * update_module_settings/create_module (dort steht es auf der Sperrliste).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class set_completion extends external_api {

    /**
     * Von der Lehrkraft/KI ueber fields_json setzbare Vervollstaendigungsfelder
     * mit ihrem erlaubten Wertebereich (null = kein fester Wertebereich, z.B.
     * ein Zeitstempel). "completiongradeitemnumber" ist bewusst nicht dabei
     * (wie "cmidnumber" bei update_module_settings): es wird aus dem
     * Endzustand von "completionusegrade" abgeleitet (0 wenn
     * completionusegrade=1, sonst null - genau die Regel aus
     * course/modlib.php Zeile ~628-631).
     *
     * @var array<string, ?int[]>
     */
    private const ALLOWED_FIELDS = [
        'completion' => [0, 1, 2],
        'completionview' => [0, 1],
        'completionusegrade' => [0, 1],
        'completionpassgrade' => [0, 1],
        'completionexpected' => null,
    ];

    /**
     * Die vier Felder, deren tatsaechliche Aenderung "completionunlocked"
     * braucht (Sperrfelder) - und damit dem Datenverlust-Zweitakt unterliegen.
     * "completionexpected" ist ausgenommen (s. Klassendoku).
     *
     * @var string[]
     */
    private const LOCKED_FIELDS = ['completion', 'completionview', 'completionusegrade', 'completionpassgrade'];

    /**
     * Modulspezifische Vervollstaendigungsfelder (Ticket #461): sie stehen
     * nicht in {course_modules}, sondern als echte Spalte in der
     * Instanztabelle - "Abgabe erforderlich" bei einer Aufgabe, "Abstimmung
     * abgegeben" bei einer Abstimmung. Fachlich sind sie dennoch
     * Vervollstaendigungsfelder und laufen deshalb durch denselben Schreibweg:
     * mod_assign schreibt "completionsubmit" nur innerhalb von
     * `if (!empty($formdata->completionunlocked))` (mod/assign/locallib.php:
     * update_instance(), Zeile ~1569) - ueber update_module_settings waere es
     * still verworfen, und mit "completionunlocked" gilt derselbe
     * Datenverlust-Zweitakt wie fuer die vier generischen Sperrfelder.
     * Deshalb stehen sie auf der Sperrliste von
     * assign::blocklist()/choice::blocklist() und sind hier je Aktivitaetsart
     * freigeschaltet: ein Patch bei einer anderen Aktivitaetsart scheitert mit
     * Wegweiser statt mit "Unbekanntes Feld".
     *
     * @var array<string, array{modnames: string[], values: ?int[]}>
     */
    private const MODULE_SPECIFIC_FIELDS = [
        'completionsubmit' => ['modnames' => ['assign', 'choice'], 'values' => [0, 1]],
    ];

    /**
     * Die fuer $modname setzbaren Felder mit ihrem Wertebereich: die fuenf
     * generischen plus die modulspezifischen dieser Aktivitaetsart.
     *
     * @param string $modname
     * @return array<string, ?int[]>
     */
    private static function allowed_fields(string $modname): array {
        $fields = self::ALLOWED_FIELDS;
        foreach (self::MODULE_SPECIFIC_FIELDS as $fieldname => $spec) {
            if (in_array($modname, $spec['modnames'], true)) {
                $fields[$fieldname] = $spec['values'];
            }
        }
        return $fields;
    }

    /**
     * Die Sperrfelder dieser Aktivitaetsart - die vier generischen plus die
     * modulspezifischen (auch sie brauchen "completionunlocked").
     *
     * @param string $modname
     * @return string[]
     */
    private static function locked_fields(string $modname): array {
        $modulefields = array_keys(array_diff_key(self::allowed_fields($modname), self::ALLOWED_FIELDS));
        return array_merge(self::LOCKED_FIELDS, $modulefields);
    }

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object with "completion" (0=off,1=manual,2=automatic), "completionview", '
                    . '"completionusegrade", "completionpassgrade", "completionexpected" and - for "assign" and '
                    . '"choice" - "completionsubmit" (1=submission/response required) - only the fields to '
                    . 'change (patch)'
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms deleting existing completion data of learners, if writing back the '
                    . 'completion fields would trigger that (two-step confirmation of set_completion). Omit or '
                    . 'false on the first call.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param string $fieldsjson
     * @param bool $confirmed
     * @return array
     */
    public static function execute(int $cmid, string $fieldsjson, bool $confirmed = false): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'fields_json' => $fieldsjson,
            'confirmed' => $confirmed,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung im Kurskontext (Spec 0015 §3.3/§9.2),
        // identisch zu update_module_settings/create_module - keine eigene
        // Coursepilot-Schreib-Capability.
        require_capability('moodle/course:manageactivities', $context);

        $modname = (string) $cm->modname;
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            throw new moodle_exception(
                'unknownmodname',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'aktivitaetsarten' => implode(', ', registry::known_modnames())]
            );
        }

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        self::validate_patch($patch, $modname);

        $course = get_course((int) $cm->course);
        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            // Ohne kurs-/instanzweit aktivierte Abschlussverfolgung wuerde
            // Moodle jedes dieser Felder ohnehin verwerfen (completionlib.php:
            // is_enabled()) - klare Meldung statt stillem No-op.
            throw new moodle_exception('completionnotenabled', 'local_coursepilot');
        }

        $before = self::read_settings($cmid);
        $changedlocked = self::changed_fields($before, $patch, self::locked_fields($modname));
        $changedexpected = self::changed_fields($before, $patch, ['completionexpected']);

        if (!$changedlocked && !$changedexpected) {
            return [
                'cmid' => (int) $cmid,
                'modname' => (string) $cm->modname,
                'message' => 'Keine Aenderung: der Patch stimmte bereits mit dem aktuellen Stand ueberein.',
                'changes' => [],
            ];
        }

        if ($changedlocked) {
            $betroffenelernende = (int) $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]);
            if ($betroffenelernende > 0 && !$params['confirmed']) {
                // Erster Takt: melden, nicht ausfuehren (Spec 0015 §8).
                throw new moodle_exception(
                    'completiondatalossconfirmationrequired',
                    'local_coursepilot',
                    '',
                    ['betroffene_lernende' => $betroffenelernende]
                );
            }
        }

        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        pseudofield_carry_forward::apply($modname, $catalogclass, $moduleinfo, $before, $cm, $patch);
        self::apply_patch($moduleinfo, $before, $patch, (bool) $changedlocked, $modname);

        \update_moduleinfo($cm, $moduleinfo, $course);

        $after = self::read_settings($cmid);
        $changes = self::diff(array_merge($changedlocked, $changedexpected), $before, $after);

        return [
            'cmid' => (int) $cmid,
            'modname' => (string) $cm->modname,
            'message' => self::build_message($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Unbekanntes Feld oder Wert ausserhalb des erlaubten Bereichs scheitert
     * VOR jedem Schreibzugriff (alles-oder-nichts, wie update_module_settings).
     *
     * @param array $patch
     * @return void
     * @throws moodle_exception invalidfieldname|completionunknownfield|completioninvalidfieldvalue
     */
    private static function validate_patch(array $patch, string $modname): void {
        $allowedfields = self::allowed_fields($modname);
        foreach ($patch as $fieldname => $value) {
            field::assert_name($fieldname);
            if (!array_key_exists($fieldname, $allowedfields)) {
                self::assert_not_foreign_module_field($fieldname, $modname);
                throw new moodle_exception(
                    'completionunknownfield',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'erlaubt' => implode(', ', array_keys($allowedfields))]
                );
            }
            if (!is_int($value)) {
                throw new moodle_exception(
                    'completioninvalidfieldvalue',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'value' => json_encode($value)]
                );
            }
            $allowedvalues = $allowedfields[$fieldname];
            if ($allowedvalues !== null && !in_array($value, $allowedvalues, true)) {
                throw new moodle_exception(
                    'completioninvalidfieldvalue',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'value' => json_encode($value)]
                );
            }
        }
    }

    /**
     * Ein modulspezifisches Vervollstaendigungsfeld, das es gibt - nur nicht
     * bei dieser Aktivitaetsart: eigene Meldung mit den Aktivitaetsarten, die
     * es tragen, statt "Unbekanntes Feld" (dieselbe Haltung wie
     * shared_block::assert_not_read_only_vocabulary()).
     *
     * @param string $fieldname
     * @param string $modname
     * @return void
     * @throws moodle_exception completionfieldnotformodname
     */
    private static function assert_not_foreign_module_field(string $fieldname, string $modname): void {
        if (!array_key_exists($fieldname, self::MODULE_SPECIFIC_FIELDS)) {
            return;
        }
        throw new moodle_exception('completionfieldnotformodname', 'local_coursepilot', '', [
            'field' => $fieldname,
            'modname' => $modname,
            'modnames' => implode(', ', self::MODULE_SPECIFIC_FIELDS[$fieldname]['modnames']),
        ]);
    }

    /**
     * @param int $cmid
     * @return array Ist-Stand, dieselbe Form wie get_module_settings (enthaelt
     *         bereits alle fuenf completion*-Felder).
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Welche der genannten Felder patcht $patch UND aendert dabei tatsaechlich
     * den Wert gegenueber $before? Ein Patch, der den bestehenden Wert nur
     * wiederholt, loest weder den Zweitakt noch "completionunlocked" aus.
     *
     * @param array $before
     * @param array $patch
     * @param string[] $fields
     * @return string[]
     */
    private static function changed_fields(array $before, array $patch, array $fields): array {
        $changed = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $patch)) {
                continue;
            }
            if ((int) ($before[$field] ?? 0) !== (int) $patch[$field]) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    /**
     * Ueberlagert $moduleinfo mit dem Endzustand (before + patch) fuer alle
     * fuenf Felder - "completiongradeitemnumber" wird aus dem Endzustand von
     * "completionusegrade" abgeleitet (course/modlib.php Zeile ~628-631).
     * "completionunlocked" wird NUR gesetzt, wenn sich mindestens ein
     * Sperrfeld tatsaechlich aendert - sonst bliebe "completionexpected" der
     * einzige Aenderungsgrund und Moodle riefe trotzdem
     * `reset_all_state()` auf, obwohl gar keine Sperrfeld-Aenderung vorliegt.
     *
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $before
     * @param array $patch
     * @param bool $lockedchanged
     * @return void
     */
    private static function apply_patch(
        \stdClass $moduleinfo,
        array $before,
        array $patch,
        bool $lockedchanged,
        string $modname
    ): void {
        $allowedfields = self::allowed_fields($modname);
        $final = [];
        foreach (array_keys($allowedfields) as $field) {
            $final[$field] = array_key_exists($field, $patch) ? (int) $patch[$field] : (int) ($before[$field] ?? 0);
        }

        $moduleinfo->completion = $final['completion'];
        $moduleinfo->completionview = $final['completionview'];
        $moduleinfo->completionexpected = $final['completionexpected'];
        $moduleinfo->completionusegrade = $final['completionusegrade'];
        $moduleinfo->completionpassgrade = $final['completionpassgrade'];
        $moduleinfo->completiongradeitemnumber = $final['completionusegrade'] ? 0 : null;

        // Die modulspezifischen Felder dieser Aktivitaetsart (Ticket #461) -
        // je eine echte Spalte der Instanztabelle, die update_moduleinfo()
        // ueber den Formularweg an add_instance()/update_instance() reicht.
        foreach (array_keys(self::MODULE_SPECIFIC_FIELDS) as $field) {
            if (array_key_exists($field, $allowedfields)) {
                $moduleinfo->$field = $final[$field];
            }
        }

        if ($lockedchanged) {
            $moduleinfo->completionunlocked = 1;
        }
    }

    /**
     * Vorher-/Nachher-Werte je tatsaechlich geaendertem Feld - echter
     * Vorher-/Nachher-Vergleich wie update_module_settings::diff_and_side_effects().
     *
     * @param string[] $fields
     * @param array $before
     * @param array $after
     * @return array
     */
    private static function diff(array $fields, array $before, array $after): array {
        $changes = [];
        foreach (array_unique($fields) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $after[$fieldname] ?? null;
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'field' => $fieldname,
                    'before_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return $changes;
    }

    /**
     * @param array $changes
     * @return string
     */
    private static function build_message(array $changes): string {
        if (!$changes) {
            return 'Keine Aenderung: der Patch stimmte bereits mit dem aktuellen Stand ueberein.';
        }
        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['field'] . '" von ' . $change['before_json'] . ' auf ' . $change['after_json'];
        }
        return 'Vervollstaendigung geaendert: ' . implode(', ', $parts) . '.';
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
        ]);
    }
}
