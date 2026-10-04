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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Two-stage catalog approval (Ticket #399, ADR 0017): drift locks only
 * the affected activity type; other types remain writable and reads
 * are never gated.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_gate::class)]
final class write_gate_test extends \advanced_testcase {

    /**
     * All catalogs pass on the current instance: reviewed when the manual
     * review covers its Moodle major version, otherwise automatically checked
     * (e.g. Moodle 5.1 CI with manual review through 5.0).
     */
    public function test_all_catalogs_are_green_on_the_current_instance(): void {
        global $CFG;
        $this->resetAfterTest();

        foreach (write_gate::all_statuses() as $status) {
            $catalogclass = \local_coursepilot\catalog\registry::for($status['modname']);
            $expected = (int) $CFG->branch > $catalogclass::reviewed_up_to_major() ? 'auto_checked' : 'checked';
            $this->assertSame($expected, $status['state'], $status['modname'] . ': ' . implode(' ', $status['violations']));
            $this->assertSame([], $status['violations']);
        }
    }

    /**
     * assert_writable() returns without throwing when there is no drift.
     */
    public function test_assert_writable_does_not_throw_when_green(): void {
        $this->resetAfterTest();

        write_gate::assert_writable('label');
        $this->addToAssertionCount(1);
    }

    /**
     * Acceptance #399: drift locks exactly the affected activity type
     * while the other eight remain writable.
     */
    public function test_drift_locks_only_the_affected_activity_type(): void {
        $this->resetAfterTest();

        // First call performs deep validation and caches passing results.
        write_gate::all_statuses();

        // Simulate label drift as ensure_fresh() would cache after a version change.
        set_config('driftviolations_label', json_encode(['Spalte "intro" fehlt.']), 'local_coursepilot');

        $labelstatus = write_gate::status_for('label');
        $this->assertSame('needs_work', $labelstatus['state']);
        $this->assertNotEmpty($labelstatus['violations']);

        foreach (\local_coursepilot\catalog\registry::known_modnames() as $modname) {
            if ($modname === 'label') {
                continue;
            }
            $status = write_gate::status_for($modname);
            $this->assertNotSame('needs_work', $status['state'], "$modname must remain writable despite label drift.");
        }

        $this->expectException(\moodle_exception::class);
        write_gate::assert_writable('label');
    }

    /**
     * Acceptance #399: advise the teacher to report the problem to administration.
     */
    public function test_drift_message_tells_the_teacher_to_report_it(): void {
        $this->resetAfterTest();

        write_gate::all_statuses();
        set_config('driftviolations_forum', json_encode(['Spalte "forcesubscribe" fehlt.']), 'local_coursepilot');

        try {
            write_gate::assert_writable('forum');
            $this->fail('assert_writable() haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('modnamedriftlocked', $e->errorcode);
            $this->assertStringContainsString('forum', $e->getMessage());
        }

        // PHPUnit has only the English language pack. Read the German source
        // directly rather than depending on runtime language resolution.
        $string = [];
        require(__DIR__ . '/../lang/de/local_coursepilot.php');
        $this->assertArrayHasKey('modnamedriftlocked', $string);
        $this->assertStringContainsStringIgnoringCase('bitte der Administration melden', $string['modnamedriftlocked']);
    }

    /**
     * Unaffected types remain writable despite drift elsewhere. In addition
     * to the status assertion, assert_writable() actually returns successfully.
     */
    public function test_other_activity_types_stay_writable_during_drift(): void {
        $this->resetAfterTest();

        write_gate::all_statuses();
        set_config('driftviolations_label', json_encode(['Kaputt.']), 'local_coursepilot');

        write_gate::assert_writable('page');
        write_gate::assert_writable('quiz');
        $this->addToAssertionCount(2);
    }
}
