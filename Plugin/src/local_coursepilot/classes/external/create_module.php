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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\module_catalog;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\write_target;
use local_coursepilot\material_files;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Create a new activity through the native form route (Spec 0015 §3.4,
 * Ticket #389, phase 3): can_add_moduleinfo() checks permissions and
 * resolves module/section; add_moduleinfo() writes. No prior manual edits
 * need preservation, so no before/after diff like update_module_settings.
 *
 * Unlike patches (Ticket #388), fill missing fields from cataloged form
 * defaults, not DB defaults, which may differ (choice.includeinactive).
 * Required fields without a form default fail with a named-field message.
 *
 * resource requires files in the same call (Spec 0018 §4/§7, Issue #434):
 * without a main file its activity page is broken. Validate before writing
 * through write_target::create(). An empty folder is valid; its files are
 * optional and can include multiple paths with target subfolders (§4.2).
 *
 * Normalization, checks, file resolution and add_moduleinfo() are one
 * sequence in write_target::create_activity() (#647); this is the external
 * adapter. Bundles remain catalog presets (Spec 0015 §2.4): the AI merges
 * them into fields_json while preserving explicitly named values. This
 * endpoint sees the merged result.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_module extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'modname' => new external_value(PARAM_PLUGIN, 'Activity type, e.g. page, label, url, choice, forum, assign'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => value - missing fields are filled with the catalog form default. '
                    . 'A field bundle (describe_module_fields) is mixed in here BEFORE the call (Spec 0015 §2.4: '
                    . 'bundles are not an endpoint parameter) - a bundle value only applies to fields this object '
                    . 'does not already name itself.'
            ),
            'location' => material_files::location_parameter(),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Runs the create module tool.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param string $fieldsjson
     * @param string $location
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum,
        string $modname,
        string $fieldsjson,
        string $location = material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'modname' => $modname,
            'fields_json' => $fieldsjson,
            'location' => $location,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        self::authorise($params['courseid']);

        $modname = $params['modname'];
        $catalogclass = self::catalog_for($modname);
        // Cheap catalog approval check (Spec 0015 §11, ADR 0017, Ticket #399):
        // lock only this activity type if a detected version change revealed drift.
        // Reading remains available.
        write_gate::assert_writable($modname);

        $merged = json_decode($params['fields_json'], true);
        if (!is_array($merged) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        // Rules, file checks and the native sequence live in the catalog core (#646, #647).
        ['cmid' => $cmid, 'changes' => $merged] = write_target::create_activity(
            $catalogclass,
            get_course($params['courseid']),
            $params['sectionnum'],
            $merged,
            $params['location'],
            $params[learner_locks::PARAMETER]
        );

        $after = self::read_settings($cmid);
        [$createdfields, $sideeffects] = self::report_and_side_effects($modname, $merged, $after);

        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'message' => self::build_message($modname, $createdfields, $sideeffects),
            'created_fields' => $createdfields,
            'side_effects' => $sideeffects,
        ];
    }

    /**
     * Authorize course context and capabilities (Issue #523, extracted from
     * execute() to keep it within 50 lines).
     *
     * @param int $courseid
     * @return \context_course
     */
    private static function authorise(int $courseid): \context_course {
        $coursecontext = context_course::instance($courseid);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Check native editing permissions early (Spec 0015 §3.4), as in
        // update_module_settings. can_add_moduleinfo checks again later;
        // this prevents field validation from masking missing permissions.
        require_capability('moodle/course:manageactivities', $coursecontext);

        return $coursecontext;
    }

    /**
     * Catalog class for $modname, provided this endpoint is its write route
     * (Spec 0015 §3.1). Some types use dedicated tools, e.g. quiz. Same check
     * as update_module_settings::catalog_for().
     *
     * @param string $modname
     * @return class-string<module_catalog>
     * @throws moodle_exception unknownmodname|writevehicleblocked
     */
    private static function catalog_for(string $modname): string {
        $catalogclass = registry::require_catalogued($modname);
        $writeroute = $catalogclass::write_route();
        if ($writeroute !== null) {
            throw new moodle_exception(
                'writevehicleblocked',
                'local_coursepilot',
                '',
                ['modname' => $modname, 'write_route' => $writeroute]
            );
        }
        return $catalogclass;
    }

    /**
     * Return current state after creation as an associative array, reusing
     * get_module_settings rather than duplicating it, like
     * {@see update_module_settings::read_settings()}.
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        $settings = json_decode($result['settings_json'], true);
        $fieldname = registry::for($result['modname'])::write_options()['intro_image_field'] ?? null;
        if ($fieldname !== null) {
            $files = get_file_storage()->get_area_files(
                \context_module::instance($cmid)->id,
                'mod_' . $result['modname'],
                'intro',
                0,
                'filename',
                false
            );
            $settings[$fieldname] = array_values(array_map(
                static fn(\stored_file $file): string => $file->get_filename(),
                $files
            ));
        }
        return $settings;
    }

    /**
     * Report fields explicitly set by the call or bundle using persisted
     * values, including Moodle normalization (e.g. url_fix_submitted_url()),
     * and triggered side effects. Omit catalog defaults not named by the
     * teacher: they are implicit settings, not requested changes.
     *
     * @param string $modname
     * @param array $merged
     * @param array $after
     * @return array{0: array, 1: string[]}
     */
    private static function report_and_side_effects(string $modname, array $merged, array $after): array {
        $createdfields = [];
        $sideeffects = [];
        $triggers = registry::for($modname)::write_options()['side_effect_triggers'] ?? [];

        foreach (array_keys($merged) as $fieldname) {
            $value = $after[$fieldname] ?? null;
            $createdfields[] = [
                'field' => $fieldname,
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            if (isset($triggers[$fieldname][$value])) {
                $sideeffects[] = $triggers[$fieldname][$value];
            }
        }

        return [$createdfields, $sideeffects];
    }

    /**
     * Localized creation message (Spec 0015 §3.4: the response is the
     * change report).
     *
     * @param string $modname
     * @param array $createdfields
     * @param string[] $sideeffects
     * @return string
     */
    private static function build_message(string $modname, array $createdfields, array $sideeffects): string {
        $parts = [];
        foreach ($createdfields as $field) {
            $parts[] = '"' . $field['field'] . '" = ' . $field['value_json'];
        }
        $message = 'Activity "' . $modname . '" created';
        $message .= $parts ? (': ' . implode(', ', $parts) . '.') : '.';

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the newly created activity'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing creation message'),
            'created_fields' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'value_json' => new external_value(PARAM_RAW, 'JSON-encoded, actually persisted value'),
                ]),
                'One entry per field set by the patch/bundle - silent catalog defaults are deliberately absent here'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing side-effect note'),
                'Triggered side effects from catalog category 5, empty when none were triggered'
            ),
        ]);
    }
}
