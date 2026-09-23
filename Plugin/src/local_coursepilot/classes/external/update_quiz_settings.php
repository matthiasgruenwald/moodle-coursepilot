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
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\quiz;
use local_coursepilot\catalog\quiz_write_bridge;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Quiz-Patch (Spec 0015 §5, Ticket #398): quiz ist eine begruendete
 * Ausnahme vom generischen Vehikel {@see update_module_settings} - der
 * Katalog (#383) fuehrt quiz trotzdem, mit `schreibweg(): 'update_quiz_settings'`.
 *
 * Read-modify-write wie beim generischen Patch (Spec 0015 §3.3): der
 * Formularweg (update_moduleinfo()) traegt fuer die meisten Felder, "grade"
 * laeuft stattdessen ueber {@see quiz_write_bridge::apply_grade_change()}
 * (Moodles eigener Grade-Calculator statt einer direkten DB-Schreibung,
 * ADR 0016). Ohne "feedbacktext" im Patch loescht Moodle das Gesamtfeedback
 * still (quiz_after_add_or_update() loescht immer zuerst) - dieser Endpunkt
 * liest den Ist-Stand und schreibt ihn deshalb unveraendert mit zurueck,
 * genauso fuer die 32 Review-Checkboxen und das Passwort (siehe
 * {@see quiz_write_bridge}-Klassendoku).
 *
 * Die drei Modus-Buendel kommen aus dem Katalog ({@see quiz::bundles()}),
 * nicht aus dieser Werkzeugbeschreibung - ein Buendelwert gilt nur fuer
 * Felder, die "felder_json" nicht bereits selbst nennt (Spec 0015 §2.4).
 *
 * Die Anordnung (Fragen/Seiten/Abschnitte) ist nicht Teil dieses Endpunkts
 * (Spec 0015 §5, ADR 0016) - sie wird ausschliesslich ueber die 16
 * mod_quiz-Struktur-Ereignisse versioniert (#396).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_quiz_settings extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID des Tests'),
            'felder_json' => new external_value(
                PARAM_RAW,
                'JSON-Objekt Feldname => neuer Wert - nur die zu aendernden Felder (Patch, kein Vollstand). '
                    . '"grade"/"sumgrades" hier NICHT moeglich (Sperrliste), siehe Parameter "grade".'
            ),
            'mode' => new external_value(
                PARAM_ALPHANUMEXT,
                'Modus-Buendel: "mini-check", "lernstandscheck" oder "abschlusstest". Buendelwerte gelten nur '
                    . 'fuer Felder, die felder_json nicht bereits selbst nennt. Leer = kein Moduswechsel.',
                VALUE_DEFAULT,
                ''
            ),
            'grade' => new external_value(
                PARAM_FLOAT,
                'Neue maximale Bewertung des Tests - laeuft ueber Moodles eigenen Bewertungsweg (skaliert '
                    . 'bestehende Versuchsnoten und Gesamtfeedback-Grenzen automatisch um), nicht ueber felder_json. '
                    . '-1 = nicht aendern.',
                VALUE_DEFAULT,
                -1.0
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param string $felderjson
     * @param string $mode
     * @param float $grade
     * @return array
     */
    public static function execute(int $cmid, string $felderjson, string $mode = '', float $grade = -1.0): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'felder_json' => $felderjson,
            'mode' => $mode,
            'grade' => $grade,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native Berechtigungspruefung vorgezogen, wie
        // {@see update_module_settings::execute()} - get_moduleinfo_data()
        // prueft dieselbe Capability spaeter ohnehin erneut.
        require_capability('moodle/course:manageactivities', $context);

        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // dasselbe Regime wie fuer das generische Vehikel gilt unveraendert
        // fuer das Quiz-Einzelwerkzeug. Lesen bleibt unberuehrt.
        write_gate::assert_writable('quiz');

        $patch = json_decode($params['felder_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $before = quiz::effective_state($cm, $quiz);

        $merged = array_merge(self::bundle_fields($params['mode']), $patch);

        quiz_write_bridge::validate_fields($merged);
        $newgrade = $params['grade'] >= 0 ? $params['grade'] : (float) $quiz->grade;
        quiz_write_bridge::validate_combination_rules(array_merge($before, $merged), $merged, $newgrade);
        quiz_write_bridge::assert_stealth_allowed($merged);

        // Eine Grade-Aenderung laeuft ZUERST (Moodles eigener Grade-Calculator,
        // siehe quiz_write_bridge-Klassendoku): er skaliert bestehende
        // Gesamtfeedback-Grenzen anteilig auf die neue Bewertung um. Erst
        // DANACH liest get_moduleinfo_data() den (jetzt aktuellen) Ist-Stand -
        // sonst wuerden explizit im selben Aufruf mitgegebene, bereits gegen
        // die neue Bewertung gueltige "feedbackboundaries" durch die
        // anschliessende Skalierung ein zweites Mal verzerrt.
        $gradechanged = $params['grade'] >= 0 && abs($params['grade'] - (float) $quiz->grade) > 0.00001;
        if ($gradechanged) {
            quiz_write_bridge::apply_grade_change((int) $quiz->id, $params['grade']);
        }

        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        // get_moduleinfo_data() liefert die rohe quiz-Zeile plus den
        // gemeinsamen Block (visible, groupmode, cmidnumber, ...) - dieselbe
        // Grundlage wie beim generischen Patch (update_module_settings).
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);

        $feedbacktextpatch = $merged['feedbacktext'] ?? null;
        $feedbackboundariespatch = $merged['feedbackboundaries'] ?? [];
        $fieldstowrite = $merged;
        unset($fieldstowrite['feedbacktext'], $fieldstowrite['feedbackboundaries']);

        foreach ($fieldstowrite as $fieldname => $value) {
            $moduleinfo->{quiz_write_bridge::moduleinfo_property($fieldname)} = $value;
        }

        // Ein reiner ->intro-Patch wuerde sonst stillschweigend verpuffen
        // (Abnahmekriterium 2: Beschreibung aendern) - siehe
        // pseudofield_carry_forward::sync_intro_editor_from_patch().
        pseudofield_carry_forward::sync_intro_editor_from_patch($moduleinfo, $fieldstowrite);

        // Carry-forward der 32 Review-Checkboxen (quiz_process_options()
        // berechnet die acht Bitmasken IMMER aus diesen 32 Feldern neu, siehe
        // quiz_write_bridge-Klassendoku).
        foreach (quiz_write_bridge::decompose_review_bitmasks($quiz) as $name => $value) {
            if (!array_key_exists($name, $merged)) {
                $moduleinfo->{$name} = $value;
            }
        }

        // Passwort-Carry-forward (Formularname "quizpassword", siehe Katalog-Klassendoku).
        if (!array_key_exists('quizpassword', $merged)) {
            $moduleinfo->quizpassword = (string) $quiz->password;
        }

        // Gesamtfeedback-Carry-forward (Klassendoku quiz_write_bridge).
        if ($feedbacktextpatch !== null) {
            quiz_write_bridge::apply_feedback_pseudofields($moduleinfo, $feedbacktextpatch, $feedbackboundariespatch);
        } else {
            $current = quiz_write_bridge::read_feedback((int) $quiz->id);
            if ($current['feedbacktext']) {
                quiz_write_bridge::apply_feedback_pseudofields($moduleinfo, $current['feedbacktext'], $current['feedbackboundaries']);
            }
        }

        // #400: get_moduleinfo_data() liefert "gradepass" im Anzeigeformat
        // ("0,00"); ungeprueft zurueckgeschrieben endet der Aufruf im
        // DB-Schreibfehler, nachdem die Aenderung schon persistiert ist.
        pseudofield_carry_forward::unformat_localised_gradepass($moduleinfo);

        \update_moduleinfo($cm, $moduleinfo, $course);

        $after = quiz::effective_state($cm, $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST));
        [$changes, $sideeffects] = self::diff_and_side_effects($merged, $before, $after, $gradechanged);

        return [
            'cmid' => (int) $cm->id,
            'meldung' => self::build_message($changes, $sideeffects),
            'aenderungen' => $changes,
            'nebenwirkungen' => $sideeffects,
        ];
    }

    /**
     * Modus-Buendel aus dem Katalog, oder leer ohne Moduswechsel.
     *
     * @param string $mode
     * @return array<string, mixed>
     * @throws moodle_exception unknownmode
     */
    private static function bundle_fields(string $mode): array {
        if ($mode === '') {
            return [];
        }
        $bundles = quiz::bundles();
        if (!array_key_exists($mode, $bundles)) {
            throw new moodle_exception('unknownmode', 'local_coursepilot', '', [
                'mode' => $mode,
                'modi' => implode(', ', array_keys($bundles)),
            ]);
        }
        return $bundles[$mode];
    }

    /**
     * Vorher-/Nachher-Diff je tatsaechlich geaendertem Feld (echter Vergleich,
     * nicht der Patch selbst - identisches Prinzip wie
     * {@see update_module_settings::diff_and_side_effects()}), plus
     * Nebenwirkungsvermerke fuer Kalendereintraege bei timeopen/timeclose
     * (Katalog-Klassendoku quiz::side_effects()) und fuer eine Grade-Aenderung.
     *
     * @param array $merged
     * @param array $before
     * @param array $after
     * @param bool $gradechanged
     * @return array{0: array, 1: string[]}
     */
    private static function diff_and_side_effects(array $merged, array $before, array $after, bool $gradechanged): array {
        $changes = [];
        $sideeffects = [];
        $fieldnames = array_keys($merged);
        if ($gradechanged) {
            $fieldnames[] = 'grade';
        }

        foreach (array_unique($fieldnames) as $fieldname) {
            if (in_array($fieldname, ['feedbacktext', 'feedbackboundaries'], true)) {
                continue;
            }
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $after[$fieldname] ?? null;
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'feld' => $fieldname,
                    'von_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'auf_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }

        if (array_key_exists('feedbacktext', $merged) && $before['feedbacktext'] !== $after['feedbacktext']) {
            $changes[] = [
                'feld' => 'feedbacktext',
                'von_json' => json_encode($before['feedbacktext'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'auf_json' => json_encode($after['feedbacktext'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        if ((array_key_exists('timeopen', $merged) && (int) $after['timeopen'] > 0)
                || (array_key_exists('timeclose', $merged) && (int) $after['timeclose'] > 0)) {
            $sideeffects[] = 'Der Kalendereintrag fuer den Test wurde aktualisiert.';
        }
        if ($gradechanged) {
            $sideeffects[] = 'Bestehende Versuchsnoten und Gesamtfeedback-Grenzen wurden anteilig auf die neue Bewertung umgerechnet.';
        }

        return [$changes, $sideeffects];
    }

    /**
     * Die Lehrkraft-deutsche Aenderungsmeldung (Spec 0015 §3.3/§5).
     *
     * @param array $changes
     * @param string[] $sideeffects
     * @return string
     */
    private static function build_message(array $changes, array $sideeffects): string {
        if (!$changes) {
            return 'Keine Aenderung: der Patch stimmte bereits mit dem aktuellen Stand ueberein.';
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['feld'] . '" von ' . $change['von_json'] . ' auf ' . $change['auf_json'];
        }
        $message = 'Geaendert: ' . implode(', ', $parts) . '.';

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
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Aenderungsmeldung'),
            'aenderungen' => new external_multiple_structure(
                new external_single_structure([
                    'feld' => new external_value(PARAM_TEXT, 'Feldname'),
                    'von_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert vor dem Schreiben'),
                    'auf_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert nach dem Schreiben'),
                ]),
                'Je tatsaechlich geaendertem Feld ein Eintrag'
            ),
            'nebenwirkungen' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Nebenwirkungsvermerk in Lehrkraft-Deutsch'),
                'Ausgeloeste Nebenwirkungen, leer wenn keine ausgeloest wurden'
            ),
        ]);
    }
}
