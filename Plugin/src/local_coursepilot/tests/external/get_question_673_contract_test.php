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

use local_coursepilot\tests\stale_read_database;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * A bank entry whose versions vanish between two reads is reported as not found (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_question::class)]
final class get_question_673_contract_test extends \advanced_testcase {
    /**
     * The version query comes back empty after the entry was found: the error names the entry.
     */
    public function test_an_entry_without_a_readable_version_is_reported_with_its_entry_id(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = ensure_question_bank::execute($course->id, 'Bank');
        $categoryid = (int) ensure_question_category::execute('Category', (int) $bank['topcategoryid'])['id'];
        $created = create_mc_question::execute($categoryid, 'Q', 'Text', 'single', [
            ['answer' => 'Yes', 'fraction' => 1], ['answer' => 'No', 'fraction' => 0],
        ]);
        $realdb = $DB;
        // The versions were deleted right after the entry was found: the version query is the first get_record_sql on that table.
        $DB = new stale_read_database($realdb, 'get_record_sql', 'FROM {question_versions}');
        try {
            get_question::execute($categoryid, '', (int) $created['questionid']);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('notfound', $e->errorcode);
            $this->assertSame(
                'No version found for questionbankentryid ' . $created['questionbankentryid'] . '.',
                $e->debuginfo
            );
        } finally {
            $DB = $realdb;
        }
    }
}
