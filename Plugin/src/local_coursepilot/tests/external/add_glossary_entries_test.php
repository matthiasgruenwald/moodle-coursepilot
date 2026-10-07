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
use local_coursepilot\dispatcher;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Registered glossary entry write contract (#593).
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(add_glossary_entries::class)]
#[CoversClass(\local_coursepilot\glossary_entry_writer::class)]
final class add_glossary_entries_test extends \advanced_testcase {
    /** The registered External path applies defaults and validates returns. */
    private function call(int $cmid, array $entries): array {
        global $USER;
        $USER->ignoresesskey = true;
        $response = external_api::call_external_function('local_coursepilot_add_glossary_entries', [
            'entries' => $entries, 'cmid' => $cmid,
        ]);
        $this->assertFalse($response['error'], json_encode($response));
        return $response['data'];
    }

    public function test_registered_tool_adds_three_entries_and_extends_existing_glossary(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id, 'usedynalink' => 1,
        ]);
        $entries = array_map(static fn($concept) => [
            'concept' => $concept, 'definition' => '<p>A definition</p>',
            'aliases' => [$concept . ' alias'], 'categories' => ['Science'],
            'usedynalink' => true, 'casesensitive' => true, 'fullmatch' => true,
        ], ['Atom', 'Cell', 'Energy']);
        $result = $this->call($glossary->cmid, $entries);
        $this->assertSame([true, true, true], array_column($result['entries'], 'success'), json_encode($result));
        foreach ($result['entries'] as $row) {
            $entry = $DB->get_record('glossary_entries', ['id' => $row['entryid']], '*', MUST_EXIST);
            $this->assertSame('1', $entry->usedynalink);
            $this->assertSame('1', $entry->casesensitive);
            $this->assertSame('1', $entry->fullmatch);
            $this->assertTrue($DB->record_exists('glossary_alias', ['entryid' => $entry->id]));
            $this->assertTrue($DB->record_exists('glossary_entries_categories', ['entryid' => $entry->id]));
        }
        $this->assertSame(1, $DB->count_records('glossary_categories', ['glossaryid' => $glossary->id]));
        $later = $this->call($glossary->cmid, [['concept' => 'Force', 'definition' => 'A push or pull']]);
        $this->assertTrue($later['entries'][0]['success']);
        $this->assertSame(4, $DB->count_records('glossary_entries', ['glossaryid' => $glossary->id]));
    }
    public function test_material_attachments_and_embedded_files_are_copied_without_content_in_response(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        upload_material_file::execute('worksheet.pdf', base64_encode('private file payload'));
        upload_material_file::execute('diagram.svg', base64_encode('<svg></svg>'));
        $result = $this->call($glossary->cmid, [[
            'concept' => 'Atom', 'definition' => '<img src="@@PLUGINFILE@@/diagram.svg" alt="Atom">',
            'attachment_files' => ['worksheet.pdf'], 'definition_files' => ['diagram.svg'],
            'location' => 'workbench',
        ]]);
        $this->assertTrue($result['entries'][0]['success']);
        $id = $result['entries'][0]['entryid'];
        $context = \context_module::instance($glossary->cmid);
        $fs = get_file_storage();
        $attachment = $fs->get_file($context->id, 'mod_glossary', 'attachment', $id, '/', 'worksheet.pdf');
        $this->assertNotFalse($attachment);
        $this->assertSame('private file payload', $attachment->get_content());
        $this->assertNotFalse($fs->get_file($context->id, 'mod_glossary', 'entry', $id, '/', 'diagram.svg'));
        $this->assertStringContainsString('@@PLUGINFILE@@/diagram.svg', $DB->get_field('glossary_entries', 'definition', ['id' => $id]));
        $this->assertStringNotContainsString('private file payload', json_encode($result));
        $this->assertNotNull(\local_coursepilot\material_files::read_content_for_location('workbench', 'worksheet.pdf'));
    }

    public function test_explicit_approval_and_tags_follow_teacher_permissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $result = $this->call($glossary->cmid, [[
            'concept' => 'Draft', 'definition' => 'Review me', 'approved' => false, 'tags' => ['science'],
        ]]);
        $this->assertTrue($result['entries'][0]['success']);
        $this->assertFalse($result['entries'][0]['approved']);
        $id = $result['entries'][0]['entryid'];
        $this->assertSame('0', $DB->get_field('glossary_entries', 'approved', ['id' => $id]));
        $this->assertContains('science', \core_tag_tag::get_item_tags_array('mod_glossary', 'glossary_entries', $id));
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('mod/glossary:approve', CAP_PROHIBIT, $roleid, \context_module::instance($glossary->cmid)->id);
        $this->setUser($teacher);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'Forbidden', 'definition' => 'Cannot approve', 'approved' => true],
            ['concept' => 'Allowed', 'definition' => 'Core default'],
        ]);
        $this->assertSame([false, true], array_column($result['entries'], 'success'), json_encode($result));
        $this->assertSame('nopermissions', $result['entries'][0]['errorcode']);
        $this->assertSame(2, $DB->count_records('glossary_entries', ['glossaryid' => $glossary->id]));
    }

    public function test_entry_errors_preserve_successes_and_roll_back_failed_categories_and_files(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id, 'allowduplicatedentries' => 0,
        ]);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'Atom', 'definition' => 'First'],
            ['concept' => ' atom ', 'definition' => 'Duplicate'],
            ['concept' => 'Bad alias', 'definition' => 'Invalid', 'aliases' => ['$']],
            ['concept' => 'Bad format', 'definition' => 'Invalid', 'definitionformat' => 999],
            ['concept' => 'Missing', 'definition' => 'Invalid', 'categories' => ['Rollback'],
                'attachment_files' => ['missing.pdf']],
            ['concept' => 'Last', 'definition' => 'Final'],
        ]);
        $this->assertSame([true, false, false, false, false, true], array_column($result['entries'], 'success'), json_encode($result));
        $this->assertSame('errconceptalreadyexists', $result['entries'][1]['errorcode']);
        $this->assertSame('errreservedkeywords', $result['entries'][2]['errorcode']);
        $this->assertSame('glossaryentryformat', $result['entries'][3]['errorcode']);
        $this->assertSame('materialfilenotfound', $result['entries'][4]['errorcode']);
        $this->assertSame(2, $DB->count_records('glossary_entries', ['glossaryid' => $glossary->id]));
        $this->assertFalse($DB->record_exists('glossary_categories', ['name' => 'Rollback']));
        $DB->set_field('glossary', 'allowduplicatedentries', 1, ['id' => $glossary->id]);
        $duplicate = $this->call($glossary->cmid, [['concept' => 'Atom', 'definition' => 'Allowed duplicate']]);
        $this->assertTrue($duplicate['entries'][0]['success']);
    }

    public function test_dispatcher_exposes_and_invokes_registered_tool_as_teacher_without_learner_content(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $this->getDataGenerator()->role_assign($roleid, $teacher->id, \context_system::instance()->id);
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content($glossary, [
            'userid' => $student->id, 'concept' => 'Private learner term', 'definition' => 'Private learner definition',
        ]);
        $token = 'synthetic-glossary-token';
        $grant = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $teacher->id, 'clientid' => 'glossary-test', 'revoked' => 0,
            'statehash' => hash('sha256', 'synthetic-state'), 'timecreated' => time(),
        ]);
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'accesstokenhash' => hash('sha256', $token), 'refreshtokenhash' => hash('sha256', 'synthetic-refresh'),
            'clientid' => 'glossary-test', 'userid' => $teacher->id, 'expires' => time() + 3600,
            'refreshexpires' => time() + 7200, 'revoked' => 0, 'timecreated' => time(), 'connectionid' => $grant,
        ]);
        $headers = ['origin' => null, 'pathinfo' => '', 'method' => 'POST'];
        $listed = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $headers);
        $this->assertContains('coursepilot_add_glossary_entries', array_column($listed['body']['result']['tools'], 'name'));
        $response = dispatcher::handle(['id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'coursepilot_add_glossary_entries', 'arguments' => [
                'entries' => [['concept' => 'Teacher term', 'definition' => 'Teacher definition']], 'cmid' => $glossary->cmid,
            ],
        ]], $token, $headers);
        $this->assertFalse($response['body']['result']['isError'] ?? false, json_encode($response));
        $this->assertTrue($response['body']['result']['structuredContent']['entries'][0]['success']);
        $this->assertStringNotContainsString('Private learner', json_encode($response));
        $createdid = $response['body']['result']['structuredContent']['entries'][0]['entryid'];
        $this->assertEquals($teacher->id, $DB->get_field('glossary_entries', 'userid', ['id' => $createdid]));
        $this->assertSame([], \local_coursepilot\privacy_surface::check(\local_coursepilot\privacy_surface::registered_functions()));
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_add_glossary_entries'));
        assign_capability('mod/glossary:write', CAP_PROHIBIT, $roleid, \context_module::instance($glossary->cmid)->id);
        $denied = dispatcher::handle(['id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'coursepilot_add_glossary_entries', 'arguments' => [
                'cmid' => $glossary->cmid, 'entries' => [['concept' => 'Denied', 'definition' => 'Cannot write']],
            ],
        ]], $token, $headers);
        $this->assertTrue($denied['body']['result']['isError']);
        $this->assertFalse($DB->record_exists('glossary_entries', ['concept' => 'Denied']));
    }

    public function test_existing_categories_need_no_manage_permission_but_missing_categories_do(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $this->call($glossary->cmid, [['concept' => 'Seed', 'definition' => 'Seed', 'categories' => ['Science']]]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $context = \context_module::instance($glossary->cmid);
        assign_capability('mod/glossary:managecategories', CAP_PROHIBIT, $roleid, $context->id);
        $this->setUser($teacher);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'Existing', 'definition' => 'Existing category', 'categories' => ['science', ' Science ']],
            ['concept' => 'Missing', 'definition' => 'New category', 'categories' => ['New category']],
            ['concept' => 'Empty', 'definition' => 'Bad name', 'categories' => [' ']],
        ]);
        $this->assertSame([true, false, false], array_column($result['entries'], 'success'), json_encode($result));
        $this->assertSame('nopermissions', $result['entries'][1]['errorcode']);
        $this->assertSame(1, $DB->count_records('glossary_entries_categories', ['entryid' => $result['entries'][0]['entryid']]));
        $this->assertFalse($DB->record_exists('glossary_categories', ['name' => 'New category']));
    }

    public function test_moodle_defaults_formats_disabled_tags_and_file_size_limits(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['maxbytes' => 5]);
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id, 'usedynalink' => 0,
        ]);
        $CFG->glossary_linkentries = 0;
        upload_material_file::execute('large.pdf', base64_encode('too large for course'));
        \core_tag_area::update($DB->get_record('tag_area', ['component' => 'mod_glossary', 'itemtype' => 'glossary_entries']), ['enabled' => 0]);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'Plain', 'definition' => 'Plain text', 'definitionformat' => FORMAT_PLAIN, 'usedynalink' => true],
            ['concept' => 'Tagged', 'definition' => 'Disabled tags', 'tags' => ['science']],
            ['concept' => 'Big', 'definition' => 'Too large', 'attachment_files' => ['large.pdf'], 'location' => 'workbench'],
            ['concept' => ' ', 'definition' => 'Empty concept'],
            ['concept' => 'Empty', 'definition' => ' '],
        ]);
        $this->assertSame([true, false, false, false, false], array_column($result['entries'], 'success'), json_encode($result));
        $entry = $DB->get_record('glossary_entries', ['id' => $result['entries'][0]['entryid']]);
        $this->assertSame((string) FORMAT_PLAIN, $entry->definitionformat);
        $this->assertSame('0', $entry->usedynalink);
        $this->assertSame('glossaryentrytagsdisabled', $result['entries'][1]['errorcode']);
        $this->assertSame('glossaryentryfilelimit', $result['entries'][2]['errorcode']);
    }

    public function test_explicit_pending_approval_does_not_complete_the_author_activity(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionentries' => 1,
        ]);
        $this->setUser($teacher);
        $result = $this->call($glossary->cmid, [[
            'concept' => 'Pending', 'definition' => 'Not yet approved', 'approved' => false,
        ]]);
        $this->assertTrue($result['entries'][0]['success']);
        $completion = new \completion_info($course);
        $cm = get_fast_modinfo($course)->get_cm($glossary->cmid);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm)->completionstate);
    }

    public function test_reserved_alias_on_second_line_fails_only_that_entry(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'Bad', 'definition' => 'Bad alias', 'aliases' => ["safe\n$"]],
            ['concept' => 'Good', 'definition' => 'Normal alias', 'aliases' => ['safe']],
        ]);
        $this->assertSame([false, true], array_column($result['entries'], 'success'));
        $this->assertSame('errreservedkeywords', $result['entries'][0]['errorcode']);
    }

    public function test_required_editor_content_uses_moodle_validation(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->strictformsrequired = true;
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'Empty HTML', 'definition' => '<p><br></p>'],
            ['concept' => 'Empty entity', 'definition' => '&nbsp;'],
            ['concept' => 'Empty space', 'definition' => "\u{00a0}"],
            ['concept' => 'Image', 'definition' => '<img src="https://example.com/diagram.png" alt="Diagram">'],
        ]);
        $this->assertSame([false, false, false, true], array_column($result['entries'], 'success'));
        $this->assertSame('glossaryentryrequired', $result['entries'][0]['errorcode']);
    }

    public function test_standard_only_tag_configuration_cannot_be_bypassed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $area = $DB->get_record('tag_area', ['component' => 'mod_glossary', 'itemtype' => 'glossary_entries']);
        $this->call($glossary->cmid, [['concept' => 'Seed', 'definition' => 'Seed tag', 'tags' => ['science', 'ordinary']]]);
        $DB->set_field('tag', 'isstandard', 1, ['name' => 'science', 'tagcollid' => $area->tagcollid]);
        \core_tag_area::update($area, ['showstandard' => \core_tag_tag::STANDARD_ONLY]);
        $result = $this->call($glossary->cmid, [
            ['concept' => 'New tag', 'definition' => 'Forbidden', 'tags' => ['brand-new']],
            ['concept' => 'Ordinary tag', 'definition' => 'Forbidden', 'tags' => ['ordinary']],
            ['concept' => 'Standard tag', 'definition' => 'Allowed', 'tags' => ['SCIENCE']],
        ]);
        $this->assertSame([false, false, true], array_column($result['entries'], 'success'));
        $this->assertSame('glossaryentrystandardtags', $result['entries'][0]['errorcode']);
        $this->assertFalse($DB->record_exists('tag', ['name' => 'brand-new']));
    }

}
