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
namespace local_coursepilot\tests;

/**
 * Captures what a call throws so a test can assert on the outcome instead of ending in an error.
 *
 * A mutation or regression that makes the call fail differently (for example with a TypeError)
 * then shows up as a failed assertion about the expected exception.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait caught_throwable {
    /**
     * Runs the call and returns whatever it throws.
     *
     * @param callable $call
     * @return \Throwable|null The thrown object, or null if the call completed.
     */
    protected function caught(callable $call): ?\Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }

    /**
     * Runs a call that must succeed and turns any throwable into a failed assertion about that call.
     *
     * Used where the observable behaviour under test is "this call completes with this result": a
     * regression that makes the call crash then fails an assertion instead of ending in an error.
     *
     * @param callable $call
     * @return mixed What the call returns.
     */
    protected function succeeded(callable $call): mixed {
        try {
            return $call();
        } catch (\Throwable $e) {
            $this->fail('The call was expected to succeed but threw ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Runs a call and asserts that it leaves Moodle's transaction stack unchanged.
     *
     * Moodle keeps an outer transaction open for each PostgreSQL test, so the public boolean status cannot detect leaks.
     *
     * @param callable $call
     * @return mixed What the call returns.
     */
    protected function assert_transaction_stack_unchanged(callable $call): mixed {
        global $DB;
        $transactions = new \ReflectionProperty(\moodle_database::class, 'transactions');
        $before = count((array) $transactions->getValue($DB));

        try {
            return $call();
        } finally {
            $this->assertSame(
                $before,
                count((array) $transactions->getValue($DB)),
                'The call must not leave a transaction open.'
            );
        }
    }
}
