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

use local_coursepilot\tests\native_override;
use local_coursepilot\tests\stale_read_database;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/native_override_functions.php');

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
     * Losing the insert race still counts the request against the winner's row.
     */
    public function test_losing_the_site_row_insert_race_counts_against_the_winners_row(): void {
        global $DB;
        $this->resetAfterTest();
        native_override::$now = 1000050;
        $expires = 1000100;
        $winner = $DB->insert_record('local_coursepilot_oauth_budget', (object) [
            'scope' => 'register', 'sourcekey' => '*', 'expires' => $expires, 'hits' => 0,
        ]);
        $realdb = $DB;
        // The loser looked for the row just before the winner inserted it.
        $DB = new stale_read_database($realdb, 'get_field');
        try {
            $wait = oauth_budget::consume('register', 'source', 5, 5, 100);
        } finally {
            $DB = $realdb;
        }

        $this->assertSame(0, $wait);
        $this->assertSame(1, $DB->count_records('local_coursepilot_oauth_budget', ['sourcekey' => '*']));
        $this->assertSame(1, (int) $DB->get_field('local_coursepilot_oauth_budget', 'hits', ['id' => $winner]));
    }

    /**
     * Restores the real clock.
     */
    protected function tearDown(): void {
        native_override::reset();
        parent::tearDown();
    }
}
