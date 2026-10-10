<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\check;

use core\check\check;
use core\check\result;
use local_coursepilot\write_gate;

defined('MOODLE_INTERNAL') || die();

/**
 * One Moodle admin status check (standard callback
 * "<component>_status_checks()", see local_coursepilot/lib.php) per
 * catalogued activity type (ticket #399: "Admin status check shows one of
 * the three states per activity type").
 *
 * Computes afresh on every page view (via {@see write_gate::status_for()},
 * whose own version caching already ensures that no
 * unnecessary DB/reflection effort arises) - no cron, "retrievable at
 * any time" simply means: reload the status page.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_drift extends check {
    /**
     * Creates the activity drift.
     *
     * @param string $modname Moodle module name (mod_XXX without prefix).
     */
    public function __construct(
        /** @var string Moodle module name (mod_XXX without prefix). */
        private readonly string $modname,
    ) {
    }

    /**
     * Unique per instance (ticket #399: one check per activity type).
     *
     * @return string
     */
    public function get_id(): string {
        return 'activity_drift_' . $this->modname;
    }

    /**
     * Returns name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('driftcheckname', 'local_coursepilot', $this->modname);
    }

    /**
     * Returns result.
     *
     * @return result
     */
    public function get_result(): result {
        $status = write_gate::status_for($this->modname);

        return match ($status['state']) {
            'checked' => new result(result::OK, get_string('driftstatuschecked', 'local_coursepilot')),
            'auto_checked' => new result(
                result::OK,
                get_string('driftstatusautochecked', 'local_coursepilot')
            ),
            default => new result(
                result::ERROR,
                get_string('driftstatusbrauchtarbeit', 'local_coursepilot'),
                implode("\n", $status['violations'])
            ),
        };
    }
}
