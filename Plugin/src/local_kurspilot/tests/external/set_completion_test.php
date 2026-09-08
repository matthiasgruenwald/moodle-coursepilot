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

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Der einzige Schreibweg fuer Vervollstaendigungsfelder, im benannten
 * Zweitakt (Spec 0015 §8, Ticket #392).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(set_completion::class)]
final class set_completion_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs (Abschlussverfolgung an), Lehrkraft (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param int $cmid
     * @return array Ist-Stand, dieselbe Form wie get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * Legt eine echte course_modules_completion-Zeile fuer $userid an $cmid
     * an - simuliert vorhandene Lernenden-Abschlussdaten, ohne den ganzen
     * Moodle-Vervollstaendigungsmotor durchlaufen zu muessen.
     *
     * @param int $cmid
     * @param int $userid
     * @return void
     */
    private function seed_completion_data(int $cmid, int $userid): void {
        global $DB;
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $userid,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);
    }

    /**
     * Die vier Sperrfelder scheitern in update_module_settings (Sperrliste) -
     * "completionusegrade" und "completionunlocked" sind #392s Ergaenzung
     * neben den bereits von #388 gesperrten Feldern.
     */
    public function test_all_completion_fields_are_blocked_in_update_module_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        foreach (['completion', 'completionview', 'completionexpected', 'completionusegrade',
                'completionpassgrade', 'completionunlocked'] as $field) {
            try {
                update_module_settings::execute($page->cmid, json_encode([$field => 1]));
                $this->fail('Erwartete moodle_exception blieb aus fuer Feld "' . $field . '".');
            } catch (\moodle_exception $e) {
                $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
                $this->assertStringContainsString($field, $e->getMessage());
                $this->assertStringContainsString('set_completion', $e->getMessage());
            }
        }
    }

    /**
     * Eine Aufgabe behaelt ihre aktiven Abgabearten, wenn nur die
     * Abschlussverfolgung geschrieben wird.
     *
     * mod_assign leitet "nosubmissions" bei jedem Schreibvorgang aus den
     * aktivierten Abgabearten ab; diese Felder liefert get_moduleinfo_data()
     * nicht. Ohne Weitertragen des Ist-Stands schaltete set_completion sie
     * still ab - die Aufgabe nahm danach keine Abgaben mehr an (#400).
     */
    public function test_assign_keeps_its_submission_types(): void {
        $this->resetAfterTest();
        global $DB;
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign->id]));

        external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode(['completion' => 1]))
        );

        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'completion', ['id' => $cmid]));
    }

    /**
     * Dieselben Felder scheitern auch beim Anlegen (create_module).
     */
    public function test_completion_field_is_blocked_in_create_module(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            create_module::execute($course->id, 0, 'page', json_encode([
                'name' => 'x',
                'page' => ['text' => 'x', 'format' => FORMAT_HTML],
                'completionview' => 1,
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
            $this->assertStringContainsString('completionview', $e->getMessage());
            $this->assertStringContainsString('set_completion', $e->getMessage());
        }
    }

    /**
     * Erster Aufruf, der bestehende Abschlussdaten loeschen wuerde: fuehrt
     * NICHT aus, meldet den Datenverlust in Lehrkraft-Deutsch.
     */
    public function test_first_call_with_data_loss_does_not_execute_and_reports_it(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        try {
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('1', $e->getMessage());
            $this->assertStringContainsString('bestaetigt', $e->getMessage());
        }

        // Nichts geschrieben.
        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($page->cmid)['completion']);
        global $DB;
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
    }

    /**
     * Zweiter Aufruf mit bestaetigt: true fuehrt aus.
     */
    public function test_second_call_with_confirmation_executes(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]), true)
        );

        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($page->cmid)['completion']);
        $this->assertNotEmpty($result['aenderungen']);
        $this->assertStringContainsString('completion', $result['meldung']);
    }

    /**
     * Eine Aenderung der Abschlussverfolgung ohne vorhandene Lernendendaten
     * (frisch angelegte Aktivitaet) laeuft ohne Zweitakt durch.
     */
    public function test_change_without_existing_data_runs_without_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]))
        );

        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($page->cmid)['completion']);
        $this->assertSame(1, $this->read($page->cmid)['completionview']);
        $this->assertNotEmpty($result['aenderungen']);
    }

    /**
     * "completionexpected" allein loest nie den Zweitakt aus, auch mit
     * vorhandenen Lernendendaten - Moodle schreibt es unabhaengig vom
     * Sperrzustand (modlib.php).
     */
    public function test_completionexpected_alone_never_needs_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        $expected = time() + DAYSECS;
        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completionexpected' => $expected]))
        );

        $this->assertSame($expected, $this->read($page->cmid)['completionexpected']);
        $this->assertNotEmpty($result['aenderungen']);

        // Bestehende Abschlussdaten bleiben unangetastet.
        global $DB;
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($page->cmid)['completion']);
    }

    /**
     * Ein anderes Feld der Aktivitaet aendern (update_module_settings) laesst
     * bestehende Abschlussdaten unangetastet - ueber jeden Weg (Abnahmekriterium).
     */
    public function test_other_field_change_via_update_module_settings_leaves_completion_data_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        update_module_settings::execute($page->cmid, json_encode(['name' => 'Neu']));

        $this->assertSame('Neu', $this->read($page->cmid)['name']);
        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($page->cmid)['completion']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
    }

    /**
     * "completionunlocked" wird nie automatisch gesetzt: eine Aenderung, die
     * keines der vier Sperrfelder tatsaechlich aendert, ruft
     * update_moduleinfo() ohne "completionunlocked" auf und loest deshalb
     * kein reset_all_state() aus - geprueft indirekt ueber unveraenderte
     * Abschlussdaten bei einem reinen Patch auf den bereits geltenden Wert.
     */
    public function test_patch_matching_current_value_does_not_touch_completion_data(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_MANUAL]))
        );

        $this->assertEmpty($result['aenderungen']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
    }

    /**
     * Unbekanntes Feld scheitert vor jedem Schreibzugriff.
     */
    public function test_unknown_field_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['name' => 'x']));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }
    }

    /**
     * Ein Wert ausserhalb des erlaubten Bereichs scheitert.
     */
    public function test_invalid_value_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['completion' => 99]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('completion', $e->getMessage());
        }
    }

    /**
     * Ohne aktivierte Abschlussverfolgung im Kurs scheitert der Aufruf mit
     * klarer Meldung statt Moodle die Felder still verwerfen zu lassen.
     */
    public function test_completion_disabled_for_course_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionnotenabled', $e->errorcode);
        }
    }

    /**
     * Native Capability-Pruefung im Kurskontext: ohne
     * moodle/course:manageactivities scheitert der Schreibvorgang.
     */
    public function test_write_without_native_capability_fails(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        set_completion::execute($page->cmid, json_encode(['completionview' => 1]));
    }

    /**
     * Der Schreibvorgang erzeugt einen Stand im Aenderungsverlauf - ueber
     * denselben course_module_updated-Beobachter wie jeder andere
     * update_moduleinfo()-Aufruf (kein eigener Mechanismus).
     */
    public function test_write_creates_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));

        $this->assertGreaterThan(
            0,
            $DB->count_records('local_kurspilot_cm_version', ['cmid' => $page->cmid])
        );
    }

    /**
     * "completionsubmit" ("Abgabe erforderlich") ist bei einer Aufgabe die
     * eigentlich gemeinte Abschlussbedingung - sie laeuft ueber denselben
     * Schreibweg wie die generischen Felder (#461).
     */
    public function test_assign_completionsubmit_is_written(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode([
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionsubmit' => 1,
            ]))
        );

        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($cmid)['completion']);
        $this->assertStringContainsString('completionsubmit', $result['meldung']);
        // Die Abgabearten bleiben unangetastet (#400).
        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign->id]));
    }

    /**
     * Dasselbe Feld bei "choice" ("Abstimmung abgegeben") - der zweite und
     * letzte Modultyp mit einem modulspezifischen Vervollstaendigungsfeld.
     */
    public function test_choice_completionsubmit_is_written(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $choice = $this->getDataGenerator()->get_plugin_generator('mod_choice')->create_instance([
            'course' => $course->id,
        ]);
        $cmid = (int) get_coursemodule_from_instance('choice', $choice->id)->id;

        set_completion::execute($cmid, json_encode([
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
        ]));

        $this->assertEquals(1, $DB->get_field('choice', 'completionsubmit', ['id' => $choice->id]));
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($cmid)['completion']);
    }

    /**
     * Das Feld ist modulspezifisch: bei jeder anderen Aktivitaetsart scheitert
     * der Aufruf mit einem Wegweiser statt "Unbekanntes Feld".
     */
    public function test_completionsubmit_is_rejected_for_other_modnames(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['completionsubmit' => 1]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionfieldnotformodname', $e->errorcode);
            $this->assertStringContainsString('assign', $e->getMessage());
            $this->assertStringContainsString('choice', $e->getMessage());
        }
    }

    /**
     * "completionsubmit" ist ein Sperrfeld wie die vier generischen: Moodle
     * schreibt es nur mit "completionunlocked" (mod/assign/locallib.php:
     * update_instance()), und das loescht die Abschlussdaten der Lernenden -
     * also derselbe Zweitakt.
     */
    public function test_completionsubmit_change_needs_confirmation_when_data_exists(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        try {
            set_completion::execute($cmid, json_encode(['completionsubmit' => 1]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completiondatalossconfirmationrequired', $e->errorcode);
        }
        $this->assertEquals(0, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));

        set_completion::execute($cmid, json_encode(['completionsubmit' => 1]), true);
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
    }

    /**
     * Ein Patch, der den geltenden Wert nur wiederholt, aendert nichts und
     * loest keinen Zweitakt aus - auch beim modulspezifischen Feld.
     */
    public function test_completionsubmit_patch_matching_current_value_is_a_noop(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode(['completionsubmit' => 0]))
        );

        $this->assertEmpty($result['aenderungen']);
    }

    /**
     * Der Umweg ueber update_module_settings bleibt fuer beide Modultypen
     * gesperrt - ein beilaeufiger Patch darf Abschlussregeln nicht aendern.
     */
    public function test_completionsubmit_is_blocked_in_update_module_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
        ]);
        $choice = $this->getDataGenerator()->get_plugin_generator('mod_choice')->create_instance([
            'course' => $course->id,
        ]);

        foreach (['assign' => $assign->id, 'choice' => $choice->id] as $modname => $instanceid) {
            $cmid = (int) get_coursemodule_from_instance($modname, $instanceid)->id;
            try {
                update_module_settings::execute($cmid, json_encode(['completionsubmit' => 1]));
                $this->fail('Erwartete moodle_exception blieb aus fuer "' . $modname . '".');
            } catch (\moodle_exception $e) {
                $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
                $this->assertStringContainsString('completionsubmit', $e->getMessage());
                $this->assertStringContainsString('set_completion', $e->getMessage());
            }
        }
    }
}
