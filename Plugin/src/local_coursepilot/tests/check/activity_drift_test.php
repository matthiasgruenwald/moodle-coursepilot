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

namespace local_coursepilot\check;

use core\check\result;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Die Admin-Statusprüfung je Aktivitätsart (Ticket #399): "geprueft" und
 * "automatisch_geprueft" sind beide result::OK (schreibbar), "braucht_arbeit"
 * ist result::ERROR (gesperrt) mit den Verstoessen als Detail.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_drift::class)]
final class activity_drift_test extends \advanced_testcase {

    /**
     * Gruen auf der aktuellen Testinstanz -> result::OK.
     */
    public function test_result_is_ok_when_green(): void {
        $this->resetAfterTest();

        $check = new activity_drift('label');
        $result = $check->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    /**
     * Simulierter Drift -> result::ERROR, Detail nennt den Verstoss.
     */
    public function test_result_is_error_when_drifted(): void {
        $this->resetAfterTest();

        \local_coursepilot\write_gate::all_statuses();
        set_config('driftviolations_label', json_encode(['Spalte "intro" fehlt.']), 'local_coursepilot');

        $check = new activity_drift('label');
        $result = $check->get_result();

        $this->assertSame(result::ERROR, $result->get_status());
        $this->assertStringContainsString('intro', $result->get_details());
    }

    /**
     * Die Check-ID ist je Aktivitätsart eindeutig.
     */
    public function test_id_is_unique_per_modname(): void {
        $this->assertNotSame((new activity_drift('label'))->get_id(), (new activity_drift('page'))->get_id());
    }
}
