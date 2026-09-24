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
 * "Vor drei Versionen war das besser" als Schreibvorgang (Spec 0015 §10.7,
 * Ticket #395): Fortschreiben statt Rueckspulen.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(restore_activity_version::class)]
final class restore_activity_version_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Lehrkraft (editingteacher).
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
     * Abnahmekriterium 1: eine Rueckkehr erzeugt eine neue juengste Version
     * statt eines Rueckspulens - die cmid bleibt unveraendert, der alte
     * Feldwert erscheint als neuer Ist-Stand.
     */
    public function test_restore_writes_target_state_forward_keeping_cmid(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;

        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));
        $this->assertSame('Neu', $this->read($cmid)['name']);

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertSame($cmid, $result['cmid']);
        $this->assertSame('page', $result['modname']);
        $this->assertSame('Alt', $this->read($cmid)['name']);

        // Fortgeschrieben, nicht zurueckgespult: drei Staende (Anlage, Aenderung,
        // Rueckkehr), keiner geloescht/ueberschrieben.
        $this->assertSame(3, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
    }

    /**
     * Der Seiteninhalt liegt im Versionsstand als "content", wird von Moodle
     * beim Schreiben aber ausschliesslich aus dem Editor-Pseudofeld "page"
     * gelesen. Eine Rueckkehr darf daher nicht den aktuellen Inhalt weitertragen.
     */
    public function test_restore_writes_back_page_content(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'content' => '<p>Version eins</p>',
            'contentformat' => FORMAT_PLAIN,
        ]);
        $cmid = $page->cmid;

        update_module_settings::execute($cmid, json_encode([
            'page' => ['text' => '<p>Version zwei</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]));
        $this->assertSame('<p>Version zwei</p>', $this->read($cmid)['content']);

        restore_activity_version::execute($cmid, 1);

        $this->assertSame('<p>Version eins</p>', $this->read($cmid)['content']);
        $this->assertSame(FORMAT_PLAIN, $this->read($cmid)['contentformat']);
    }

    /**
     * Auch der Aktivitaetstext einer Aufgabe wird von Moodle aus dem
     * activityeditor-Pseudofeld uebernommen.
     */
    public function test_restore_writes_back_assign_activity_content(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        update_module_settings::execute($cmid, json_encode([
            'activity' => '<p>Version eins</p>',
            'activityformat' => FORMAT_PLAIN,
        ]));
        $this->assertSame('<p>Version eins</p>', $this->read($cmid)['activity']);
        $this->assertSame(FORMAT_PLAIN, $this->read($cmid)['activityformat']);

        update_module_settings::execute($cmid, json_encode(['activity' => '<p>Version zwei</p>']));
        $this->assertSame('<p>Version zwei</p>', $this->read($cmid)['activity']);

        restore_activity_version::execute($cmid, 2);

        $this->assertSame('<p>Version eins</p>', $this->read($cmid)['activity']);
    }

    /**
     * Abnahmekriterium 2: nach einer Rueckkehr entsteht keine zusaetzliche
     * Aktivitaet im Kurs.
     */
    public function test_restore_creates_no_additional_activity(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));

        $before = $DB->count_records('course_modules', ['course' => $course->id]);
        restore_activity_version::execute($cmid, 1);
        $after = $DB->count_records('course_modules', ['course' => $course->id]);

        $this->assertSame($before, $after);
    }

    /**
     * Abnahmekriterium 3 (Proxy): die cmid - und damit jeder Link/jede
     * Voraussetzung, die sie referenziert - bleibt unveraendert erreichbar.
     */
    public function test_restore_keeps_cmid_reachable(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));

        restore_activity_version::execute($cmid, 1);

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $this->assertSame($cmid, (int) $cm->id);
    }

    /**
     * Abnahmekriterium 4+5: ohne "bestaetigt" bleiben Abschlussfelder
     * unangetastet, wenn das Zurueckschreiben bestehende Abschlussdaten
     * loeschen wuerde - die Meldung ist set_completion's echte
     * Datenverlust-Warnung mit Betroffenenzahl, nicht eine eigene Erfindung.
     */
    public function test_restore_without_confirmation_leaves_completion_fields_untouched_on_data_loss_risk(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = $page->cmid;

        // Version 2: Vervollstaendigung auf automatisch (noch keine Lernendendaten,
        // laeuft ohne Zweitakt durch).
        set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($cmid)['completion']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $cmid]));
        foreach ($result['aenderungen'] as $change) {
            $this->assertNotSame('completion', $change['feld']);
        }
        $this->assertStringContainsString('bestaetigt', $result['meldung']);
        // Die echte Betroffenenzahl aus set_completion's Zweitakt, nicht nur
        // eine generische Warnung (Testumgebung laeuft in Englisch).
        $this->assertStringContainsString('1 learner', $result['meldung']);
    }

    /**
     * Abnahmekriterium 5+6: nur der Zweitakt mit "bestaetigt": true schreibt
     * Abschlussfelder zurueck, die bestehende Abschlussdaten loeschen wuerden -
     * ueber set_completion, nicht ueber einen eigenen Mechanismus.
     */
    public function test_restore_with_confirmation_writes_back_completion_fields(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = $page->cmid;

        set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1, true)
        );

        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($cmid)['completion']);
        $fields = array_column($result['aenderungen'], 'feld');
        $this->assertContains('completion', $fields);
    }

    /**
     * Ohne Datenverlustrisiko (keine vorhandenen Abschlussdaten) laeuft die
     * Rueckkehr der Abschlussfelder sofort mit durch, wie bei jedem anderen
     * set_completion()-Aufruf ohne Risiko - "bestaetigt" ist hier nicht
     * noetig, restore erfindet keine zusaetzliche eigene Huerde.
     */
    public function test_restore_writes_back_completion_fields_without_confirmation_when_no_data_at_risk(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = $page->cmid;

        // Version 2, keine Lernendendaten vorhanden.
        set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($cmid)['completion']);
        $fields = array_column($result['aenderungen'], 'feld');
        $this->assertContains('completion', $fields);
    }

    /**
     * Ohne tatsaechlichen Unterschied (Ziel = aktueller Stand) wird nichts
     * geschrieben - kein neuer Verlaufsstand, klare "keine Aenderung"-Meldung.
     */
    public function test_restore_to_current_version_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertEmpty($result['aenderungen']);
        $this->assertStringContainsString('Keine Änderung', $result['meldung']);
        $this->assertSame(1, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
    }

    /**
     * Abnahmekriterium: eine unbekannte Zielversion scheitert klar.
     */
    public function test_unknown_target_version_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            restore_activity_version::execute($page->cmid, 99);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('versionnotfound', $e->errorcode);
        }
    }

    /**
     * Abnahmekriterium 7: local/coursepilot:restoreversion wird geprueft.
     */
    public function test_rejects_user_without_own_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Neu']));

        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:restoreversion',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        restore_activity_version::execute($page->cmid, 1);
    }

    /**
     * Abnahmekriterium 7: moodle/course:manageactivities wird zusaetzlich
     * geprueft - eine nicht-editierende Lehrkraft (hat lt. Vorbelegung
     * local/coursepilot:restoreversion, aber nicht manageactivities) scheitert.
     */
    public function test_rejects_user_without_native_manageactivities_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Neu']));

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        restore_activity_version::execute($page->cmid, 1);
    }

    /**
     * Abnahmekriterium: der Rueckschreibvorgang selbst erzeugt einen Stand im
     * Aenderungsverlauf - ueber denselben course_module_updated-Beobachter
     * wie jeder andere update_moduleinfo()-Aufruf.
     */
    public function test_restore_creates_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));
        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]);

        restore_activity_version::execute($cmid, 1);

        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
    }

    /**
     * Abnahmekriterium: die Antwort ist die Aenderungsmeldung in
     * Lehrkraft-Deutsch.
     */
    public function test_response_message_names_changed_field(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertStringContainsString('name', $result['meldung']);
        $this->assertStringContainsString('Version 1', $result['meldung']);
    }

    /**
     * Erstellt eine Materialdatei fuer den aktuell angemeldeten Nutzer,
     * ersetzt sie falls sie schon existiert - derselbe Ablageort, den
     * upload_material_file bespielt (Issue #428).
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function create_material_file(string $path, string $content): void {
        $filerecord = \local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            '/coursepilot-material/',
            $path
        );
        $existing = get_file_storage()->get_file(
            $filerecord['contextid'],
            $filerecord['component'],
            $filerecord['filearea'],
            $filerecord['itemid'],
            $filerecord['filepath'],
            $filerecord['filename']
        );
        \local_coursepilot\material_files::replace($existing ?: null, $filerecord, $content);
    }

    /**
     * Rollback holt eine ersetzte Aktivitaetsdatei zurueck (Spec 0018 §9.1,
     * Issue #432): Version 1 haengt Datei A an, Version 2 ersetzt sie durch
     * Datei B (gleicher Dateiname, anderer Inhalt) - die Rueckkehr zu
     * Version 1 muss Datei A wieder an der Aktivitaet haengen haben, nicht
     * die Luecke aus Spec 0015 §10.4.
     */
    public function test_restore_brings_back_a_replaced_activity_file(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $modulecontext = \context_module::instance($cmid);

        $this->create_material_file('blatt.pdf', 'Fassung A');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']])); // Version 2

        $this->create_material_file('blatt.pdf', 'Fassung B');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']])); // Version 3

        $replaced = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf');
        $this->assertSame('Fassung B', $replaced->get_content());

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 2)
        );

        $restored = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf');
        $this->assertNotFalse($restored);
        $this->assertSame('Fassung A', $restored->get_content());
        $this->assertStringContainsString('blatt.pdf', $result['meldung']);
        $this->assertStringContainsString('Papierkorb', $result['meldung']);
    }

    /**
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Auch das modulspezifische Vervollstaendigungsfeld kehrt zurueck - ueber
     * set_completion, nicht ueber den generischen Patch (dort steht es auf der
     * Sperrliste von assign/choice, Ticket #461).
     */
    public function test_restore_writes_back_completionsubmit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        set_completion::execute($cmid, json_encode(['completionsubmit' => 1]));
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertEquals(0, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
        $this->assertContains('completionsubmit', array_column($result['aenderungen'], 'feld'));
    }
}
