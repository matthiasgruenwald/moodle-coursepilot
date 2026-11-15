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

use local_coursepilot\tests\recording_lock_factory;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * A glossary that another call holds locked is reported as busy (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(add_glossary_entries::class)]
final class add_glossary_entries_673_contract_test extends \advanced_testcase {
    /**
     * A refused lock aborts the call before any entry is written.
     */
    public function test_a_glossary_locked_by_another_call_is_reported_as_busy_and_stays_untouched(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $CFG->lock_factory = recording_lock_factory::class;
        recording_lock_factory::reset();
        recording_lock_factory::$refuse = true;

        try {
            add_glossary_entries::execute($glossary->cmid, [
                ['concept' => 'Atom', 'definition' => '<p>Smallest unit</p>'],
            ]);
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('glossaryentrybusy', $e->errorcode);
            $this->assertSame('local_coursepilot', $e->module);
        } finally {
            recording_lock_factory::reset();
        }
        $this->assertSame(0, $DB->count_records('glossary_entries', ['glossaryid' => $glossary->id]));
    }
}
