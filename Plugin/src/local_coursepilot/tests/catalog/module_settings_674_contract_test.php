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

namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The generic settings write reports refusals, changes and side effects exactly (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(module_settings::class)]
final class module_settings_674_contract_test extends \advanced_testcase {
    /**
     * Signs in an editing teacher of a new course.
     *
     * @return \stdClass The course.
     */
    private function course_with_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Creates an activity in the course and returns its course module id.
     *
     * @param string $modname
     * @param \stdClass $course
     * @param mixed[] $record
     * @return int
     */
    private function activity(string $modname, \stdClass $course, array $record = []): int {
        return (int) $this->getDataGenerator()->create_module($modname, ['course' => $course->id] + $record)->cmid;
    }

    /**
     * Runs a write that must be refused and returns the refusal.
     *
     * @param int $cmid
     * @param string $patch
     * @return \moodle_exception
     */
    private function refusal(int $cmid, string $patch): \moodle_exception {
        try {
            module_settings::write($cmid, $patch);
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('The write must be refused.');
    }

    /**
     * A user outside the course is stopped by the context check before any permission check.
     */
    public function test_a_user_outside_the_course_is_stopped_by_the_context_check(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        module_settings::write($cmid, '{"name":"x"}');
    }

    /**
     * Capabilities the settings write demands.
     *
     * @return array<string, array{string}>
     */
    public static function required_capabilities(): array {
        return [
            'Coursepilot use' => ['local/coursepilot:use'],
            'native activity management' => ['moodle/course:manageactivities'],
        ];
    }

    /**
     * Refuses a teacher who lacks either capability before the patch is parsed.
     *
     * @param string $capability
     */
    #[DataProvider('required_capabilities')]
    public function test_each_required_capability_is_enforced_before_the_patch_is_parsed(string $capability): void {
        global $DB;
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_module::instance($cmid)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        try {
            // Invalid JSON proves the capability is checked before the patch is parsed.
            module_settings::write($cmid, 'not json');
            $this->fail('The missing capability must stop the write.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($capability), $e->a);
        }
    }

    /**
     * Patches that are valid JSON but not an object.
     *
     * @return array<string, array{string}>
     */
    public static function non_object_patches(): array {
        return ['number' => ['5'], 'string' => ['"text"'], 'null' => ['null'], 'boolean' => ['true']];
    }

    /**
     * Valid JSON that is no object is refused as an invalid patch.
     *
     * @param string $patch
     */
    #[DataProvider('non_object_patches')]
    public function test_a_json_patch_that_is_no_object_is_refused(string $patch): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame('invalidpatchjson', $this->refusal($cmid, $patch)->errorcode);
    }

    /**
     * An activity with a dedicated tool is refused with its name and the tool to use.
     */
    public function test_an_activity_with_a_dedicated_write_tool_is_refused_with_the_tool(): void {
        $this->assertSame(
            ['modname' => 'quiz', 'write_route' => 'update_quiz_settings'],
            $this->refusal_of_catalog('quiz')->a
        );
    }

    /**
     * Runs catalog_for() for an activity type that must be refused.
     *
     * @param string $modname
     * @return \moodle_exception
     */
    private function refusal_of_catalog(string $modname): \moodle_exception {
        try {
            module_settings::catalog_for($modname);
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('The activity type must be refused.');
    }

    /**
     * Reports old and new value as readable JSON without escaping unicode or slashes.
     */
    public function test_changes_keep_unicode_and_slashes_readable(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher(), ['name' => 'Ä/Ö']);

        $this->assertSame(
            [['field' => 'name', 'before_json' => '"Ä/Ö"', 'after_json' => '"ß/x"']],
            module_settings::write($cmid, json_encode(['name' => 'ß/x'], JSON_UNESCAPED_UNICODE))['changes']
        );
    }

    /**
     * The message lists the changed field before the pseudofield that cannot be compared.
     */
    public function test_the_message_joins_changes_and_written_pseudofields(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('assign', $this->course_with_teacher(), ['name' => 'Old']);

        $result = module_settings::write($cmid, '{"name":"New","assignsubmission_file_enabled":1}');

        $this->assertSame(
            get_string('modulechangedfields', 'local_coursepilot', '"name" from "Old" to "New"')
                . ' '
                . get_string(
                    'modulepseudofieldswritten',
                    'local_coursepilot',
                    '"assignsubmission_file_enabled" = 1'
                ),
            $result['message']
        );
    }

    /**
     * Written pseudofield values appear in the message as readable JSON.
     */
    public function test_the_message_shows_pseudofield_values_without_escaping_unicode_or_slashes(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $result = module_settings::write($cmid, json_encode(['page' => 'ä/b'], JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('"page" = {"text":"ä/b"', $result['message']);
    }

    /**
     * A triggered side effect follows the change message after one space.
     */
    public function test_the_message_appends_the_side_effect_after_the_changes(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('forum', $this->course_with_teacher());

        $result = module_settings::write($cmid, '{"forcesubscribe":2}');

        $this->assertSame(
            get_string('modulechangedfields', 'local_coursepilot', '"forcesubscribe" from "0" to "2"')
                . ' All course participants have been subscribed to this forum.',
            $result['message']
        );
    }

    /**
     * Restoring a file draft keeps the submission types of an assignment.
     */
    public function test_restoring_a_file_draft_keeps_the_submission_types(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $course = $this->course_with_teacher();
        $created = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 0,
        ]);
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'restored.txt',
        ], 'restored');

        module_settings::write_pseudofield_draft((int) $created->cmid, 'introattachments', $draftid);

        $this->assertSame('1', $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => $created->id, 'plugin' => 'file', 'subtype' => 'assignsubmission', 'name' => 'enabled',
        ]));
    }
}
