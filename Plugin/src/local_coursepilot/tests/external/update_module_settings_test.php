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
use local_coursepilot\catalog\registry;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * First write operation (Spec 0015 §3.3, issue #388).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_module_settings::class)]
#[CoversClass(\local_coursepilot\activity_file_trash::class)]
final class update_module_settings_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass} Course, teacher (editingteacher).
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
     * @return array Current state, with the same shape as get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * A single-field patch changes only that field, reports before/after
     * values and preserves concurrent manual edits to other fields.
     * Read-modify-write occurs immediately before writing.
     */
    public function test_patch_changes_named_field_and_survives_concurrent_hand_edit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alter Titel',
        ]);

        // Preserve a concurrent manual edit to another field made immediately
        // before writing (Spec 0015 §3.3).
        $DB->set_field('page', 'intro', 'Handaenderung der Lehrkraft', ['id' => $page->id]);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($page->cmid, json_encode(['name' => 'Neuer Titel']))
        );

        $this->assertCount(1, $result['changes']);
        $this->assertSame('name', $result['changes'][0]['field']);
        $this->assertSame('"Alter Titel"', $result['changes'][0]['before_json']);
        $this->assertSame('"Neuer Titel"', $result['changes'][0]['after_json']);
        $this->assertStringContainsString('Alter Titel', $result['message']);
        $this->assertStringContainsString('Neuer Titel', $result['message']);

        $after = $this->read($page->cmid);
        $this->assertSame('Neuer Titel', $after['name']);
        $this->assertSame('Handaenderung der Lehrkraft', $after['intro']);
    }

    /**
     * Repeating an existing value reports no changes.
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

        $this->assertCount(0, $result['changes']);
    }

    /**
     * Repeating an existing value reports no change in the message itself,
     * not just in changes.
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

        $this->assertStringContainsString('No change', $result['message']);
    }

    /**
     * Pseudofields have no instance-table columns, so ordinary before/after
     * comparison misses them. Do not claim no change when a write occurred (#403).
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

        $this->assertStringNotContainsString('No change', $result['message']);
        $this->assertStringContainsString('assignsubmission_file_enabled', $result['message']);
        $this->assertEquals(1, $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $assign->id,
            'subtype' => 'assignsubmission',
            'plugin' => 'file',
            'name' => 'enabled',
        ]));
    }

    /**
     * Repeated pseudofields live in choice_options; report their values
     * read back after native writing (#564).
     */
    public function test_choice_option_patch_report_uses_persisted_value(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $created = external_api::clean_returnvalue(
            create_module::execute_returns(),
            create_module::execute($course->id, 0, 'choice', json_encode([
                'name' => 'Abstimmung',
                'intro' => 'Bitte waehlen',
                'option' => ['Ja', 'Nein'],
                'allowupdate' => 1,
            ]), \local_coursepilot\material_files::LOCATION_STORE)
        );
        $before = $this->read($created['cmid']);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($created['cmid'], json_encode([
                'option' => ['Vielleicht', 'Auf jeden Fall'],
                'limit' => [4, 5],
                'optionid' => $before['optionid'],
            ]))
        );

        $changes = array_column($result['changes'], 'after_json', 'field');
        $this->assertSame('["Vielleicht","Auf jeden Fall"]', $changes['option']);
        $this->assertSame('["4","5"]', $changes['limit']);
        $this->assertStringNotContainsString('"option" = null', $result['message']);
    }

    /**
     * coursepagevisibility is read vocabulary. Explain its write path
     * instead of reporting an unknown field (#404).
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
     * Reject unknown fields without writes; name the field and point to
     * describe_module_fields.
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
     * Reject fields on the shared denylist.
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
     * Reject values outside the documented range.
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
     * Reject combination-rule violations without partial writes.
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
            // cutoffdate before duedate violates the combination rule.
            update_module_settings::execute($forum->cmid, json_encode(['cutoffdate' => 1000000000]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cutoffdate', $e->getMessage());
            $this->assertStringContainsString('duedate', $e->getMessage());
        }

        $this->assertEquals(0, $this->read($forum->cmid)['cutoffdate']);
    }

    /**
     * Report automatic subscription (forcesubscribe=2) explicitly as a side
     * effect (Spec 0015 §3.3, catalog category 5).
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

        $this->assertNotEmpty($result['side_effects']);
        $this->assertStringContainsString('course participants', $result['side_effects'][0]);
        $this->assertStringContainsString('subscribed', $result['side_effects'][0]);
        $this->assertStringContainsString('course participants', $result['message']);
    }

    /**
     * update_moduleinfo() overwrites intro from introeditor[text].
     * pseudofield_carry_forward::sync_intro_editor_from_patch() prevents
     * intro-only patches from silently vanishing for every FEATURE_MOD_INTRO
     * activity (#433, generalized from assign introimages). Test forum as
     * well as assign.
     */
    public function test_intro_patch_persists_on_a_non_assign_activity(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $forum = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_instance(['course' => $course->id]);

        update_module_settings::execute($forum->cmid, json_encode(['intro' => 'Neue Forumsbeschreibung']));

        $this->assertSame('Neue Forumsbeschreibung', $this->read($forum->cmid)['intro']);
    }

    /**
     * Reject completion fields on the course_modules completion* denylist.
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
     * Activity types with dedicated writers, such as update_quiz_settings,
     * cannot use this generic writer.
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
     * Reject unsupported activity types with the describe_module_fields message.
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
     * Clearly reject writes without native editing capability; read-only
     * Coursepilot access remains available (Spec 0015 §3.3).
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

        // Non-editing teacher has local/coursepilot:use but lacks moodle/course:manageactivities.
        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        // Read access remains available.
        $this->assertSame('Fremder Kurs', $this->read($page->cmid)['name']);

        $this->expectException(\required_capability_exception::class);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Uebernommen']));
    }

    /**
     * Writing creates a history version through the course_module_updated
     * observer (#385–387).
     */
    public function test_write_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        // course_module_created already recorded version 1 (#385); this write
        // must add another version.
        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]);

        update_module_settings::execute($page->cmid, json_encode(['name' => 'Verlauf-Test']));

        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]));
    }

    /**
     * Use only update_moduleinfo(), never direct instance-table writes
     * (ADR 0016).
     */
    public function test_source_never_writes_the_instance_table_directly(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/catalog/write_target.php');
        $this->assertStringNotContainsString('$DB->update_record', $source);
        $this->assertStringNotContainsString('$DB->insert_record', $source);
        $this->assertStringContainsString('update_moduleinfo(', $source);
    }

    /**
     * Hide (visible=0) and show (visible=1) activities (#390, criterion 1).
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
     * Stealth (visibleoncoursepage=0) works for all eight catalog types using
     * this writer (#390, criterion 2). Quiz has its own writer. Read vocabulary
     * coursepagevisibility becomes stealth, matching get_module_settings
     * and get_course_catalog.
     */
    public function test_stealth_visibility_works_for_all_eight_activity_types(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 1);
        [$course] = $this->course_with_editing_teacher();

        $modnames = registry::known_modnames();
        $this->assertContains('quiz', $modnames, 'quiz must remain cataloged (own write path).');
        $modnamesviaupdatemodulesettings = array_values(array_diff($modnames, ['quiz']));
        $this->assertCount(8, $modnamesviaupdatemodulesettings, 'Expected eight activity types via this vehicle.');

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
            $this->assertStringContainsString('visibleoncoursepage', $result['message'], "modname={$modname}");
        }
    }

    /**
     * Disabling allowstealth rejects stealth patches without writes
     * (#390, criterion 3).
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
     * With allowstealth off, hiding via visible and restoring
     * visibleoncoursepage=1 remain allowed; only target value 0 is blocked.
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
     * Set groupmode and groupingid with localized before/after reports
     * (#390, criteria 4/8).
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

        // Localized before/after reports include values as well as field names
        // (#390, criterion 8).
        $this->assertCount(2, $result['changes']);
        $bygroupmode = array_values(array_filter($result['changes'], fn($c) => $c['field'] === 'groupmode'))[0];
        $this->assertSame('0', $bygroupmode['before_json']);
        $this->assertSame((string) SEPARATEGROUPS, $bygroupmode['after_json']);
        $this->assertStringContainsString('groupmode', $result['message']);
        $this->assertStringContainsString('groupingid', $result['message']);
        $this->assertStringContainsString((string) $grouping->id, $result['message']);
    }

    /**
     * Set idnumber (#390, criterion 5).
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

        // Localized before/after state (#390, criterion 8).
        $this->assertCount(1, $result['changes']);
        $this->assertSame('idnumber', $result['changes'][0]['field']);
        $this->assertSame('""', $result['changes'][0]['before_json']);
        $this->assertSame('"kp-390"', $result['changes'][0]['after_json']);
        $this->assertStringContainsString('kp-390', $result['message']);
    }

    /**
     * Never use set_coursemodule_groupmode(), deprecated in Moodle 5.2.
     * Set group mode only through update_moduleinfo() (#390, criterion 4).
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
     * Create a material file for the current user in upload_material_file’s
     * storage location (#428).
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
     * Set up fake external material storage for an already enrolled teacher
     * (#496). Unlike webdav_instance_fixture::set_up_external_material(),
     * which creates its own user, this keeps the same user’s course write rights.
     *
     * @param \stdClass $teacher
     * @return \local_coursepilot\tests\webdav\fake_webdav_transport
     */
    private function set_up_external_material_for(\stdClass $teacher): \local_coursepilot\tests\webdav\fake_webdav_transport {
        $this->grant_webdav_capability($teacher);
        $instanceid = $this->create_webdav_instance($teacher);
        $this->write_v2_pointer($teacher, 'material_store', $instanceid, 'Material');

        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        return $fake;
    }

    /**
     * Embed from external inventory through fake WebDAV and a draft into
     * the activity, without using the workbench (#496, Spec #486 §7).
     */
    public function test_introattachments_reference_attaches_material_file_from_external_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/arbeitsblatt.pdf', 'Arbeitsblattinhalt');

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode(['introattachments' => ['arbeitsblatt.pdf']]))
        );

        $this->assertStringContainsString('introattachments', $result['message']);
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
     * Explicit location = workbench still uses the workbench when inventory
     * is external, matching material readers (#495/#496).
     */
    public function test_introattachments_reference_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/nur-extern.pdf', 'external');
        $this->create_material_file('werkbankdatei.pdf', 'aus der Werkbank');

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute(
                $cmid,
                json_encode(['introattachments' => ['werkbankdatei.pdf']]),
                \local_coursepilot\material_files::LOCATION_WORKBENCH
            )
        );

        $this->assertStringContainsString('introattachments', $result['message']);
        $modulecontext = \context_module::instance($cmid);
        $attached = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'werkbankdatei.pdf');
        $this->assertNotFalse($attached);
        $this->assertSame('aus der Werkbank', $attached->get_content());
    }

    /**
     * Embedding also rejects context_area paths, preventing an alternate
     * access path bypassing personal-data checks (#495/#496, Spec #486 §2/§7).
     */
    public function test_introattachments_reference_under_kontextbereich_is_rejected(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        \local_coursepilot\storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot-material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \local_coursepilot\material_files::own_context()->id,
            'component' => \local_coursepilot\material_files::COMPONENT,
            'filearea' => \local_coursepilot\material_files::FILEAREA,
            'itemid' => \local_coursepilot\material_files::ITEMID,
            'filepath' => '/coursepilot-material/kontext/',
            'filename' => 'plan.md',
        ], '# Plan');

        try {
            update_module_settings::execute($cmid, json_encode(['introattachments' => ['kontext/plan.md']]));
            $this->fail('A path under the context area should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiscontext', $e->errorcode);
        }
    }

    /**
     * Reference material paths as assignment additional-file attachments
     * (Spec 0018 §4.2/§7, #429). The Spec 0015 §4.3 file restriction no
     * longer applies to assign.
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

        $this->assertStringContainsString('introattachments', $result['message']);
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
     * A second reference appends rather than replacing the first attachment
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
     * Missing material files produce an error naming the expected path (#429).
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
     * A failed write, such as another field’s combination-rule violation,
     * preserves the material file for retry without another upload
     * (Spec 0018 §4.2, #429).
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
            // cutoffdate before duedate violates the combination rule;
            // validate_patch() fails before any material access.
            update_module_settings::execute($cmid, json_encode([
                'introattachments' => ['arbeitsblatt.pdf'],
                'cutoffdate' => 1000000000,
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('cutoffdate', $e->getMessage());
        }

        $material = get_file_storage()->get_file(
            \local_coursepilot\material_files::own_context()->id,
            \local_coursepilot\material_files::COMPONENT,
            \local_coursepilot\material_files::FILEAREA,
            \local_coursepilot\material_files::ITEMID,
            '/coursepilot-material/',
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
     * Fail during resolve_into_draft(), after copying the first reference
     * but before resolving the missing second file. Never reach
     * update_moduleinfo(); preserve materials and attach nothing (#429).
     * See {@see self::test_failed_attach_leaves_material_file_untouched()}.
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
            \local_coursepilot\material_files::own_context()->id,
            \local_coursepilot\material_files::COMPONENT,
            \local_coursepilot\material_files::FILEAREA,
            \local_coursepilot\material_files::ITEMID,
            '/coursepilot-material/',
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
     * Replacing an activity file with the same filename moves the old
     * version to trash with its original contenthash rather than deleting
     * it (Spec 0018 §9.1, #432).
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

        $fromtrash = \local_coursepilot\activity_file_trash::find_for_restore(
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
     * introattachment files no longer count as history gaps because
     * trash provides actual restoration (#432, Spec 0018 §9.1).
     */
    public function test_introattachment_files_are_not_marked_as_a_gap_in_the_history(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(['course' => $course->id]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->create_material_file('blatt.pdf', 'Inhalt');

        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));

        $latest = max(array_column(\local_coursepilot\history\version_history::list_versions($cmid)['versions'], 'version'));
        $files = \local_coursepilot\history\version_history::files_at($cmid, $latest);
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

        $filerecord = \local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            '/coursepilot-material/',
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
        \local_coursepilot\material_files::replace($existing ?: null, $filerecord, $png);
    }

    /**
     * Embed a material image inside assignment intro HTML rather than as
     * a separate attachment (Spec 0018 §4.2/§5, #433). Supply alt text in
     * the patch as part of the AI quality routine.
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

        $this->assertStringContainsString('intro', $result['message']);
        $after = $this->read($cmid);
        // Moodle stores @@PLUGINFILE@@ in intro (lib/filelib.php:1103) and
        // format_text() resolves pluginfile.php URLs on display. Prove embedding
        // with the placeholder and physical file, not a rendered URL.
        $this->assertStringContainsString('alt="Saeulendiagramm der Messreihe"', $after['intro']);
        $this->assertStringContainsString('@@PLUGINFILE@@/diagramm.png', $after['intro']);

        $modulecontext = \context_module::instance($cmid);
        $embedded = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'intro', 0, '/', 'diagramm.png');
        $this->assertNotFalse($embedded);
    }

    /**
     * Exercise the complete Spec 0018 §5 path: crop material (#431), save
     * the crop as separate material and embed it without uploading again (#433).
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
     * Reject images outside the embedding whitelist with the upload error
     * message, using a narrower whitelist (Spec 0018 §6, #433).
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
     * Missing image references name the expected path, as for
     * introattachments (#429).
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
     * History records embedded images like other changes: intro appears
     * in the diff and the file appears in the version’s file inventory (#433).
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

        $this->assertNotEmpty(array_filter($result['changes'], static fn($c): bool => $c['field'] === 'intro'));

        $latest = max(array_column(\local_coursepilot\history\version_history::list_versions($cmid)['versions'], 'version'));
        $files = \local_coursepilot\history\version_history::files_at($cmid, $latest);
        $embedded = array_values(array_filter(
            $files,
            static fn($f): bool => $f->component === 'mod_assign' && $f->filearea === 'intro' && $f->filename === 'diagramm.png'
        ));
        $this->assertNotEmpty($embedded);
    }

    /**
     * Re-embedding the same filename moves the old intro file to trash
     * instead of losing it, matching introattachments (Spec 0018 §9.1).
     * See {@see self::test_replacing_introattachment_trashes_the_old_file_with_the_same_contenthash()}.
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

        $fromtrash = \local_coursepilot\activity_file_trash::find_for_restore(
            $modulecontext->id,
            $cmid,
            'diagramm.png',
            $originalcontenthash
        );
        $this->assertNotNull($fromtrash);
        $this->assertSame($originalcontenthash, $fromtrash->get_contenthash());
    }

    /**
     * Catalog drift blocks only that activity type for writing and advises
     * contacting administration; get_module_settings still reads (#399).
     */
    public function test_drift_blocks_the_write_but_not_the_read(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
        ]);

        \local_coursepilot\write_gate::all_statuses();
        set_config('driftviolations_page', json_encode(['Spalte "intro" fehlt.']), 'local_coursepilot');

        try {
            update_module_settings::execute($page->cmid, json_encode(['name' => 'Neuer Titel']));
            $this->fail('execute() should have thrown because of drift.');
        } catch (\moodle_exception $e) {
            // write_gate_test.php checks the exact language-pack wording. The test
            // instance has only plugin German strings, not a full German pack;
            // a language-independent error code suffices here.
            $this->assertSame('modnamedriftlocked', $e->errorcode);
        }

        // Read access remains available despite drift.
        $this->read($page->cmid);
        $this->addToAssertionCount(1);
    }

    /**
     * Patch a resource’s main file (#434, Spec 0018 §9). Referencing the
     * same filename moves the old content to trash rather than deleting it,
     * as with assign (Spec 0018 §9.1).
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
            \local_coursepilot\activity_file_trash::COMPONENT,
            \local_coursepilot\activity_file_trash::FILEAREA,
            $cmid,
            'itemid',
            false
        );
        $this->assertNotEmpty($trashed, 'The replaced main file must move to the trash.');
    }

    /**
     * Reject folder files patches clearly (#434). folder_update_instance()
     * reads its draft item ID from $_REQUEST through
     * file_get_submitted_draft_itemid(), not data->files, so a pure webservice
     * patch silently did nothing. Adding folder files uses create_module only.
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

    /**
     * Reject attemptreopenmethod=manual without confirmation and allow it
     * with confirmation. Repeating an unchanged restriction needs no new
     * confirmation (#583).
     */
    public function test_assign_manual_reopen_patch_needs_confirmation_once(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $patch = json_encode(['attemptreopenmethod' => 'manual']);

        try {
            update_module_settings::execute($assign->cmid, $patch);
            $this->fail('attemptreopenmethod=manual should have required confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
        }

        update_module_settings::execute($assign->cmid, $patch, \local_coursepilot\material_files::LOCATION_STORE,
            ['attemptreopenmethod']);
        $settings = json_decode(get_module_settings::execute($assign->cmid)['settings_json'], true);
        $this->assertSame('manual', $settings['attemptreopenmethod']);

        update_module_settings::execute($assign->cmid, json_encode(['attemptreopenmethod' => 'manual', 'name' => 'Neu']));
    }
}
