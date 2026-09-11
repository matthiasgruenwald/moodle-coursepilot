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
use local_kurspilot\catalog\registry;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use local_kurspilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Der erste Schreibvorgang (Spec 0015 §3.3, Ticket #388).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(update_module_settings::class)]
final class update_module_settings_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::use_test_transport(null);
        parent::tearDown();
    }

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
     * Ein Patch auf ein Feld aendert genau dieses Feld, nennt Vorher-/
     * Nachher-Wert, und laesst eine parallele Handaenderung an einem anderen
     * Feld unangetastet (Abnahmekriterien: Diff, Read-modify-write
     * unmittelbar vor dem Schreiben).
     */
    public function test_patch_changes_named_field_and_survives_concurrent_hand_edit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alter Titel',
        ]);

        // Parallele Handaenderung an einem ANDEREN Feld, direkt vor dem
        // Schreibvorgang - muss ueberleben (Spec 0015 §3.3).
        $DB->set_field('page', 'intro', 'Handaenderung der Lehrkraft', ['id' => $page->id]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($page->cmid, json_encode(['name' => 'Neuer Titel']))
        );

        $this->assertCount(1, $result['aenderungen']);
        $this->assertSame('name', $result['aenderungen'][0]['feld']);
        $this->assertSame('"Alter Titel"', $result['aenderungen'][0]['von_json']);
        $this->assertSame('"Neuer Titel"', $result['aenderungen'][0]['auf_json']);
        $this->assertStringContainsString('Alter Titel', $result['meldung']);
        $this->assertStringContainsString('Neuer Titel', $result['meldung']);

        $after = $this->read($page->cmid);
        $this->assertSame('Neuer Titel', $after['name']);
        $this->assertSame('Handaenderung der Lehrkraft', $after['intro']);
    }

    /**
     * Ein Patch, der den bestehenden Wert nur wiederholt, meldet keine
     * Aenderung.
     */
    public function test_patch_matching_current_value_reports_no_change(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Gleicher Titel',
        ]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($page->cmid, json_encode(['name' => 'Gleicher Titel']))
        );

        $this->assertCount(0, $result['aenderungen']);
    }

    /**
     * Ein Patch, der den bestehenden Wert nur wiederholt, meldet auch in der
     * Meldung selbst keine Aenderung - nicht nur in "aenderungen".
     */
    public function test_patch_matching_current_value_says_so_in_the_message(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Gleicher Titel',
        ]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($page->cmid, json_encode(['name' => 'Gleicher Titel']))
        );

        $this->assertStringContainsString('Keine Aenderung', $result['meldung']);
    }

    /**
     * Ein Pseudofeld hat keine Spalte in der Instanztabelle, der
     * Vorher/Nachher-Vergleich kann es also nicht sehen. Die Meldung darf
     * deshalb trotzdem nicht "Keine Aenderung" behaupten - geschrieben wurde
     * sehr wohl (#403).
     */
    public function test_written_pseudofield_is_named_in_the_message(): void {
        $this->resetAfterTest();
        global $DB;
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'assignsubmission_file_enabled' => 0,
            'assignsubmission_onlinetext_enabled' => 0,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode(['assignsubmission_file_enabled' => 1]))
        );

        $this->assertStringNotContainsString('Keine Aenderung', $result['meldung']);
        $this->assertStringContainsString('assignsubmission_file_enabled', $result['meldung']);
        $this->assertEquals(1, $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assign->id,
            'subtype' => 'assignsubmission',
            'plugin' => 'file',
            'name' => 'enabled',
        ]));
    }

    /**
     * Auch beim Patchen ist "coursepagevisibility" Lese-Vokabular: die Meldung
     * nennt den Schreibweg statt "Unbekanntes Feld" (#404).
     */
    public function test_read_only_vocabulary_points_to_the_writable_field(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
        ]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['coursepagevisibility' => 'stealth']));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('visibleoncoursepage', $e->getMessage());
            $this->assertStringNotContainsString('Unbekanntes Feld', $e->getMessage());
        }
    }

    /**
     * Unbekannter Feldname scheitert, nichts wird geschrieben - die Meldung
     * nennt das Feld und verweist auf describe_module_fields.
     */
    public function test_unknown_field_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Unveraendert',
        ]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['gibtsnicht' => 'x']));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht', $e->getMessage());
            $this->assertStringContainsString('describe_module_fields', $e->getMessage());
        }

        $this->assertSame('Unveraendert', $this->read($page->cmid)['name']);
    }

    /**
     * Gesperrtes Feld (durchgaengige Sperrliste) scheitert ebenso.
     */
    public function test_blocked_field_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Unveraendert',
        ]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['timemodified' => 123]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('timemodified', $e->getMessage());
            $this->assertStringContainsString('describe_module_fields', $e->getMessage());
        }

        $this->assertSame('Unveraendert', $this->read($page->cmid)['name']);
    }

    /**
     * Unerlaubter Wert (ausserhalb des dokumentierten Wertebereichs)
     * scheitert ebenso.
     */
    public function test_invalid_value_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['visible' => 5]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('visible', $e->getMessage());
            $this->assertStringContainsString('describe_module_fields', $e->getMessage());
        }

        $this->assertSame(1, $this->read($page->cmid)['visible']);
    }

    /**
     * Eine verletzte Kombinationsregel scheitert, ohne Teilstand.
     */
    public function test_combination_rule_violation_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $forum = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_instance([
            'course' => $course->id,
            'duedate' => 2000000000,
            'cutoffdate' => 0,
        ]);

        try {
            // cutoffdate liegt vor duedate - verletzt die Kombinationsregel.
            update_module_settings::execute($forum->cmid, json_encode(['cutoffdate' => 1000000000]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cutoffdate', $e->getMessage());
            $this->assertStringContainsString('duedate', $e->getMessage());
        }

        $this->assertEquals(0, $this->read($forum->cmid)['cutoffdate']);
    }

    /**
     * forcesubscribe=2 (Auto-Abonnement) wird als Nebenwirkung ausdruecklich
     * in der Antwort ausgesprochen (Spec 0015 §3.3, Katalogkategorie 5).
     */
    public function test_forum_forcesubscribe_side_effect_is_announced(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $forum = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_instance([
            'course' => $course->id,
            'forcesubscribe' => 0,
        ]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($forum->cmid, json_encode(['forcesubscribe' => 2]))
        );

        $this->assertNotEmpty($result['nebenwirkungen']);
        $this->assertStringContainsString('Kursteilnehmenden', $result['nebenwirkungen'][0]);
        $this->assertStringContainsString('abonniert', $result['nebenwirkungen'][0]);
        $this->assertStringContainsString('Kursteilnehmenden', $result['meldung']);
    }

    /**
     * update_moduleinfo() (course/modlib.php:675-680) ueberschreibt
     * $moduleinfo->intro immer aus $moduleinfo->introeditor['text'] - ohne
     * pseudofield_carry_forward::sync_intro_editor_from_patch() (Issue #433,
     * generalisiert aus dem assign-spezifischen introimages-Fix) wuerde ein
     * reiner "intro"-Patch auf JEDER Aktivitaetsart mit FEATURE_MOD_INTRO
     * stillschweigend verpuffen - hier stellvertretend an forum geprueft,
     * nicht nur an assign.
     */
    public function test_intro_patch_persists_on_a_non_assign_activity(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $forum = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_instance(['course' => $course->id]);

        update_module_settings::execute($forum->cmid, json_encode(['intro' => 'Neue Forumsbeschreibung']));

        $this->assertSame('Neue Forumsbeschreibung', $this->read($forum->cmid)['intro']);
    }

    /**
     * Ein Abschlussfeld im Patch scheitert (Sperrliste, course_modules-
     * completion*-Spalten).
     */
    public function test_completion_field_is_blocked(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['completionview' => 1]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('completionview', $e->getMessage());
        }
    }

    /**
     * Aktivitaetsarten mit eigenem Schreibweg (quiz -> update_quiz_settings)
     * werden nicht ueber dieses Vehikel geschrieben.
     */
    public function test_modname_with_own_write_vehicle_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($quiz->cmid, json_encode(['name' => 'x']));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('update_quiz_settings', $e->getMessage());
        }
    }

    /**
     * Eine nicht von Kurspilot gefuehrte Aktivitaetsart scheitert mit
     * derselben Meldung wie describe_module_fields.
     */
    public function test_unknown_modname_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $wiki = $this->getDataGenerator()->get_plugin_generator('mod_wiki')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($wiki->cmid, json_encode(['name' => 'x']));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('wiki', $e->getMessage());
        }
    }

    /**
     * Schreiben im fremden Kurs ohne native Bearbeiten-Berechtigung
     * scheitert mit klarer Meldung, nicht still - lesend bleibt Kurspilot
     * weiter nutzbar (Spec 0015 §3.3).
     */
    public function test_write_without_native_capability_fails_but_read_still_works(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $editingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Fremder Kurs',
        ]);

        // Nicht-bearbeitende Lehrkraft: hat local/kurspilot:use, aber nicht
        // moodle/course:manageactivities.
        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        // Lesend bleibt nutzbar.
        $this->assertSame('Fremder Kurs', $this->read($page->cmid)['name']);

        $this->expectException(\required_capability_exception::class);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Uebernommen']));
    }

    /**
     * Der Schreibvorgang erzeugt einen Stand im Aenderungsverlauf (#385-387
     * beobachten course_module_updated automatisch).
     */
    public function test_write_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        // course_module_created hat bereits Version 1 angelegt (#385) - der
        // Schreibvorgang hier muss eine WEITERE Version hinzufuegen.
        $before = $DB->count_records('local_kurspilot_cm_version', ['cmid' => $page->cmid]);

        update_module_settings::execute($page->cmid, json_encode(['name' => 'Verlauf-Test']));

        $this->assertGreaterThan($before, $DB->count_records('local_kurspilot_cm_version', ['cmid' => $page->cmid]));
    }

    /**
     * Keine direkte DB-Schreibung auf einer Instanztabelle (ADR 0016) - der
     * einzige Schreibweg ist update_moduleinfo().
     */
    public function test_source_never_writes_the_instance_table_directly(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/external/update_module_settings.php');
        $this->assertStringNotContainsString('$DB->update_record', $source);
        $this->assertStringNotContainsString('$DB->insert_record', $source);
        $this->assertStringContainsString('update_moduleinfo(', $source);
    }

    /**
     * Eine Aktivitaet laesst sich verbergen (visible=0) und wieder sichtbar
     * machen (visible=1) - Ticket #390, Abnahmekriterium 1.
     */
    public function test_visibility_can_be_hidden_and_shown_again(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        update_module_settings::execute($page->cmid, json_encode(['visible' => 0]));
        $this->assertSame(0, $this->read($page->cmid)['visible']);

        update_module_settings::execute($page->cmid, json_encode(['visible' => 1]));
        $this->assertSame(1, $this->read($page->cmid)['visible']);
    }

    /**
     * Stealth (visibleoncoursepage=0) funktioniert generisch fuer alle acht
     * vom Feldkatalog gefuehrten, ueber dieses Vehikel geschriebenen
     * Aktivitaetsarten (Ticket #390, Abnahmekriterium 2) - quiz hat einen
     * eigenen Schreibweg (update_quiz_settings) und ist deshalb nicht dabei.
     * "coursepagevisibility" (Lese-Vokabular) wechselt dabei ebenfalls auf
     * "stealth" - dasselbe Wort wie get_module_settings/get_course_catalog
     * (Abnahmekriterium: identische Feldnamen).
     */
    public function test_stealth_visibility_works_for_all_eight_activity_types(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 1);
        [$course] = $this->course_with_editing_teacher();

        $modnames = registry::known_modnames();
        $this->assertContains('quiz', $modnames, 'quiz muss weiterhin katalogisiert sein (eigener Schreibweg).');
        $modnamesviaupdatemodulesettings = array_values(array_diff($modnames, ['quiz']));
        $this->assertCount(8, $modnamesviaupdatemodulesettings, 'Erwartet acht Aktivitaetsarten ueber dieses Vehikel.');

        foreach ($modnamesviaupdatemodulesettings as $modname) {
            $instance = $this->getDataGenerator()->get_plugin_generator('mod_' . $modname)->create_instance([
                'course' => $course->id,
            ]);

            $result = external_api::clean_returnvalue(
                update_module_settings::execute_returns(),
                update_module_settings::execute($instance->cmid, json_encode(['visibleoncoursepage' => 0]))
            );

            $after = $this->read($instance->cmid);
            $this->assertSame(0, $after['visibleoncoursepage'], "modname={$modname}");
            $this->assertSame('stealth', $after['coursepagevisibility'], "modname={$modname}");
            $this->assertStringContainsString('visibleoncoursepage', $result['meldung'], "modname={$modname}");
        }
    }

    /**
     * Bei abgeschaltetem allowstealth scheitert ein Stealth-Patch mit einer
     * klaren Meldung, es wird nichts geschrieben (Ticket #390,
     * Abnahmekriterium 3).
     */
    public function test_stealth_fails_with_clear_message_when_allowstealth_is_off(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 0);
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['visibleoncoursepage' => 0]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('allowstealth', $e->getMessage());
        }

        $this->assertSame(1, $this->read($page->cmid)['visibleoncoursepage']);
    }

    /**
     * Trotz abgeschaltetem allowstealth bleiben visible (Verbergen im Kurs)
     * und die Rueckkehr auf visibleoncoursepage=1 uneingeschraenkt moeglich -
     * die Sperre trifft nur den Zielwert 0.
     */
    public function test_hiding_is_still_allowed_when_stealth_is_off(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 0);
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        update_module_settings::execute($page->cmid, json_encode(['visible' => 0]));
        $this->assertSame(0, $this->read($page->cmid)['visible']);

        update_module_settings::execute($page->cmid, json_encode(['visibleoncoursepage' => 1]));
        $this->assertSame(1, $this->read($page->cmid)['visibleoncoursepage']);
    }

    /**
     * groupmode und groupingid lassen sich setzen, mit Vorher-/Nachher-
     * Meldung in Lehrkraft-Deutsch (Ticket #390, Abnahmekriterium 4/8).
     */
    public function test_groupmode_and_groupingid_can_be_set(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $grouping = $this->getDataGenerator()->create_grouping(['courseid' => $course->id]);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute(
                $page->cmid,
                json_encode(['groupmode' => SEPARATEGROUPS, 'groupingid' => (int) $grouping->id])
            )
        );

        $after = $this->read($page->cmid);
        $this->assertSame(SEPARATEGROUPS, $after['groupmode']);
        $this->assertSame((int) $grouping->id, $after['groupingid']);

        // Vorher-/Nachher-Zustand in Lehrkraft-Deutsch (Ticket #390,
        // Abnahmekriterium 8) - nicht nur der Feldname, auch die Werte.
        $this->assertCount(2, $result['aenderungen']);
        $bygroupmode = array_values(array_filter($result['aenderungen'], fn($c) => $c['feld'] === 'groupmode'))[0];
        $this->assertSame('0', $bygroupmode['von_json']);
        $this->assertSame((string) SEPARATEGROUPS, $bygroupmode['auf_json']);
        $this->assertStringContainsString('groupmode', $result['meldung']);
        $this->assertStringContainsString('groupingid', $result['meldung']);
        $this->assertStringContainsString((string) $grouping->id, $result['meldung']);
    }

    /**
     * idnumber laesst sich setzen (Ticket #390, Abnahmekriterium 5).
     */
    public function test_idnumber_can_be_set(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($page->cmid, json_encode(['idnumber' => 'kp-390']))
        );

        $this->assertSame('kp-390', $this->read($page->cmid)['idnumber']);

        // Vorher-/Nachher-Zustand in Lehrkraft-Deutsch (Ticket #390,
        // Abnahmekriterium 8).
        $this->assertCount(1, $result['aenderungen']);
        $this->assertSame('idnumber', $result['aenderungen'][0]['feld']);
        $this->assertSame('""', $result['aenderungen'][0]['von_json']);
        $this->assertSame('"kp-390"', $result['aenderungen'][0]['auf_json']);
        $this->assertStringContainsString('kp-390', $result['meldung']);
    }

    /**
     * set_coursemodule_groupmode() (in Moodle 5.2 deprecated) wird nirgends
     * im Plugin verwendet - der Gruppenmodus laeuft ausschliesslich ueber den
     * Formularweg (update_moduleinfo()) (Ticket #390, Abnahmekriterium 4).
     */
    public function test_deprecated_set_coursemodule_groupmode_is_never_used(): void {
        $plugindir = __DIR__ . '/../..';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugindir . '/classes'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString(
                'set_coursemodule_groupmode(',
                $source,
                'Gefunden in ' . $file->getPathname()
            );
        }
    }

    /**
     * Erstellt eine Materialdatei fuer den aktuell angemeldeten Nutzer -
     * derselbe Ablageort, den upload_material_file bespielt (Issue #428).
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function create_material_file(string $path, string $content): void {
        $filerecord = \local_kurspilot\material_files::filerecord(
            \local_kurspilot\material_files::own_context()->id,
            '/kurspilot-material/',
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
        \local_kurspilot\material_files::replace($existing ?: null, $filerecord, $content);
    }

    /**
     * Richtet den externen Materialbestand (WebDAV-Fake) fuer eine bereits
     * angemeldete Lehrkraft ein - anders als
     * {@see webdav_instance_fixture::set_up_external_material()} (das seine
     * eigene Person anlegt) fuer eine schon im Kurs eingeschriebene
     * Lehrkraft, damit derselbe Aufruf zugleich Kurs-Schreibrechte hat
     * (Issue #496).
     *
     * @param \stdClass $teacher
     * @return \local_kurspilot\tests\webdav\fake_webdav_transport
     */
    private function set_up_external_material_for(\stdClass $teacher): \local_kurspilot\tests\webdav\fake_webdav_transport {
        $this->grant_webdav_capability($teacher);
        $instanceid = $this->create_webdav_instance($teacher);
        $this->write_v2_pointer($teacher, 'materialbestand', $instanceid, 'Material');

        $fake = new \local_kurspilot\tests\webdav\fake_webdav_transport();
        webdav_instance::use_test_transport($fake);
        return $fake;
    }

    /**
     * Einbettung direkt aus dem externen Materialbestand (Issue #496, Spec
     * #486 §7): "ort" = "bestand" liest ueber den WebDAV-Transport-Fake und
     * kopiert die Datei ueber den Entwurfsbereich in die Aktivitaet, ohne
     * Umweg ueber die Werkbank.
     */
    public function test_introattachments_reference_attaches_material_file_from_external_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Kurspilot/Material/arbeitsblatt.pdf', 'Arbeitsblattinhalt');

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode(['introattachments' => ['arbeitsblatt.pdf']]))
        );

        $this->assertStringContainsString('introattachments', $result['meldung']);
        $modulecontext = \context_module::instance($cmid);
        $attached = get_file_storage()->get_file(
            $modulecontext->id,
            'mod_assign',
            'introattachment',
            0,
            '/',
            'arbeitsblatt.pdf'
        );
        $this->assertNotFalse($attached);
        $this->assertSame('Arbeitsblattinhalt', $attached->get_content());
    }

    /**
     * Explizit "ort" = "werkbank" greift weiterhin auf die Werkbank zu, auch
     * wenn der Materialbestand extern liegt (Issue #496) - derselbe Vertrag
     * wie bei den lesenden Materialwerkzeugen (Issue #495).
     */
    public function test_introattachments_reference_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Kurspilot/Material/nur-extern.pdf', 'extern');
        $this->create_material_file('werkbankdatei.pdf', 'aus der Werkbank');

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute(
                $cmid,
                json_encode(['introattachments' => ['werkbankdatei.pdf']]),
                \local_kurspilot\material_files::ORT_WERKBANK
            )
        );

        $this->assertStringContainsString('introattachments', $result['meldung']);
        $modulecontext = \context_module::instance($cmid);
        $attached = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'werkbankdatei.pdf');
        $this->assertNotFalse($attached);
        $this->assertSame('aus der Werkbank', $attached->get_content());
    }

    /**
     * Die Sperre unterhalb eines Eintrags vom Typ "kontextbereich" (Issue
     * #495, Spec #486 §2/§7) greift auch an der Einbettung (Issue #496) -
     * kein zweiter Zugang zu Kontextdateien am Personenbezug-Schalter vorbei.
     */
    public function test_introattachments_reference_under_kontextbereich_is_rejected(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        \local_kurspilot\storage_anchor::write_pointer_document([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \local_kurspilot\material_files::own_context()->id,
            'component' => \local_kurspilot\material_files::COMPONENT,
            'filearea' => \local_kurspilot\material_files::FILEAREA,
            'itemid' => \local_kurspilot\material_files::ITEMID,
            'filepath' => '/kurspilot-material/kontext/',
            'filename' => 'plan.md',
        ], '# Plan');

        try {
            update_module_settings::execute($cmid, json_encode(['introattachments' => ['kontext/plan.md']]));
            $this->fail('Ein Pfad unter dem Kontextbereich haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiskontext', $e->errorcode);
        }
    }

    /**
     * Verweisweg (Spec 0018 §4.2/§7, Issue #429): ein Materialordner-Pfad
     * wird zur "Zusaetzliche Dateien"-Anlage der Aufgabe uebernommen - die
     * Dateisperre aus Spec 0015 §4.3 faellt fuer assign.
     */
    public function test_introattachments_reference_attaches_material_file(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('arbeitsblatt.pdf', 'Arbeitsblattinhalt');

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode(['introattachments' => ['arbeitsblatt.pdf']]))
        );

        $this->assertStringContainsString('introattachments', $result['meldung']);
        $modulecontext = \context_module::instance($cmid);
        $attached = get_file_storage()->get_file(
            $modulecontext->id,
            'mod_assign',
            'introattachment',
            0,
            '/',
            'arbeitsblatt.pdf'
        );
        $this->assertNotFalse($attached);
        $this->assertSame('Arbeitsblattinhalt', $attached->get_content());
    }

    /**
     * Ein zweiter Verweis haengt an, statt den ersten Anhang zu ersetzen
     * (Spec 0018 §4.2).
     */
    public function test_introattachments_reference_preserves_earlier_attachment(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('erstes.pdf', 'zuerst');
        $this->create_material_file('zweites.pdf', 'danach');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['erstes.pdf']]));

        update_module_settings::execute($cmid, json_encode(['introattachments' => ['zweites.pdf']]));

        $modulecontext = \context_module::instance($cmid);
        $fs = get_file_storage();
        $this->assertNotFalse($fs->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'erstes.pdf'));
        $this->assertNotFalse($fs->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'zweites.pdf'));
    }

    /**
     * Ein Verweis auf eine nicht existierende Materialdatei scheitert mit
     * einer Meldung, die den erwarteten Pfad nennt (Abnahmekriterium #429).
     */
    public function test_introattachments_reference_to_missing_material_file_fails_with_clear_message(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        try {
            update_module_settings::execute($cmid, json_encode(['introattachments' => ['gibtsnicht.pdf']]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht.pdf', $e->getMessage());
        }
    }

    /**
     * Scheitert der Schreibvorgang (hier: verletzte Kombinationsregel eines
     * anderen Feldes im selben Patch), bleibt die Materialdatei unangetastet
     * liegen - ein zweiter Versuch kann denselben Verweis erneut nutzen,
     * ohne erneut hochzuladen (Spec 0018 §4.2, Abnahmekriterium #429).
     */
    public function test_failed_attach_leaves_material_file_untouched(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'duedate' => 2000000000,
            'cutoffdate' => 0,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('arbeitsblatt.pdf', 'Arbeitsblattinhalt');

        try {
            // cutoffdate liegt vor duedate - verletzt die Kombinationsregel,
            // validate_patch() scheitert VOR jedem Materialzugriff.
            update_module_settings::execute($cmid, json_encode([
                'introattachments' => ['arbeitsblatt.pdf'],
                'cutoffdate' => 1000000000,
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cutoffdate', $e->getMessage());
        }

        $material = get_file_storage()->get_file(
            \local_kurspilot\material_files::own_context()->id,
            \local_kurspilot\material_files::COMPONENT,
            \local_kurspilot\material_files::FILEAREA,
            \local_kurspilot\material_files::ITEMID,
            '/kurspilot-material/',
            'arbeitsblatt.pdf'
        );
        $this->assertNotFalse($material);
        $this->assertSame('Arbeitsblattinhalt', $material->get_content());

        $modulecontext = \context_module::instance($cmid);
        $this->assertFalse(get_file_storage()->get_file(
            $modulecontext->id,
            'mod_assign',
            'introattachment',
            0,
            '/',
            'arbeitsblatt.pdf'
        ));
    }

    /**
     * Wie {@see self::test_failed_attach_leaves_material_file_untouched()},
     * aber der Fehlschlag passiert nicht in validate_patch() (vor jedem
     * Materialzugriff), sondern MITTEN im Aufloesungsschritt selbst: die
     * erste referenzierte Datei existiert und wird bereits in den Entwurf
     * kopiert, die zweite fehlt und laesst resolve_into_draft() abbrechen -
     * update_moduleinfo() wird dadurch nie erreicht. Beide Materialdateien
     * bleiben unangetastet, die Aufgabe bekommt keinen Anhang (#429).
     */
    public function test_failed_attach_leaves_material_files_untouched_mid_resolution(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('vorhanden.pdf', 'Inhalt');

        try {
            update_module_settings::execute($cmid, json_encode([
                'introattachments' => ['vorhanden.pdf', 'fehlt.pdf'],
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('fehlt.pdf', $e->getMessage());
        }

        $material = get_file_storage()->get_file(
            \local_kurspilot\material_files::own_context()->id,
            \local_kurspilot\material_files::COMPONENT,
            \local_kurspilot\material_files::FILEAREA,
            \local_kurspilot\material_files::ITEMID,
            '/kurspilot-material/',
            'vorhanden.pdf'
        );
        $this->assertNotFalse($material);
        $this->assertSame('Inhalt', $material->get_content());

        $modulecontext = \context_module::instance($cmid);
        $this->assertFalse(get_file_storage()->get_file(
            $modulecontext->id,
            'mod_assign',
            'introattachment',
            0,
            '/',
            'vorhanden.pdf'
        ));
    }

    /**
     * Ersetzen einer Aktivitaetsdatei (gleicher Dateiname erneut referenziert,
     * anderer Inhalt) loescht die alte Datei nicht, sondern verdraengt sie in
     * den Papierkorb - der Papierkorb-Datensatz zeigt auf denselben
     * contenthash wie das Original (Spec 0018 §9.1, Issue #432).
     */
    public function test_replacing_introattachment_trashes_the_old_file_with_the_same_contenthash(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $modulecontext = \context_module::instance($cmid);

        $this->create_material_file('blatt.pdf', 'Erste Fassung');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));
        $original = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf');
        $this->assertNotFalse($original);
        $originalcontenthash = $original->get_contenthash();

        $this->create_material_file('blatt.pdf', 'Zweite, bessere Fassung');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));

        $replaced = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf');
        $this->assertNotFalse($replaced);
        $this->assertSame('Zweite, bessere Fassung', $replaced->get_content());
        $this->assertNotSame($originalcontenthash, $replaced->get_contenthash());

        $fromtrash = \local_kurspilot\activity_file_trash::find_for_restore(
            $modulecontext->id,
            $cmid,
            'blatt.pdf',
            $originalcontenthash
        );
        $this->assertNotNull($fromtrash);
        $this->assertSame('Erste Fassung', $fromtrash->get_content());
        $this->assertSame($originalcontenthash, $fromtrash->get_contenthash());
    }

    /**
     * Der Verlaufsstand markiert introattachment-Dateien nicht mehr pauschal
     * als Luecke (gap=1) - fuer sie existiert seit Issue #432 ein echter
     * Wiederherstellungsweg ueber den Papierkorb (Spec 0018 §9.1).
     */
    public function test_introattachment_files_are_not_marked_as_a_gap_in_the_history(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('blatt.pdf', 'Inhalt');

        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));

        $latest = max(array_column(\local_kurspilot\history\version_history::list_versions($cmid)['versionen'], 'version'));
        $files = \local_kurspilot\history\version_history::files_at($cmid, $latest);
        $introattachment = array_values(array_filter(
            $files,
            static fn($f): bool => $f->component === 'mod_assign' && $f->filearea === 'introattachment'
        ));

        $this->assertNotEmpty($introattachment);
        $this->assertSame(0, (int) $introattachment[0]->gap);
    }

    /**
     * @param string $filename
     * @param int $width
     * @param int $height
     * @return void
     */
    private function store_material_png(string $filename, int $width, int $height): void {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        $filerecord = \local_kurspilot\material_files::filerecord(
            \local_kurspilot\material_files::own_context()->id,
            '/kurspilot-material/',
            $filename
        );
        $existing = get_file_storage()->get_file(
            $filerecord['contextid'],
            $filerecord['component'],
            $filerecord['filearea'],
            $filerecord['itemid'],
            $filerecord['filepath'],
            $filerecord['filename']
        );
        \local_kurspilot\material_files::replace($existing ?: null, $filerecord, $png);
    }

    /**
     * Fachabbildung in die Aufgabenbeschreibung einbetten (Spec 0018 §4.2/§5,
     * Issue #433): eine Materialdatei landet als <img> IM Intro-Text, nicht
     * als danebenliegender Anhang - der Alt-Text wird im Patch selbst
     * mitgeschrieben (Glossar: Alt-Text als KI-Qualitätsroutine).
     */
    public function test_introimages_embeds_material_image_into_intro(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->store_material_png('diagramm.png', 400, 300);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode([
                'intro' => '<p>Bitte auswerten:</p><img src="@@PLUGINFILE@@/diagramm.png" '
                    . 'alt="Saeulendiagramm der Messreihe">',
            ] + ['introimages' => ['diagramm.png']]))
        );

        $this->assertStringContainsString('intro', $result['meldung']);
        $after = $this->read($cmid);
        // Moodle speichert Intro-Text mit dem @@PLUGINFILE@@-Platzhalter in
        // der Datenbank (lib/filelib.php:1103) - die eigentliche
        // pluginfile.php-URL entsteht erst beim Anzeigen ueber format_text().
        // Der Beleg fuers Einbetten ist deshalb der Platzhalter plus die
        // tatsaechlich abgelegte Datei (s.u.), nicht eine fertige URL.
        $this->assertStringContainsString('alt="Saeulendiagramm der Messreihe"', $after['intro']);
        $this->assertStringContainsString('@@PLUGINFILE@@/diagramm.png', $after['intro']);

        $modulecontext = \context_module::instance($cmid);
        $embedded = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'intro', 0, '/', 'diagramm.png');
        $this->assertNotFalse($embedded);
    }

    /**
     * Der komplette Weg aus Spec 0018 §5 laeuft durch: eine Materialdatei
     * wird zugeschnitten (#431), der Ausschnitt landet als eigene
     * Materialdatei - und genau der laesst sich anschliessend einbetten,
     * ohne erneut hochzuladen (Abnahmekriterium #433).
     */
    public function test_cropped_material_file_can_be_embedded_afterwards(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->store_material_png('buchseite.png', 800, 600);

        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.1, 0.1, 0.6, 0.6);

        update_module_settings::execute($cmid, json_encode([
            'intro' => '<p>Ausschnitt:</p><img src="@@PLUGINFILE@@/ausschnitt.png" alt="Kartenausschnitt">',
            'introimages' => ['ausschnitt.png'],
        ]));

        $after = $this->read($cmid);
        $this->assertStringContainsString('ausschnitt.png', $after['intro']);
        $this->assertStringContainsString('alt="Kartenausschnitt"', $after['intro']);
    }

    /**
     * Ein Bildtyp ausserhalb der Einbett-Whitelist (Spec 0018 §6) wird mit
     * klarer Meldung abgewiesen statt eingebettet - dieselbe Meldung wie
     * beim Upload, engere Whitelist (Abnahmekriterium #433).
     */
    public function test_introimages_rejects_disallowed_extension_with_clear_message(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('arbeitsblatt.pdf', 'PDF-Inhalt');

        try {
            update_module_settings::execute($cmid, json_encode([
                'intro' => '<p>Text</p><img src="@@PLUGINFILE@@/arbeitsblatt.pdf" alt="geht nicht">',
                'introimages' => ['arbeitsblatt.pdf'],
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('arbeitsblatt.pdf', $e->getMessage());
        }

        $modulecontext = \context_module::instance($cmid);
        $this->assertFalse(get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'intro', 0, '/', 'arbeitsblatt.pdf'));
    }

    /**
     * Ein Verweis auf eine nicht existierende Materialdatei scheitert mit
     * einer Meldung, die den erwarteten Pfad nennt - dieselbe Zusicherung
     * wie bei introattachments (#429).
     */
    public function test_introimages_reference_to_missing_material_file_fails_with_clear_message(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        try {
            update_module_settings::execute($cmid, json_encode([
                'intro' => '<img src="@@PLUGINFILE@@/gibtsnicht.png" alt="fehlt">',
                'introimages' => ['gibtsnicht.png'],
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht.png', $e->getMessage());
        }
    }

    /**
     * Der Aenderungsverlauf verzeichnet ein eingebettetes Bild wie jede
     * andere Aenderung (Abnahmekriterium #433): "intro" erscheint im Diff,
     * und die eingebettete Datei selbst taucht im Dateibestand der Version
     * auf.
     */
    public function test_embedded_image_appears_in_the_change_history(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->store_material_png('diagramm.png', 200, 150);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode([
                'intro' => '<img src="@@PLUGINFILE@@/diagramm.png" alt="Diagramm">',
                'introimages' => ['diagramm.png'],
            ]))
        );

        $this->assertNotEmpty(array_filter($result['aenderungen'], static fn($c): bool => $c['feld'] === 'intro'));

        $latest = max(array_column(\local_kurspilot\history\version_history::list_versions($cmid)['versionen'], 'version'));
        $files = \local_kurspilot\history\version_history::files_at($cmid, $latest);
        $embedded = array_values(array_filter(
            $files,
            static fn($f): bool => $f->component === 'mod_assign' && $f->filearea === 'intro' && $f->filename === 'diagramm.png'
        ));
        $this->assertNotEmpty($embedded);
    }

    /**
     * Wie {@see self::test_replacing_introattachment_trashes_the_old_file_with_the_same_contenthash()},
     * nur fuer den Einbettungsweg: ein zweites Mal unter demselben
     * Dateinamen eingebettet, landet die ALTE Fassung im Papierkorb statt
     * verloren zu gehen - dieselbe Zusicherung wie bei introattachments,
     * jetzt auch fuer die "intro"-Filearea (Spec 0018 §9.1).
     */
    public function test_replacing_an_embedded_image_trashes_the_old_file_with_the_same_contenthash(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $modulecontext = \context_module::instance($cmid);

        $this->store_material_png('diagramm.png', 200, 150);
        update_module_settings::execute($cmid, json_encode([
            'intro' => '<img src="@@PLUGINFILE@@/diagramm.png" alt="Erste Fassung">',
            'introimages' => ['diagramm.png'],
        ]));
        $original = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'intro', 0, '/', 'diagramm.png');
        $this->assertNotFalse($original);
        $originalcontenthash = $original->get_contenthash();

        $this->store_material_png('diagramm.png', 400, 300);
        update_module_settings::execute($cmid, json_encode([
            'intro' => '<img src="@@PLUGINFILE@@/diagramm.png" alt="Zweite Fassung">',
            'introimages' => ['diagramm.png'],
        ]));

        $replaced = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'intro', 0, '/', 'diagramm.png');
        $this->assertNotFalse($replaced);
        $this->assertNotSame($originalcontenthash, $replaced->get_contenthash());

        $fromtrash = \local_kurspilot\activity_file_trash::find_for_restore(
            $modulecontext->id,
            $cmid,
            'diagramm.png',
            $originalcontenthash
        );
        $this->assertNotNull($fromtrash);
        $this->assertSame($originalcontenthash, $fromtrash->get_contenthash());
    }

    /**
     * Abnahmekriterium #399: Drift in einer Aktivitätsart sperrt genau diese
     * fuers Schreiben, mit der Handlungsaufforderung "bitte der
     * Administration melden" - Lesen (get_module_settings) bleibt moeglich.
     */
    public function test_drift_blocks_the_write_but_not_the_read(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
        ]);

        \local_kurspilot\write_gate::all_statuses();
        set_config('driftviolations_page', json_encode(['Spalte "intro" fehlt.']), 'local_kurspilot');

        try {
            update_module_settings::execute($page->cmid, json_encode(['name' => 'Neuer Titel']));
            $this->fail('execute() haette wegen Drift werfen muessen.');
        } catch (\moodle_exception $e) {
            // Die genaue deutsche Formulierung ("bitte der Administration
            // melden") wird in write_gate_test.php gegen das Sprachpaket
            // geprueft (Testinstanz hat kein vollstaendiges de-Sprachpaket,
            // nur lang/de/local_kurspilot.php im Plugin) - hier reicht der
            // sprachunabhaengige Fehlercode.
            $this->assertSame('modnamedriftlocked', $e->errorcode);
        }

        // Lesen bleibt trotz Drift moeglich.
        $this->read($page->cmid);
        $this->addToAssertionCount(1);
    }

    /**
     * Die Hauptdatei einer bestehenden "resource" laesst sich per Patch
     * ersetzen (Spec 0018 §9, Issue #434: die von create_module::Klassendoku
     * benannte Luecke "die KI kann die Hauptdatei einer resource ersetzen").
     * Gleicher Dateiname erneut referenziert -> alte Datei wandert in den
     * Papierkorb statt geloescht zu werden (Spec 0018 §9.1, wie bei assign).
     */
    public function test_resource_files_reference_replaces_main_file_and_trashes_the_old_one(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $resource = $this->getDataGenerator()->get_plugin_generator('mod_resource')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('resource', $resource->id)->id;
        $modulecontext = \context_module::instance($cmid);

        $this->create_material_file('blatt.pdf', 'Erste Fassung');
        update_module_settings::execute($cmid, json_encode(['files' => ['blatt.pdf']]));
        $original = get_file_storage()->get_file($modulecontext->id, 'mod_resource', 'content', 0, '/', 'blatt.pdf');
        $this->assertNotFalse($original);

        $this->create_material_file('blatt.pdf', 'Zweite Fassung');
        update_module_settings::execute($cmid, json_encode(['files' => ['blatt.pdf']]));

        $replaced = get_file_storage()->get_file($modulecontext->id, 'mod_resource', 'content', 0, '/', 'blatt.pdf');
        $this->assertNotFalse($replaced);
        $this->assertSame('Zweite Fassung', $replaced->get_content());

        $trashed = get_file_storage()->get_area_files(
            $modulecontext->id,
            \local_kurspilot\activity_file_trash::COMPONENT,
            \local_kurspilot\activity_file_trash::FILEAREA,
            $cmid,
            'itemid',
            false
        );
        $this->assertNotEmpty($trashed, 'Die ersetzte Hauptdatei muss in den Papierkorb wandern.');
    }

    /**
     * "files" bei "folder" scheitert auf dem Patch-Weg mit einer klaren
     * Meldung statt still wirkungslos zu bleiben (Issue #434):
     * folder_update_instance() (mod/folder/lib.php) liest den Draft-Itemid
     * NICHT aus $data->files, sondern ueber file_get_submitted_draft_itemid()
     * aus $_REQUEST - ein reiner Webservice-Aufruf haette also nie eine
     * Wirkung gehabt, ohne dass Moodle einen Fehler meldet. "Dateien einem
     * folder hinzufuegen" laeuft deshalb ausschliesslich ueber create_module.
     */
    public function test_folder_files_patch_fails_with_clear_message_instead_of_silently_doing_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $folder = $this->getDataGenerator()->get_plugin_generator('mod_folder')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('folder', $folder->id)->id;
        $this->create_material_file('blatt.pdf', 'Inhalt');

        try {
            update_module_settings::execute($cmid, json_encode(['files' => ['blatt.pdf']]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('folderfilespatchunsupported', $e->errorcode);
        }
    }
}
