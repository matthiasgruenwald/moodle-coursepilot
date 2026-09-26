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
 * Volles Diff zweier frei gewaehlter Staende einer Aktivitaet (Spec 0015
 * §10.6, Ticket #394) - nicht nur benachbarter Versionen. Das Diff wird beim
 * Ansehen berechnet, nicht gespeichert (Spec 0015 §10.1). Rein lesend,
 * eigene Faehigkeit 'local/coursepilot:viewhistory'.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class compare_activity_versions extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'from_version' => new external_value(PARAM_INT, 'First version number to compare'),
            'to_version' => new external_value(PARAM_INT, 'Second version number to compare'),
        ]);
    }

    /**
     * @param int $cmid
     * @param int $fromversion
     * @param int $toversion
     * @return array
     */
    public static function execute(int $cmid, int $fromversion, int $toversion): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'from_version' => $fromversion,
            'to_version' => $toversion,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:viewhistory', $context);

        return version_history::compare($params['cmid'], $params['from_version'], $params['to_version']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $standblock = new external_single_structure([
            'version' => new external_value(PARAM_INT, 'Version number'),
            'source' => new external_value(PARAM_TEXT, '"moodle", "vorgefunden" or "geklont"'),
            'discovered' => new external_value(PARAM_BOOL, 'true if retroactively recorded as a starting state'),
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
        ]);

        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'before' => $standblock,
            'after' => $standblock,
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value in the "before" state'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value in the "after" state'),
                ]),
                'One entry per field that actually differs'
            ),
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'change_type' => new external_value(PARAM_TEXT, '"hinzugefuegt" (added) or "entfernt" (removed)'),
                    'filename' => new external_value(PARAM_TEXT, 'File name'),
                ]),
                'Files that were added or removed between the two states'
            ),
            'gap_notice' => new external_value(
                PARAM_TEXT,
                'Fixed notice about the structural gaps of the history - not computed per comparison'
            ),
        ]);
    }
}
