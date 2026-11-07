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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Question category tools: context-scoped reuse and exact integer output (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(ensure_question_category::class)]
#[CoversClass(get_question_category_cleanup_plan::class)]
final class question_category_tools_674_final_contract_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

    /**
     * Teacher with a question bank.
     *
     * @return mixed[] Course and bank result.
     */
    private function setup_bank(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, ensure_question_bank::execute($course->id, 'Bank')];
    }

    /**
     * A category of the same name below the same parent id in another context is not reused.
     */
    public function test_a_same_named_child_in_another_context_is_not_reused(): void {
        global $DB;
        [, $bank] = $this->setup_bank();
        $top = (int) $bank['topcategoryid'];
        $foreign = $DB->insert_record('question_categories', (object) [
            'name' => 'Shared name', 'contextid' => \context_system::instance()->id, 'info' => '', 'infoformat' => 0,
            'stamp' => make_unique_id_code(), 'parent' => $top, 'sortorder' => 0, 'idnumber' => null,
        ]);

        $result = $this->succeeded(fn() => ensure_question_category::execute('Shared name', $top));

        $this->assertTrue($result['created']);
        $this->assertNotEquals($foreign, $result['id']);
        $this->assertSame(
            (int) $DB->get_field('question_categories', 'contextid', ['id' => $top]),
            (int) $DB->get_field('question_categories', 'contextid', ['id' => $result['id']])
        );
    }

    /**
     * The raw result carries the parent as an integer, not as the database string.
     */
    public function test_cleanup_plan_reports_the_parent_as_integer(): void {
        [$course, $bank] = $this->setup_bank();
        $top = (int) $bank['topcategoryid'];
        $created = ensure_question_category::execute('Empty leaf', $top);

        $result = $this->succeeded(fn() => get_question_category_cleanup_plan::execute($course->id, $bank['questionbankid']));

        $byid = array_column($result['removals'], null, 'id');
        $this->assertArrayHasKey((int) $created['id'], $byid);
        $this->assertSame($top, $byid[(int) $created['id']]['parent']);
    }
}
