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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Source-course validation, plain restrictions and dangling-prerequisite messages of clone_activity (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(clone_activity::class)]
final class clone_activity_availability_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Replaces the page module with a database-resident module whose plugin is absent.
     *
     * @param int $cmid Course-module ID.
     * @return array{moduleid: int, modulename: string, instance: int}
     */
    private function use_missing_module_plugin(int $cmid): array {
        global $DB;
        $table = new \xmldb_table('zzremovedmod');
        $table->addField(new \xmldb_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE));
        $table->addField(new \xmldb_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL));
        $table->addField(new \xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL));
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);

        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        $modulename = $DB->get_field('modules', 'name', ['id' => $cm->module], MUST_EXIST);
        $instanceid = $DB->insert_record('zzremovedmod', (object) ['course' => $cm->course, 'name' => 'Missing plugin']);
        $DB->set_field('modules', 'name', 'zzremovedmod', ['id' => $cm->module]);
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $cmid]);
        rebuild_course_cache($cm->course, true);
        return ['moduleid' => (int) $cm->module, 'modulename' => $modulename, 'instance' => (int) $cm->instance];
    }

    /**
     * Restores the module record and removes the temporary instance table.
     *
     * @param int $cmid Course-module ID.
     * @param int $moduleid Module type ID.
     * @param string $modulename Original module name.
     * @param int $instance Original instance ID.
     */
    private function drop_missing_module_table(int $cmid, int $moduleid, string $modulename, int $instance): void {
        global $DB;
        $DB->set_field('modules', 'name', $modulename, ['id' => $moduleid]);
        $DB->set_field('course_modules', 'instance', $instance, ['id' => $cmid]);
        $cm = $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
        rebuild_course_cache($cm->course, true);
        $DB->get_manager()->drop_table(new \xmldb_table('zzremovedmod'));
    }

    /** @var \stdClass Source course. */
    private \stdClass $source;

    /** @var \stdClass Target course. */
    private \stdClass $target;

    /** @var \stdClass Page that gets cloned. */
    private \stdClass $page;

    /**
     * Two courses with one teacher; the source holds the page to clone.
     */
    private function setup_courses(): void {
        $this->resetAfterTest();
        $this->source = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        foreach ([$this->source, $this->target] as $course) {
            $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        }
        $this->setUser($teacher);
        $this->page = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'name' => 'Original']);
    }

    /**
     * Stores raw availability JSON on the source page.
     *
     * @param string|null $json Raw value.
     */
    private function store_availability(?string $json): void {
        global $DB;
        $DB->set_field('course_modules', 'availability', $json, ['id' => $this->page->cmid]);
        rebuild_course_cache($this->source->id, true);
    }

    public function test_a_clone_restricted_to_the_target_course_is_refused_for_its_source_course(): void {
        global $DB;
        $this->setup_courses();
        $before = $DB->count_records('course_modules');

        external_api::set_context_restriction(\context_course::instance($this->target->id));
        try {
            $e = $this->caught(fn() => clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
        $this->assertSame($before, $DB->count_records('course_modules'));
    }

    /**
     * Provides the expected completion wording per stored completion value.
     *
     * @return array<string, array{int, string}>
     */
    public static function completion_values(): array {
        return [
            'not completed' => [0, 'not completed'],
            'completed' => [1, 'abgeschlossen'],
            'passed' => [2, 'bestanden'],
            'failed' => [3, 'not passed'],
        ];
    }

    #[DataProvider('completion_values')]
    public function test_a_removed_prerequisite_names_its_required_completion_state(int $value, string $label): void {
        $this->setup_courses();
        $prerequisite = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'name' => 'Vorher']);
        $condition = ['type' => 'completion', 'cm' => (int) $prerequisite->cmid, 'e' => $value];
        $this->store_availability(json_encode(['op' => '&', 'c' => [$condition], 'showc' => [true]]));

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);

        $this->assertStringContainsString('Completion condition on "Vorher" (' . $label . ') - ', $result['message']);
    }

    public function test_several_removed_prerequisites_are_counted_and_listed_in_order(): void {
        $this->setup_courses();
        $first = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'name' => 'Eins']);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'name' => 'Zwei']);
        $this->store_availability(json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'completion', 'cm' => (int) $first->cmid, 'e' => 1],
                ['type' => 'completion', 'cm' => (int) $second->cmid, 'e' => 2],
            ],
            'showc' => [true, true],
        ]));

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);

        $this->assertSame(
            'Activity cloned as "Clone" into the target course. 2 kaputte Voraussetzungen entfernt: '
                . 'Completion condition on "Eins" (abgeschlossen) - the referenced activity was not copied along '
                . 'when cloning across courses; '
                . 'Completion condition on "Zwei" (bestanden) - the referenced activity was not copied along '
                . 'when cloning across courses.',
            $result['message']
        );
    }

    public function test_cloning_an_unrestricted_activity_across_courses_decodes_no_empty_restriction(): void {
        $this->setup_courses();
        $this->store_availability(null);
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        });
        try {
            $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('Activity cloned as "Clone" into the target course.', $result['message']);
        $this->assertSame([], array_values(array_filter($messages, static fn($m) => str_contains($m, 'json_decode'))));
    }

    public function test_a_module_without_backup_support_is_refused_by_name_before_anything_is_cloned(): void {
        global $DB;
        $this->setup_courses();
        $before = $DB->count_records('course_modules');
        $original = $this->use_missing_module_plugin((int) $this->page->cmid);
        try {
            $e = $this->caught(fn() => clone_activity::execute((int) $this->page->cmid, 'Clone'));
        } finally {
            $this->drop_missing_module_table(
                (int) $this->page->cmid,
                $original['moduleid'],
                $original['modulename'],
                $original['instance']
            );
        }

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('clonenobackupsupport', $e->errorcode);
        $this->assertSame(['modname' => 'zzremovedmod'], $e->a);
        $this->assertSame($before, $DB->count_records('course_modules'));
    }

    public function test_conditions_of_a_nested_group_get_an_explicit_visibility_list_after_cleanup(): void {
        global $DB;
        $this->setup_courses();
        $prerequisite = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id]);
        $this->store_availability(json_encode([
            'op' => '&',
            'c' => [
                [
                    'op' => '|',
                    'c' => [
                        ['type' => 'completion', 'cm' => (int) $prerequisite->cmid, 'e' => 1],
                        ['type' => 'date', 'd' => '>=', 't' => 1],
                    ],
                    'show' => true,
                ],
                ['type' => 'date', 'd' => '<', 't' => 2000000000],
            ],
            'showc' => [true, false],
        ]));

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);

        $stored = json_decode($DB->get_field('course_modules', 'availability', ['id' => $result['cmid']]), true);
        $this->assertSame([true], $stored['c'][0]['showc']);
        $this->assertSame([true, false], $stored['showc']);
    }

    /**
     * Calls a private method of the tool.
     *
     * @param string $method Method name.
     * @param mixed ...$arguments Arguments.
     * @return mixed
     */
    private function call_private(string $method, mixed ...$arguments): mixed {
        return (new \ReflectionMethod(clone_activity::class, $method))->invoke(null, ...$arguments);
    }

    public function test_a_removed_condition_without_a_source_name_or_state_uses_the_defaults(): void {
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        });
        try {
            $unknown = $this->call_private('describe_removed_condition', null);
            $nostate = $this->call_private('describe_removed_condition', ['type' => 'completion', 'cm' => 0]);
            $nocm = $this->call_private('describe_removed_condition', ['type' => 'completion', 'e' => 2]);
        } finally {
            restore_error_handler();
        }

        $generic = 'Completion condition on an activity that was not copied along (%s)';
        $this->assertSame(sprintf($generic, 'abgeschlossen'), $unknown);
        $this->assertSame(sprintf($generic, 'abgeschlossen'), $nostate);
        $this->assertSame(sprintf($generic, 'bestanden'), $nocm);
        $this->assertSame([], $messages);
    }
}
