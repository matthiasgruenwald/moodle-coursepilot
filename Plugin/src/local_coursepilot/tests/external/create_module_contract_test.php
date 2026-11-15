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
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\declared_return_types;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact message, report, guards and their order for create_module.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_module::class)]
final class create_module_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Course with an editing teacher.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    public function test_the_message_lists_every_named_field_with_its_persisted_value_and_the_side_effect(): void {
        $this->setup_course();

        $result = create_module::execute(
            (int) $this->course->id,
            0,
            'forum',
            json_encode(['name' => 'Ankündigungen / Hinweise', 'intro' => '', 'forcesubscribe' => 2])
        );

        $this->assert_declared_types($result, create_module::execute_returns());
        $this->assertSame(
            [
                ['field' => 'name', 'value_json' => '"Ankündigungen / Hinweise"'],
                ['field' => 'intro', 'value_json' => '""'],
                ['field' => 'forcesubscribe', 'value_json' => '"2"'],
            ],
            $result['created_fields']
        );
        $this->assertSame(['All course participants have been subscribed to this forum.'], $result['side_effects']);
        $this->assertSame(
            'Activity "forum" created: "name" = "Ankündigungen / Hinweise", "intro" = "", "forcesubscribe" = "2".'
                . ' All course participants have been subscribed to this forum.',
            $result['message']
        );
        $this->assertSame('forum', $result['modname']);
    }

    public function test_without_named_fields_the_message_ends_after_the_type(): void {
        $this->setup_course();

        $result = create_module::execute((int) $this->course->id, 0, 'page', json_encode(['page' => '<p>x</p>', 'name' => 'P']));
        $bare = $this->caught(fn() => create_module::execute((int) $this->course->id, 0, 'label', '{}'));

        $this->assertSame([], $result['side_effects']);
        $this->assertStringStartsWith('Activity "page" created: "page" = ', $result['message']);
        $this->assertStringEndsWith('.', $result['message']);
        $this->assertNotNull($bare);
    }

    /**
     * Provides JSON texts that are not a field object.
     *
     * @return array<string, array{string}>
     */
    public static function unusable_json(): array {
        return [
            'a number' => ['5'],
            'a string' => ['"text"'],
            'broken syntax' => ['{"a":'],
            'null' => ['null'],
        ];
    }

    /**
     * Texts that are no field object are refused.
     *
     * @param string $json Request text.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusable_json')]
    public function test_only_a_json_object_is_accepted(string $json): void {
        $this->setup_course();

        $e = $this->caught(fn() => create_module::execute((int) $this->course->id, 0, 'label', $json));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpatchjson', $e->errorcode);
    }

    public function test_an_activity_with_its_own_write_route_names_type_and_route(): void {
        $this->setup_course();

        $e = $this->caught(fn() => create_module::execute((int) $this->course->id, 0, 'quiz', '{}'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('writevehicleblocked', $e->errorcode);
        $this->assertSame(['modname', 'write_route'], array_keys($e->a));
        $this->assertSame('quiz', $e->a['modname']);
        $this->assertNotSame('', (string) $e->a['write_route']);
    }

    public function test_context_and_both_rights_are_checked_before_the_request_is_looked_at(): void {
        global $DB;
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => create_module::execute((int) $this->course->id, 0, 'nosuchtype', 'not json'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/course:manageactivities'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => create_module::execute((int) $this->course->id, 0, 'nosuchtype', 'not json'));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }
}
