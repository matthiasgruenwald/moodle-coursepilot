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
 * Lock request of add_glossary_entries: exact resource name and wait time (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(add_glossary_entries::class)]
final class add_glossary_entries_674_final_contract_test extends \advanced_testcase {
    /**
     * The tool serialises concurrent calls per glossary: one lock named after the glossary, 10 seconds of waiting.
     */
    public function test_the_call_locks_the_glossary_by_name_and_waits_ten_seconds(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        $CFG->lock_factory = recording_lock_factory::class;
        recording_lock_factory::reset();

        add_glossary_entries::execute($glossary->cmid, [
            ['concept' => 'Atom', 'definition' => '<p>Smallest unit</p>'],
        ]);

        $own = array_values(array_filter(
            recording_lock_factory::$requests,
            static fn(array $request): bool => $request['type'] === 'local_coursepilot'
        ));
        $this->assertSame([['type' => 'local_coursepilot', 'resource' => 'glossary:' . $glossary->id, 'timeout' => 10]], $own);
        recording_lock_factory::reset();
    }
}
