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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\glossary_entry_writer;
use local_coursepilot\material_files;

/**
 * Adds teacher-authored entries without exposing existing learner content (#593).
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class add_glossary_entries extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $strings = static fn(string $description) => new external_multiple_structure(
            new external_value(PARAM_TEXT, $description),
            $description,
            VALUE_DEFAULT,
            []
        );
        $paths = static fn(string $description) => new external_multiple_structure(
            new external_value(PARAM_RAW, 'Relative material path; file contents never enter the response'),
            $description,
            VALUE_DEFAULT,
            []
        );
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Glossary course module ID, fresh or existing'),
            'entries' => new external_multiple_structure(new external_single_structure([
                'concept' => new external_value(PARAM_TEXT, 'Concept'),
                'definition' => new external_value(PARAM_RAW, 'Definition; use @@PLUGINFILE@@/filename for embedded files'),
                'definitionformat' => new external_value(PARAM_INT, 'Moodle text format: 0 Moodle, 1 HTML, 2 plain, 4 Markdown', VALUE_DEFAULT, FORMAT_HTML),
                'aliases' => $strings('Keywords or aliases, one per item'),
                'categories' => $strings('Category names; missing categories require mod/glossary:managecategories'),
                'usedynalink' => new external_value(PARAM_BOOL, 'Automatic linking, only if enabled for the glossary; otherwise Moodle defaults apply', VALUE_OPTIONAL),
                'casesensitive' => new external_value(PARAM_BOOL, 'Case-sensitive linking', VALUE_OPTIONAL),
                'fullmatch' => new external_value(PARAM_BOOL, 'Link whole words only', VALUE_OPTIONAL),
                'approved' => new external_value(PARAM_BOOL, 'Explicit approval state requires mod/glossary:approve; omit for Moodle default approval', VALUE_OPTIONAL),
                'tags' => $strings('Entry tags, when glossary entry tagging is enabled'),
                'attachment_files' => $paths('Attachments copied from the teacher material area'),
                'definition_files' => $paths('Embedded definition files copied from the teacher material area'),
                'location' => material_files::location_parameter(),
            ]), 'Teacher-authored entries; each entry succeeds or fails independently'),
        ]);
    }

    /**
     * Runs the add glossary entries tool.
     *
     * @param int $cmid
     * @param array $entries
     * @return array
     */
    public static function execute(int $cmid, array $entries): array {
        global $CFG, $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'entries' => $entries]);
        $cm = get_coursemodule_from_id('glossary', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('mod/glossary:write', $context);
        require_once($CFG->dirroot . '/mod/glossary/lib.php');
        $course = get_course($cm->course);
        $glossary = $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST);
        // Serialise duplicate/category checks across concurrent calls of this tool.
        $lock = \core\lock\lock_config::get_lock_factory('local_coursepilot')->get_lock('glossary:' . $glossary->id, 10);
        if (!$lock) {
            throw new \moodle_exception('glossaryentrybusy', 'local_coursepilot');
        }
        try {
            $results = [];
            foreach ($params['entries'] as $index => $entry) {
                $results[] = ['index' => $index] + glossary_entry_writer::add($entry, $course, $cm, $glossary, $context);
            }
            return [
                'cmid' => (int) $cm->id,
                'entries' => $results,
                'gap_notice' => get_string('historygapnotice', 'local_coursepilot'),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Glossary course module ID'),
            'entries' => new external_multiple_structure(new external_single_structure([
                'index' => new external_value(PARAM_INT, 'Zero-based input index'),
                'success' => new external_value(PARAM_BOOL, 'Whether this entry was created'),
                'entryid' => new external_value(PARAM_INT, 'New entry ID; 0 on failure'),
                'approved' => new external_value(PARAM_BOOL, 'Actual approval state; false on failure'),
                'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Moodle error code; empty on success'),
                'message' => new external_value(PARAM_RAW, 'Error reason; empty on success'),
            ])),
            'gap_notice' => new external_value(PARAM_RAW, 'History limitations: glossary entries are not versioned or restorable'),
        ]);
    }
}
