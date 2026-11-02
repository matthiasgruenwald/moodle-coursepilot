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
 * Same-context moves leave the questions alone and name conflicts are judged inside the target context (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_question_category::class)]
final class update_question_category_674_contract_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

    /**
     * Teacher with a question bank.
     *
     * @return int Top category ID of the bank.
     */
    private function setup_bank(): int {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = ensure_question_bank::execute($course->id, 'Bank');
        return (int) $bank['topcategoryid'];
    }

    public function test_moving_a_category_inside_its_context_does_not_touch_the_cached_questions(): void {
        $top = $this->setup_bank();
        $target = (int) ensure_question_category::execute('Target', $top)['id'];
        $moving = (int) ensure_question_category::execute('Moving', $top)['id'];
        $question = create_mc_question::execute($moving, 'Q', 'Text', 'single', [
            ['answer' => 'Yes', 'fraction' => 1], ['answer' => 'No', 'fraction' => 0],
        ]);
        $questionid = (int) $question['questionid'];
        \question_bank::load_question_data($questionid);
        $cache = \cache::make('core', 'questiondata');
        $this->assertTrue($cache->has($questionid), 'Precondition: the question definition is cached.');

        $result = update_question_category::execute($moving, '', $target);

        $this->assertTrue($result['moved']);
        $this->assertTrue($cache->has($questionid), 'A move inside one context must not purge the question cache.');
    }

    public function test_a_same_named_category_in_another_context_is_no_conflict(): void {
        global $DB;
        $top = $this->setup_bank();
        $parent = (int) ensure_question_category::execute('Parent', $top)['id'];
        $moving = (int) ensure_question_category::execute('Same name', $top)['id'];
        $othercontextid = (int) \context_system::instance()->id;
        $DB->insert_record('question_categories', (object) [
            'name' => 'Same name', 'contextid' => $othercontextid, 'info' => '', 'infoformat' => 0,
            'stamp' => make_unique_id_code(), 'parent' => $parent, 'sortorder' => 0, 'idnumber' => null,
        ]);

        $result = $this->succeeded(fn() => update_question_category::execute($moving, '', $parent));

        $this->assertTrue($result['moved']);
        $this->assertSame($parent, $result['parent']);
    }
}
