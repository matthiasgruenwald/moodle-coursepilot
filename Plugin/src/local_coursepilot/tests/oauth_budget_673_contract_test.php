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

use local_coursepilot\tests\stale_read_database;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The site counter row of a window that a concurrent request created first is reused (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(oauth_budget::class)]
final class oauth_budget_673_contract_test extends \advanced_testcase {
    /**
     * Losing the insert race yields the id of the row the winner created, as an integer.
     */
    public function test_losing_the_site_row_insert_race_returns_the_winners_row_id(): void {
        global $DB;
        $this->resetAfterTest();
        $expires = 2000000000;
        $winner = $DB->insert_record('local_coursepilot_oauth_budget', (object) [
            'scope' => 'register', 'sourcekey' => '*', 'expires' => $expires, 'hits' => 0,
        ]);
        $realdb = $DB;
        // The loser looked for the row just before the winner inserted it.
        $DB = new stale_read_database($realdb, 'get_field');
        try {
            $id = (new \ReflectionMethod(oauth_budget::class, 'site_row'))->invoke(null, 'register', $expires);
        } finally {
            $DB = $realdb;
        }

        $this->assertSame((int) $winner, $id);
        $this->assertSame(1, $DB->count_records('local_coursepilot_oauth_budget'));
    }
}
