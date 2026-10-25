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


namespace local_coursepilot;

use core_external\external_api;
use local_coursepilot\admin\pointer_scan;
use local_coursepilot\external\append_context_file;
use local_coursepilot\external\export_activity_backup;
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact pointer scan states, backup export guards, append report and pending-write translation.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pointer_scan::class)]
#[CoversClass(export_activity_backup::class)]
#[CoversClass(append_context_file::class)]
#[CoversClass(pending_write_translation::class)]
final class admin_pointer_tools_contract_test extends \advanced_testcase {
    use caught_throwable;
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Stores raw text as a file in the anchor folder of a user.
     *
     * @param \stdClass $user
     * @param string $filename
     * @param string $content
     */
    private function anchor_file(\stdClass $user, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => $filename,
        ], $content);
    }

    public function test_only_users_with_a_non_empty_pointer_file_are_listed_and_only_objects_are_read(): void {
        $this->resetAfterTest();
        $with = $this->getDataGenerator()->create_user();
        $list = $this->getDataGenerator()->create_user();
        $empty = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $this->anchor_file($with, '.coursepilot-location.json', '{"context_area":{"location":"moodle","path":"A"}}');
        $this->anchor_file($list, '.coursepilot-location.json', '[1,2]');
        $this->anchor_file($empty, '.coursepilot-location.json', '');

        $ids = pointer_scan::userids_with_pointer();
        sort($ids);

        $this->assertSame([(int) $with->id, (int) $list->id], $ids);
        $this->assertSame(
            ['context_area' => ['location' => 'moodle', 'path' => 'A']],
            pointer_scan::raw_pointer_for((int) $with->id)
        );
        $this->assertNull(pointer_scan::raw_pointer_for((int) $list->id));
        $this->assertNull(pointer_scan::raw_pointer_for((int) $empty->id));
    }

    public function test_open_notes_and_external_targets_are_recognised_from_the_raw_documents(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = (int) $user->id;
        $this->assertFalse(pointer_scan::has_open_pending($id));

        $this->anchor_file($user, '.coursepilot-pending.json', '[1]');
        $this->assertFalse(pointer_scan::has_open_pending($id));
        $this->resetAfterTest();
        $other = $this->getDataGenerator()->create_user();
        $this->anchor_file($other, '.coursepilot-pending.json', '{"abc":{"path":"a.md"}}');
        $this->assertTrue(pointer_scan::has_open_pending((int) $other->id));
        $scalar = $this->getDataGenerator()->create_user();
        $this->anchor_file($scalar, '.coursepilot-pending.json', '5');
        $this->assertFalse(pointer_scan::has_open_pending((int) $scalar->id));

        $this->assertTrue(pointer_scan::has_open_previous_location(['previous_location' => ['location' => 'moodle']]));
        $this->assertFalse(pointer_scan::has_open_previous_location(['previous_location' => 'x']));
        $this->assertFalse(pointer_scan::has_open_previous_location(['other' => []]));
        $this->assertFalse(pointer_scan::has_open_previous_location(null));
        $this->assertTrue(pointer_scan::has_external_target(['material_store' => ['location' => 'external']]));
        $this->assertTrue(pointer_scan::has_external_target(['context_area' => ['location' => 'external']]));
        $this->assertFalse(
            pointer_scan::has_external_target(['context_area' => ['location' => 'moodle'], 'material_store' => 'external'])
        );
        $this->assertFalse(pointer_scan::has_external_target(null));
        $this->assertTrue((new \ReflectionMethod(pointer_scan::class, 'has_external_target'))->isPublic());
    }

    public function test_external_targets_are_found_per_user_and_each_state_has_its_defect(): void {
        global $USER;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $ownedinstance = $this->create_webdav_instance($user);
        $foreigninstance = $this->create_webdav_instance($other);
        $httpinstance = $this->create_webdav_instance($user, ['webdav_type' => 0]);
        $fingerprint = ['server' => 'cloud.example.test', 'basepath' => '', 'account' => 'a'];
        $target = static fn(int $instance, string $path): array => [
            'location' => 'external', 'instanceid' => $instance, 'path' => $path, 'fingerprint' => $fingerprint,
        ];
        $id = (int) $USER->id;

        $states = [];
        $cases = [
            [$ownedinstance, null],
            [$foreigninstance, 'foreign_instance'],
            [$httpinstance, 'http'],
            [987654, 'instance_missing'],
        ];
        foreach ($cases as [$instance, $defect]) {
            $decoded = ['context_area' => $target($instance, 'A'), 'material_store' => ['location' => 'moodle', 'path' => 'M']];
            $state = pointer_scan::target_state($id, $decoded, 'context_area');
            $this->assertSame(['state' => 'external', 'host' => 'cloud.example.test', 'defect' => $defect], $state);
            $states[] = $state;
        }
        $this->assertSame(
            ['state' => 'open', 'host' => null, 'defect' => null],
            pointer_scan::target_state($id, null, 'context_area')
        );
        $this->assertSame(
            ['state' => 'moodle', 'host' => null, 'defect' => null],
            pointer_scan::target_state(
                $id,
                [
                    'context_area' => ['location' => 'moodle', 'path' => 'A'],
                    'material_store' => ['location' => 'moodle', 'path' => 'M'],
                ],
                'context_area'
            )
        );
        $this->assertSame(
            ['state' => 'broken', 'host' => null, 'defect' => 'invalid'],
            pointer_scan::target_state($id, ['context_area' => 'x'], 'context_area')
        );
        $this->anchor_file(
            $user,
            '.coursepilot-location.json',
            json_encode(
                ['context_area' => $target($ownedinstance, 'A'), 'material_store' => ['location' => 'moodle', 'path' => 'M']]
            )
        );
        $this->anchor_file(
            $other,
            '.coursepilot-location.json',
            json_encode(
                [
                    'context_area' => ['location' => 'moodle', 'path' => 'A'],
                    'material_store' => ['location' => 'moodle', 'path' => 'M'],
                ]
            )
        );
        $this->assertSame([$id], pointer_scan::userids_with_external_target());
        $this->assertCount(4, $states);
    }

    /**
     * Teacher in a course with a page.
     *
     * @return \stdClass Page module.
     */
    private function teacher_page(): \stdClass {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Decoy']);
        return $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Target']);
    }

    public function test_the_backup_export_returns_typed_values_and_checks_context_and_rights_first(): void {
        global $DB;
        $page = $this->teacher_page();

        $result = export_activity_backup::execute((int) $page->cmid);

        $this->assertSame(['cmid', 'modname', 'xml'], array_keys($result));
        $this->assertSame((int) $page->cmid, $result['cmid']);
        $this->assertSame('page', $result['modname']);
        $this->assertStringContainsString('modulename="page"', $result['xml']);

        external_api::set_context_restriction(\context_course::instance(SITEID));
        try {
            $restricted = $this->caught(fn() => export_activity_backup::execute((int) $page->cmid));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/backup:backupactivity'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => export_activity_backup::execute((int) $page->cmid));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_the_append_message_reports_creation_size_and_the_rotation_hint_only_beyond_the_limit(): void {
        $build = new \ReflectionMethod(append_context_file::class, 'build_response');
        $limit = context_files::MAX_WRITE_BYTES;

        $created = $build->invoke(null, ['path' => 'a.md', 'created' => true, 'size' => 5]);
        $appended = $build->invoke(null, ['path' => 'a.md', 'created' => false, 'size' => 9]);
        $atlimit = $build->invoke(null, ['path' => 'a.md', 'created' => false, 'size' => $limit]);
        $beyond = $build->invoke(null, ['path' => 'a.md', 'created' => false, 'size' => $limit + 1]);

        $this->assertSame(get_string('contextfilecreated', 'local_coursepilot', 'a.md'), $created['message']);
        $this->assertSame(
            get_string('contextfileappended', 'local_coursepilot', (object) ['path' => 'a.md', 'size' => 9]),
            $appended['message']
        );
        $this->assertSame(['path', 'created', 'size', 'message'], array_keys($appended));
        $this->assertSame(
            get_string('contextfileappended', 'local_coursepilot', (object) ['path' => 'a.md', 'size' => $limit]),
            $atlimit['message']
        );
        $this->assertSame(
            get_string('contextfileappended', 'local_coursepilot', (object) ['path' => 'a.md', 'size' => $limit + 1])
                . ' ' . get_string('contextfilerotation', 'local_coursepilot'),
            $beyond['message']
        );
    }

    public function test_appending_checks_the_context_first_and_records_a_failed_external_write_without_a_course(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $other = $this->getDataGenerator()->create_course();
        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => append_context_file::execute('a.md', 'x'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $this->grant_webdav_capability($USER);
        $instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
        $this->write_v2_pointer($USER, 'context_area', $instanceid, 'Ctx', [
            'server' => $this->fixtureserver, 'basepath' => '', 'account' => $this->fixturekonto,
        ]);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/Ctx');
        $fake->deny_auth(401);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        $e = $this->caught(fn() => append_context_file::execute('a.md', 'x'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pendingwritefailed', $e->errorcode);
        $this->assertSame(0, pending_write_notice::list_grouped()[0]['entries'][0]['course_id']);
    }

    public function test_operation_labels_are_public_and_per_operation(): void {
        $this->resetAfterTest();
        $label = new \ReflectionMethod(pending_write_translation::class, 'operation_label');

        $this->assertTrue($label->isPublic());
        $operations = [
            pending_write_translation::OP_CREATE,
            pending_write_translation::OP_OVERWRITE,
            pending_write_translation::OP_APPEND,
            pending_write_translation::OP_UNKNOWN,
        ];
        foreach ($operations as $operation) {
            $this->assertSame(
                get_string('pendingoperation' . $operation, 'local_coursepilot'),
                $label->invoke(null, $operation)
            );
        }
    }

    public function test_a_full_quota_for_the_note_is_reported_with_path_and_operation_label_only(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1;

        $e = pending_write_translation::record_and_translate(
            'storage_full',
            'log',
            'dir/a.md',
            pending_write_translation::OP_CREATE,
            'reason',
            'target',
            0
        );

        $this->assertSame('pendingnotewritefailed', $e->errorcode);
        $this->assertSame(
            [
                'path' => 'dir/a.md',
                'operation' => pending_write_translation::operation_label(pending_write_translation::OP_CREATE),
            ],
            (array) $e->a
        );
    }
}
