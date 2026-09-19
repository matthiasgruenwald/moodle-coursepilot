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

use core\check\check;
use core\check\result;
use local_coursepilot\write_gate;

defined('MOODLE_INTERNAL') || die();

/**
 * Eine Moodle-Admin-Statusprüfung (Standard-Callback
 * "<component>_status_checks()", siehe local_coursepilot/lib.php) je
 * katalogisierter Aktivitätsart (Ticket #399: "Admin-Statusprüfung zeigt je
 * Aktivitätsart einen der drei Zustände").
 *
 * Rechnet bei jedem Seitenaufruf frisch (ueber {@see write_gate::status_for()},
 * dessen eigene Versions-Zwischenspeicherung bereits dafuer sorgt, dass kein
 * unnoetiger DB-/Reflection-Aufwand entsteht) - kein Cron, "jederzeit
 * abrufbar" ist einfach: die Statusseite neu laden.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_drift extends check {

    /**
     * @param string $modname Moodle-Modulname (mod_XXX ohne Praefix).
     */
    public function __construct(private readonly string $modname) {
    }

    /**
     * Eindeutig je Instanz (Ticket #399: eine Pruefung je Aktivitätsart).
     *
     * @return string
     */
    public function get_id(): string {
        return 'activity_drift_' . $this->modname;
    }

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('driftcheckname', 'local_coursepilot', $this->modname);
    }

    /**
     * @return result
     */
    public function get_result(): result {
        $status = write_gate::status_for($this->modname);

        return match ($status['zustand']) {
            'geprueft' => new result(result::OK, get_string('driftstatusgeprueft', 'local_coursepilot')),
            'automatisch_geprueft' => new result(
                result::OK,
                get_string('driftstatusautomatischgeprueft', 'local_coursepilot')
            ),
            default => new result(
                result::ERROR,
                get_string('driftstatusbrauchtarbeit', 'local_coursepilot'),
                implode("\n", $status['verstoesse'])
            ),
        };
    }
}
