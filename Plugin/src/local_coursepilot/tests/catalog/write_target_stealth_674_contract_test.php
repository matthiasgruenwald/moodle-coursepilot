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


namespace local_coursepilot\catalog;

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Stealth (visibleoncoursepage) rule judged directly at the write target, both edges.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_target::class)]
final class write_target_stealth_674_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Stealth requested with allowstealth off is refused before anything is written.
     */
    public function test_stealth_request_is_refused_when_allowstealth_is_off(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 0);

        $e = $this->caught(fn() => write_target::create(assign::class, [
            'name' => 'A', 'intro' => 'x', 'visibleoncoursepage' => 0,
        ], []));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('stealthnotallowed', $e->errorcode);
    }

    /**
     * Visible-on-course-page value 1 is never a stealth request, even with allowstealth off.
     */
    public function test_visible_value_one_is_accepted_when_allowstealth_is_off(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 0);

        $target = $this->succeeded(fn() => write_target::create(assign::class, [
            'name' => 'A', 'intro' => 'x', 'visibleoncoursepage' => 1,
        ], []));

        $this->assertInstanceOf(write_target::class, $target);
    }

    /**
     * With allowstealth on, a stealth request is accepted.
     */
    public function test_stealth_request_is_accepted_when_allowstealth_is_on(): void {
        $this->resetAfterTest();
        set_config('allowstealth', 1);

        $target = $this->succeeded(fn() => write_target::create(assign::class, [
            'name' => 'A', 'intro' => 'x', 'visibleoncoursepage' => 0,
        ], []));

        $this->assertInstanceOf(write_target::class, $target);
    }
}
