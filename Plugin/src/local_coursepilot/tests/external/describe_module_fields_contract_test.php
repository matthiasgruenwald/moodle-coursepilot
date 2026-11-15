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
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact notices, routing hints, side effects and error data of the field catalog tool (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(describe_module_fields::class)]
final class describe_module_fields_contract_test extends \advanced_testcase {
    use caught_throwable;

    public function test_the_list_of_activity_types_comes_with_the_exact_hint(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = describe_module_fields::execute();

        $this->assertSame(
            'Coursepilot knows these activity types: ' . implode(', ', registry::known_modnames())
                . '. describe_module_fields(modname) queries one of them.',
            $result['notice']
        );
        $this->assertSame(registry::known_modnames(), $result['known_modnames']);
    }

    public function test_the_short_form_names_exactly_what_it_leaves_out(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = describe_module_fields::execute('label');

        $this->assertSame(
            'Short form: only the frequently set fields and field bundles. Pseudo fields, blocklist, '
                . 'combination rules and side effects are missing - retrieve with full:true.',
            $result['notice']
        );
    }

    public function test_an_unknown_activity_type_names_itself_and_the_known_ones(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->caught(static fn() => describe_module_fields::execute('nonsense'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('unknownmodname', $e->errorcode);
        $this->assertSame(['modname' => 'nonsense', 'modnames' => implode(', ', registry::known_modnames())], $e->a);
    }

    public function test_the_catalog_is_refused_outside_the_restricted_context(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $course = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($course->id));
        try {
            $e = $this->caught(static fn() => describe_module_fields::execute('label'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    public function test_a_type_with_its_own_write_endpoint_names_it_and_others_name_the_form_route(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $quiz = describe_module_fields::execute('quiz')['module'];
        $label = describe_module_fields::execute('label')['module'];

        $this->assertSame('update_quiz_settings', $quiz['write_route']);
        $this->assertStringStartsWith('Form route (update_moduleinfo() or add_moduleinfo())', $label['write_route']);
    }

    public function test_the_full_form_lists_the_shared_and_the_own_side_effects(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $quiz = describe_module_fields::execute('quiz', true)['module']['side_effects'];

        $own = \local_coursepilot\catalog\quiz::side_effects();
        $shared = \local_coursepilot\catalog\shared_block::side_effects();
        $this->assertSame(array_merge($shared, $own), $quiz);
        $this->assertNotSame([], $own);
        $this->assertContains($own[0], $quiz);
    }
}
