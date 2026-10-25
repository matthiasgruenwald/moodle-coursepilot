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
use local_coursepilot\catalog\catalog_fields;
use local_coursepilot\catalog\choice;
use local_coursepilot\catalog\page;
use local_coursepilot\catalog\registry;
use local_coursepilot\check\webdav_capability_check;
use local_coursepilot\external\create_quiz;
use local_coursepilot\external\get_question_categories;
use local_coursepilot\external\list_material_files;
use local_coursepilot\external\list_skills;
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact answers and guards of small helpers and read tools that have no home of their own.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(personal_data::class)]
#[CoversClass(personal_data_hosts::class)]
#[CoversClass(catalog_fields::class)]
#[CoversClass(registry::class)]
#[CoversClass(list_material_files::class)]
#[CoversClass(list_skills::class)]
#[CoversClass(create_quiz::class)]
#[CoversClass(get_question_categories::class)]
#[CoversClass(question_bank_context::class)]
#[CoversClass(webdav_capability_check::class)]
final class small_tools_contract_test extends \advanced_testcase {
    use caught_throwable;
    use webdav_instance_fixture;

    public function test_only_a_front_matter_block_at_the_very_start_can_mark_personal_data(): void {
        $this->assertTrue(personal_data::is_marked("---\ncoursepilot:\n  personenbezug: true\n---\nText\n"));
        $this->assertFalse(personal_data::is_marked("Intro\n---\npersonenbezug: true\n---\nText\n"));
        $this->assertFalse(personal_data::is_marked("---\npersonenbezug: false\n---\nText\n"));
        $this->assertFalse(personal_data::is_marked("---\nname: x\n---\npersonenbezug: true\n"));
        $this->assertFalse(personal_data::is_marked('personenbezug: true'));
    }

    public function test_configured_hosts_are_trimmed_lowercased_and_free_of_empty_lines(): void {
        $this->resetAfterTest();
        set_config('personaldatehosts', 'ignored', 'local_coursepilot');
        set_config(
            'personaldatahosts',
            "  Cloud.Example.TEST \r\n\r\nother.example.test\n \nthird.example.test\r",
            'local_coursepilot'
        );
        $configured = new \ReflectionMethod(personal_data_hosts::class, 'configured');

        $this->assertTrue($configured->isPublic());
        $this->assertSame(['cloud.example.test', 'other.example.test', 'third.example.test'], $configured->invoke(null));
        $this->assertSame('bad', personal_data_hosts::first_invalid_entry("ok.example.test\n Bad \n*.example.test"));
        $this->assertSame('*.example.test', personal_data_hosts::first_invalid_entry("ok.example.test\n*.example.test"));
        $this->assertNull(personal_data_hosts::first_invalid_entry("ok.example.test\nother.example.test"));
    }

    public function test_an_external_location_needs_an_approved_host_and_other_locations_never_do(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', 'cloud.example.test', 'local_coursepilot');
        $external = static fn(string $server): pointer_location => pointer_location::external(1, 'x', ['server' => $server]);

        personal_data_hosts::require_allowed_location(null, 'a.md');
        personal_data_hosts::require_allowed_location(pointer_location::moodle('/x/'), 'a.md');
        personal_data_hosts::require_allowed_location($external('Cloud.Example.Test'), 'a.md');
        $denied = $this->caught(
            fn() => personal_data_hosts::require_allowed_location($external('evil.example.test'), 'dir/a.md')
        );
        $nohost = $this->caught(
            fn() => personal_data_hosts::require_allowed_location(pointer_location::external(1, 'x', []), 'a.md')
        );

        foreach ([$denied, $nohost] as $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }
        $this->assertInstanceOf(\moodle_exception::class, $denied);
        $this->assertSame('dir/a.md', $denied->a);
    }

    public function test_field_names_must_be_text_values_are_compared_loosely_and_templates_are_anchored(): void {
        $nonstring = $this->caught(fn() => catalog_fields::validate(page::class, [5 => 'x']));
        $this->assertInstanceOf(\moodle_exception::class, $nonstring);
        $this->assertSame('invalidfieldname', $nonstring->errorcode);

        catalog_fields::validate(choice::class, ['display' => '1']);
        $bad = $this->caught(fn() => catalog_fields::validate(choice::class, ['display' => 7]));
        $this->assertInstanceOf(\moodle_exception::class, $bad);
        $this->assertSame('invalidfieldvalue', $bad->errorcode);
        $this->assertSame(['field' => 'display', 'modname' => 'choice', 'value' => '7'], $bad->a);

        $urlclass = \local_coursepilot\catalog\url::class;
        catalog_fields::validate($urlclass, ['parameter_1' => 'a', 'variable_12' => 'b']);
        foreach (['xparameter_1', 'parameter_1x', 'parameter_', 'variable_a'] as $name) {
            $e = $this->caught(fn() => catalog_fields::validate($urlclass, [$name => 'v']));
            $this->assertInstanceOf(\moodle_exception::class, $e, $name);
            $this->assertSame('unknownfield', $e->errorcode, $name);
        }
    }

    public function test_the_kind_gate_asks_the_modules_table_by_name_and_defaults_to_no_backup_support(): void {
        global $DB;
        $this->resetAfterTest();

        $developed = registry::kind('book');
        $DB->delete_records('modules', ['name' => 'book']);
        $removed = registry::kind('book');
        $DB->insert_record(
            'modules',
            (object) ['name' => 'zzfake', 'cron' => 0, 'lastcron' => 0, 'search' => '', 'visible' => 1]
        );
        $unsupported = registry::kind('zzfake');
        $unknown = registry::kind('qqunknown');

        $this->assertSame('developed', $developed->kind);
        foreach ([$removed, $unsupported, $unknown] as $kind) {
            $this->assertSame('excluded', $kind->kind);
            $this->assertSame('kindexcludednobackup', $kind->reasonkey);
        }
        $e = $this->caught(fn() => registry::require_catalogued('qqunknown'));
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('unknownmodname', $e->errorcode);
        $this->assertSame(['modname', 'modnames'], array_keys($e->a));
        $this->assertSame('qqunknown', $e->a['modname']);
    }

    public function test_the_remaining_quota_is_reported_in_megabytes_with_one_decimal_or_not_at_all(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $CFG->userquota = 0;
        $this->assertNull(list_material_files::execute()['remaining_quota_mb']);
        $used = (int) file_get_user_used_space();
        $CFG->userquota = $used + 2149580;
        $this->assertSame('2.0', list_material_files::execute()['remaining_quota_mb']);
        $CFG->userquota = $used + 2149581;
        $this->assertSame('2.1', list_material_files::execute()['remaining_quota_mb']);
        $this->assertSame(['path', 'entries', 'remaining_quota_mb'], array_keys(list_material_files::execute()));
    }

    public function test_the_tools_without_a_course_respect_the_context_restriction(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $material = $this->caught(fn() => list_material_files::execute());
            $skills = $this->caught(fn() => list_skills::execute());
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $material);
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $skills);
    }

    public function test_the_skill_list_has_exactly_the_published_fields_per_skill(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $result = list_skills::execute();

        $this->assertSame(['skills', 'pending_entries', 'notices'], array_keys($result));
        $this->assertNotEmpty($result['skills']);
        foreach ($result['skills'] as $skill) {
            $this->assertSame(['name', 'trigger', 'kind', 'length'], array_keys($skill));
        }
    }

    public function test_a_quiz_without_a_grade_gets_the_server_default_and_a_non_object_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $other = $this->getDataGenerator()->create_course();

        $created = create_quiz::execute((int) $course->id, 0, json_encode([
            'name' => 'Q', 'intro' => '', 'subnet' => '', 'browsersecurity' => '-', 'preferredbehaviour' => 'deferredfeedback',
        ]));
        $bad = $this->caught(fn() => create_quiz::execute((int) $course->id, 0, '5'));
        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => create_quiz::execute((int) $course->id, 0, 'not json'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertEquals(
            10,
            $DB->get_field('quiz', 'grade', ['id' => get_coursemodule_from_id('quiz', $created['cmid'])->instance])
        );
        $this->assertInstanceOf(\moodle_exception::class, $bad);
        $this->assertSame('invalidpatchjson', $bad->errorcode);
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $norights = $this->caught(fn() => create_quiz::execute((int) $course->id, 0, 'not json'));
        $this->assertInstanceOf(\required_capability_exception::class, $norights);
    }

    public function test_the_category_list_has_typed_rows_of_this_bank_only_and_creates_the_missing_top_category(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $first = \local_coursepilot\external\ensure_question_bank::execute((int) $course->id, 'First');
        $second = \local_coursepilot\external\ensure_question_bank::execute((int) $course->id, 'Second');
        \local_coursepilot\external\ensure_question_category::execute('Mine', (int) $first['topcategoryid']);
        \local_coursepilot\external\ensure_question_category::execute('Theirs', (int) $second['topcategoryid']);
        $DB->delete_records_select('question_categories', 'contextid = ? AND parent = 0', [$second['contextid']]);
        $DB->delete_records('question_categories', ['contextid' => $second['contextid']]);

        $mine = get_question_categories::execute((int) $course->id, (int) $first['questionbankid']);
        $recreated = get_question_categories::execute((int) $course->id, (int) $second['questionbankid']);

        $this->assertSame(['id', 'name', 'parent'], array_keys($mine[0]));
        $this->assertSame([0, (int) $first['topcategoryid']], [$mine[0]['parent'], $mine[1]['parent']]);
        $names = array_column(array_slice($mine, 1), 'name');
        $this->assertContains('Mine', $names);
        $this->assertNotContains('Theirs', $names);
        $this->assertIsInt($mine[1]['id']);
        $this->assertCount(1, $recreated);
        $this->assertSame(0, $recreated[0]['parent']);
    }

    public function test_the_question_bank_is_resolved_with_context_and_right_checks_on_course_and_bank(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = \local_coursepilot\external\ensure_question_bank::execute((int) $course->id, 'Bank');
        $other = $this->getDataGenerator()->create_course();
        $bankcontext = \context_module::instance((int) $bank['questionbankid']);

        [$record, $context] = question_bank_context::resolve((int) $course->id, (int) $bank['questionbankid']);
        $this->assertSame('Bank', $record->name);
        $this->assertSame($bankcontext->id, $context->id);

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restrictedtocourse = $this->caught(
                fn() => question_bank_context::resolve((int) $course->id, (int) $bank['questionbankid'])
            );
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restrictedtocourse);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, $bankcontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $nobankright = $this->caught(fn() => question_bank_context::resolve((int) $course->id, (int) $bank['questionbankid']));
        $this->assertInstanceOf(\required_capability_exception::class, $nobankright);
        unassign_capability('local/coursepilot:use', $roleid, $bankcontext->id);
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $norights = $this->caught(fn() => question_bank_context::resolve((int) $course->id, (int) $bank['questionbankid']));
        $this->assertInstanceOf(\required_capability_exception::class, $norights);
        $this->assertNotEmpty($other->id);
    }

    public function test_the_webdav_check_counts_each_connected_user_once_and_names_who_lacks_the_right(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->setAdminUser();
        $this->enable_webdav_repository_type();
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        foreach (['one', 'two'] as $client) {
            $code = \local_coursepilot\connection::issue_code($client, (int) $user->id, 'https://client.example/cb', $challenge);
            \local_coursepilot\connection::exchange_code($code, $client, 'https://client.example/cb', $verifier);
        }

        $result = (new webdav_capability_check())->get_result();

        $this->assertSame(\core\check\result::WARNING, $result->get_status());
        $this->assertSame(
            get_string('webdavcheck3warning', 'local_coursepilot', (object) ['missing' => 1, 'total' => 1]),
            $result->get_summary()
        );
        $this->assertSame('Ada Lovelace', $result->get_details());
    }
}
