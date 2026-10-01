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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\history\version_history;

defined('MOODLE_INTERNAL') || die();

/**
 * Mehrversionen-Ueberblick des Aenderungsverlaufs (Spec 0015 §10.6, Ticket
 * #394): alle Versionen einer Aktivitaet mit je einem serverseitig
 * berechneten Einzeiler gegenueber dem Vorgaenger. Rein lesend, eigene
 * Faehigkeit 'local/coursepilot:viewhistory' statt 'local/coursepilot:use'
 * (Spec 0015 §10.6 sieht fuer den Verlauf ausdruecklich eigene Faehigkeiten
 * vor).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class list_activity_versions extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
        ]);
    }

    /**
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:viewhistory', $context);

        return version_history::list_versions($params['cmid']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'versions' => new external_multiple_structure(
                new external_single_structure([
                    'version' => new external_value(PARAM_INT, 'Consecutive version number per cmid, starting at 1'),
                    'source' => new external_value(
                        PARAM_TEXT,
                        '"moodle" (normal write) or "vorgefunden" (retroactively recorded starting state before Coursepilot)'
                    ),
                    'discovered' => new external_value(
                        PARAM_BOOL,
                        'true if this state was retroactively recorded as a starting state (source = "vorgefunden")'
                    ),
                    'source_cmid' => new external_value(
                        PARAM_INT,
                        'Source course module ID of a clone - only set when source = "geklont", null otherwise',
                        VALUE_DEFAULT,
                        null,
                        NULL_ALLOWED
                    ),
                    'userid' => new external_value(PARAM_INT, 'User ID that made the write'),
                    'user' => new external_value(PARAM_TEXT, 'Full name of that user'),
                    'timestamp' => new external_value(PARAM_INT, 'Unix timestamp of the write'),
                    'summary_line' => new external_value(
                        PARAM_TEXT,
                        'Server-computed teacher-facing German change line against the direct predecessor '
                            . '(who, when, what)'
                    ),
                ]),
                'One entry per version, sorted ascending by version number'
            ),
            'gap_notice' => new external_value(
                PARAM_TEXT,
                'Fixed notice about the structural gaps of the history - not computed per version'
            ),
        ]);
    }
}
