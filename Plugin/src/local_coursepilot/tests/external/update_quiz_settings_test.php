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

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Quiz als Einzelwerkzeug, Patch (Spec 0015 §5, Ticket #398).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_quiz_settings::class)]
final class update_quiz_settings_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Lehrkraft (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param int $cmid
     * @param array $felder
     * @param string $mode
     * @param float $grade
     * @return array
     */
    private function patch(int $cmid, array $felder, string $mode = '', float $grade = -1.0): array {
        return external_api::clean_returnvalue(
            update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($cmid, json_encode($felder), $mode, $grade)
        );
    }

    /**
     * @param int $cmid
     * @return \stdClass Rohe quiz-Tabellenzeile.
     */
    private function raw_quiz(int $cmid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        return $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * Die Beschreibung eines Tests laesst sich aendern, ohne das
     * Gesamtfeedback zu verlieren (Abnahmekriterium 2).
     */
    public function test_updating_intro_preserves_overall_feedback(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'intro' => 'Alte Beschreibung',
            'grade' => 100,
        ]);

        $this->patch($quiz->cmid, [
            'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
            'feedbackboundaries' => [50],
        ], '', 100.0);

        $this->patch($quiz->cmid, ['intro' => 'Neue Beschreibung']);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertSame('Neue Beschreibung', $raw->intro);
        $records = $DB->get_records('quiz_feedback', ['quizid' => $raw->id]);
        $this->assertCount(2, $records, 'Gesamtfeedback darf durch einen unbeteiligten Patch nicht verschwinden.');
    }

    /**
     * Ein Modus-Buendel ueberstimmt keine ausdruecklich genannten Felder
     * (Abnahmekriterium 3).
     */
    public function test_bundle_does_not_override_explicitly_named_fields(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $this->patch($quiz->cmid, ['attempts' => 9], 'mini-check');

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertEquals(9, $raw->attempts);
        $this->assertSame('immediatefeedback', $raw->preferredbehaviour, 'Buendel muss sonst greifen.');
    }

    /**
     * "grade" laeuft ueber Moodles eigenen Bewertungsweg
     * (grade_calculator::update_quiz_maximum_grade()), nicht ueber
     * felder_json (Abnahmekriterium 4).
     */
    public function test_grade_via_felder_json_is_blocked(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        try {
            $this->patch($quiz->cmid, ['grade' => 50]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('grade', $e->getMessage());
        }
    }

    /**
     * "grade" laesst sich stattdessen ueber den eigenen Parameter aendern -
     * und skaliert bestehende Gesamtfeedback-Grenzen anteilig um (Beleg,
     * dass der Moodle-eigene Grade-Calculator lief, nicht eine direkte
     * DB-Schreibung).
     */
    public function test_grade_parameter_changes_grade_via_native_path(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100,
        ]);

        $this->patch($quiz->cmid, [
            'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
            'feedbackboundaries' => [50],
        ], '', 100.0);

        $this->patch($quiz->cmid, [], '', 50.0);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertEqualsWithDelta(50.0, (float) $raw->grade, 0.0001);

        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $raw->id], 'mingrade DESC'));
        $feedback = current(array_filter($records, fn ($record) => $record->feedbacktext === 'Bestanden'));
        $this->assertEqualsWithDelta(25.0, (float) $feedback->mingrade, 0.0001, 'Grenze muss anteilig umgerechnet sein (50->25 bei Halbierung).');
    }

    /**
     * Grade-Aenderung UND explizit neue Gesamtfeedback-Grenzen im selben
     * Aufruf duerfen die Grenzen nicht doppelt umskalieren: die Grenzen sind
     * bereits gegen die NEUE Bewertung gueltig (von der Aufruferin so
     * gemeint) - der Grade-Wechsel muss deshalb VOR dem Schreiben der neuen
     * Grenzen laufen, nicht danach (sonst wuerden frisch geschriebene 25 bei
     * einer Halbierung auf 12.5 verzerrt).
     */
    public function test_grade_change_and_new_feedback_boundaries_in_one_call_are_not_double_scaled(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100,
        ]);

        // Halbierung von 100 auf 50 UND neue, gegen 50 gueltige Grenze (25)
        // im selben Aufruf.
        $this->patch($quiz->cmid, [
            'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
            'feedbackboundaries' => [25],
        ], '', 50.0);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertEqualsWithDelta(50.0, (float) $raw->grade, 0.0001);

        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $raw->id], 'mingrade DESC'));
        $feedback = current(array_filter($records, fn ($record) => $record->feedbacktext === 'Bestanden'));
        $this->assertEqualsWithDelta(25.0, (float) $feedback->mingrade, 0.0001, 'Explizit gegebene Grenze darf nicht zusaetzlich skaliert werden.');
    }

    /**
     * Unbekannter Feldname scheitert, nichts wird geschrieben.
     */
    public function test_unknown_field_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'name' => 'Unveraendert',
        ]);

        try {
            $this->patch($quiz->cmid, ['gibtsnicht' => 'x']);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht', $e->getMessage());
        }

        $this->assertSame('Unveraendert', $this->raw_quiz($quiz->cmid)->name);
    }

    /**
     * Unerlaubter Wert scheitert, nichts wird geschrieben.
     */
    public function test_invalid_value_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        try {
            $this->patch($quiz->cmid, ['navmethod' => 'diagonal']);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('navmethod', $e->getMessage());
        }

        $this->assertSame('free', $this->raw_quiz($quiz->cmid)->navmethod);
    }

    /**
     * Eine verletzte Kombinationsregel (feedbackboundaries ausserhalb des
     * gueltigen Bereichs) scheitert, nichts wird geschrieben.
     */
    public function test_combination_rule_violation_fails_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100,
        ]);

        try {
            $this->patch($quiz->cmid, [
                'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
                'feedbackboundaries' => [150], // Ausserhalb von (0, grade).
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('feedbackboundaries', $e->getMessage());
        }

        $this->assertCount(0, $DB->get_records('quiz_feedback', ['quizid' => $this->raw_quiz($quiz->cmid)->id]));
    }

    /**
     * Native Capability-Pruefung im Kurskontext: lesend bleibt Coursepilot
     * nutzbar, Schreiben ohne moodle/course:manageactivities scheitert.
     */
    public function test_write_without_native_capability_fails(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $editingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');
        $this->setUser($editingteacher);
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        update_quiz_settings::execute($quiz->cmid, json_encode(['name' => 'Uebernommen']));
    }

    /**
     * Der Patch erzeugt einen Stand im Aenderungsverlauf.
     */
    public function test_patch_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $quiz->cmid]);
        $this->patch($quiz->cmid, ['name' => 'Verlauf-Test']);
        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $quiz->cmid]));
    }

    /**
     * Die Anordnung (quiz_slots) wird von diesem Endpunkt nicht angefasst
     * (Abnahmekriterium 9) - grep-Beleg wie bei den generischen Endpunkten.
     */
    public function test_source_never_touches_quiz_slots(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/external/update_quiz_settings.php');
        $this->assertStringNotContainsString('quiz_slots', $source);
        $this->assertStringNotContainsString('mod_quiz\\structure', $source);
    }

    /**
     * Keine direkte DB-Schreibung auf der quiz-Tabelle (ADR 0016).
     */
    public function test_source_never_writes_the_quiz_table_directly(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/external/update_quiz_settings.php');
        $this->assertStringNotContainsString("update_record('quiz'", $source);
        $this->assertStringContainsString('update_moduleinfo(', $source);
    }

    /**
     * Abnahmekriterium #399: dasselbe Regime gilt fuer update_quiz_settings -
     * Drift sperrt den Patch, mit der Meldung "bitte der Administration
     * melden".
     */
    public function test_drift_blocks_update_quiz_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
        ]);

        \local_coursepilot\write_gate::all_statuses();
        set_config('driftviolations_quiz', json_encode(['Spalte "grade" fehlt.']), 'local_coursepilot');

        try {
            $this->patch($quiz->cmid, ['intro' => 'Neue Beschreibung']);
            $this->fail('execute() haette wegen Drift werfen muessen.');
        } catch (\moodle_exception $e) {
            // Die genaue deutsche Formulierung wird in write_gate_test.php
            // gegen das Sprachpaket geprueft.
            $this->assertSame('modnamedriftlocked', $e->errorcode);
        }
    }
}
