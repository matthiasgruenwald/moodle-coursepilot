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

namespace local_coursepilot\external;

use coding_exception;
use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Write core 13 (Spec 0015 Phase 3, ticket #391): patches name, summary
 * and visibility of an existing section - only the supplied fields
 * change (patch, like {@see update_module_settings}).
 *
 * Writes via course_update_section() (course/lib.php), which internally calls
 * {@see \core_courseformat\local\sectionactions::update()}. This very
 * method natively triggers transfer_visibility_to_cms() on a visibility
 * change: a section that is hidden makes its activities invisible,
 * regardless of their own value - Coursepilot does not produce this side
 * effect itself, but states it in the response (ticket #391 acceptance
 * criterion).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_section extends external_api {
    /** @var string[] Catalog of the fields patchable via this endpoint. */
    private const SETTABLE_FIELDS = ['name', 'summary', 'visible'];

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object with "name" (string), "summary" (string) and/or "visible" (0|1) - only the named '
                    . 'fields change'
            ),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $fieldsjson
     * @return array
     */
    public static function execute(int $courseid, int $sectionnum, string $fieldsjson): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'fields_json' => $fieldsjson,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check: the same capability that
        // course/editsection.php requires when saving.
        require_capability('moodle/course:update', $context);

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        require_once($CFG->dirroot . '/course/lib.php');
        $course = get_course($params['courseid']);
        $sections = get_fast_modinfo($course)->get_section_info_all();
        if (!array_key_exists($params['sectionnum'], $sections)) {
            throw new moodle_exception('sectionnotfound', 'local_coursepilot', '', ['sectionnum' => $params['sectionnum']]);
        }
        $sectioninfo = $sections[$params['sectionnum']];

        $fields = self::validate_patch($patch);
        $before = [
            'name' => (string) ($sectioninfo->name ?? ''),
            'summary' => (string) ($sectioninfo->summary ?? ''),
            'visible' => (int) $sectioninfo->visible,
        ];

        if ($fields) {
            course_update_section($course, $sectioninfo, $fields);
        }

        $after = get_fast_modinfo($course)->get_section_info_all()[$params['sectionnum']];
        $changes = self::diff($patch, $before, $after);
        $hidesactivities = array_key_exists('visible', $patch) && (int) $patch['visible'] === 0 && $before['visible'] !== 0;

        return [
            'id' => (int) $after->id,
            'sectionnum' => (int) $params['sectionnum'],
            'message' => self::build_message($changes, $hidesactivities),
            'changes' => $changes,
        ];
    }

    /**
     * All-or-nothing check BEFORE writing: unknown field,
     * disallowed value for "visible".
     *
     * @param array $patch
     * @return array Moodle field names => value, directly for course_update_section().
     * @throws moodle_exception unknownfield|invalidfieldvalue
     */
    private static function validate_patch(array $patch): array {
        $fields = [];
        foreach ($patch as $fieldname => $value) {
            if (!is_string($fieldname)) {
                throw new coding_exception('fields_json must be a JSON object, not an array.');
            }
            if (!in_array($fieldname, self::SETTABLE_FIELDS, true)) {
                throw new moodle_exception(
                    'sectionunknownfield',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'fields' => implode(', ', self::SETTABLE_FIELDS)]
                );
            }
            if ($fieldname === 'visible' && !in_array($value, [0, 1], true)) {
                throw new moodle_exception('sectioninvalidvisible', 'local_coursepilot', '', ['value' => json_encode($value)]);
            }
            $fields[$fieldname] = $value;
        }
        if (array_key_exists('summary', $fields) && !array_key_exists('summaryformat', $fields)) {
            $fields['summaryformat'] = FORMAT_HTML;
        }
        return $fields;
    }

    /**
     * @param array $patch
     * @param array $before
     * @param \section_info $after
     * @return array
     */
    private static function diff(array $patch, array $before, \section_info $after): array {
        $changes = [];
        foreach (array_keys($patch) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $fieldname === 'visible' ? (int) $after->visible : (string) ($after->{$fieldname} ?? '');
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'field' => $fieldname,
                    'before_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return $changes;
    }

    /**
     * @param array $changes
     * @param bool $hidesactivities
     * @return string
     */
    private static function build_message(array $changes, bool $hidesactivities): string {
        if (!$changes) {
            $message = 'No change: the patch already matched the current state.';
        } else {
            $parts = [];
            foreach ($changes as $change) {
                $parts[] = '"' . $change['field'] . '" from ' . $change['before_json'] . ' to ' . $change['after_json'];
            }
            $message = 'Changed: ' . implode(', ', $parts) . '.';
        }

        if ($hidesactivities) {
            $message .= ' All activities in this section are therefore hidden as well, '
                . 'regardless of their own visibility setting.';
        }

        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Section DB ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
        ]);
    }
}
