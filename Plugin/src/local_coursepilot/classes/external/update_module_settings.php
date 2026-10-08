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
use local_coursepilot\catalog\module_catalog;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\write_target;
use local_coursepilot\material_files;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * The first write operation (Spec 0015 §3.3, ticket #388, phase 3): patches
 * individual settings of an existing activity via the native form path
 * (read with get_moduleinfo_data(), overlay, write with update_moduleinfo())
 * - no conflict protection, no expected_version, a parallel manual change
 * to a DIFFERENT field survives (Spec 0015 §3.3).
 *
 * All or nothing: every validation (unknown field, locked field,
 * disallowed value, combination rule) runs BEFORE the single write call
 * - no partial result is possible.
 *
 * No Coursepilot write capability of its own: get_moduleinfo_data() internally
 * calls can_update_moduleinfo(), which requires 'moodle/course:manageactivities'
 * in the module context - that is the native check Spec 0015 §3.3
 * demands. 'local/coursepilot:use' remains the base access check, as with
 * every other tool.
 *
 * Direct DB writes are deliberately not used (ADR 0016): they do not
 * trigger course_module_updated, so the change history (#385-387) would be
 * blind to them.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class update_module_settings extends external_api {

    /**
     * The material reference pseudofields (write_options() "material_reference_fields")
     * of an activity type, for {@see \local_coursepilot\external\restore_activity_version},
     * which needs the same component/filearea set to bring replaced files back
     * from the trash ({@see \local_coursepilot\activity_file_trash}, Spec 0018
     * §9.1, issue #432).
     *
     * @param string $modname
     * @return array<string, array{component: string, filearea: string}>
     */
    public static function material_reference_specs(string $modname): array {
        $catalogclass = registry::for($modname);
        return $catalogclass === null ? [] : ($catalogclass::write_options()['material_reference_fields'] ?? []);
    }

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => new value - only the fields to change (patch, not a full state)'
            ),
            'location' => material_files::location_parameter(),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Raw write path for exactly ONE material reference pseudofield
     * ({@see self::material_reference_specs()}), with an already
     * finished file manager draft instead of material folder paths - for
     * {@see \local_coursepilot\external\restore_activity_version}, which writes files
     * back from the trash ({@see \local_coursepilot\activity_file_trash}) instead of
     * from the material folder (Spec 0018 §9.1, issue #432).
     *
     * No field-patch validation pass of its own: the caller has already
     * checked moodle/course:manageactivities and local/coursepilot:restoreversion,
     * and the draft content comes exclusively from the own change
     * history/trash, not from client input.
     *
     * @param int $cmid
     * @param string $fieldname One of this activity type's material_reference_specs() fields.
     * @param int $draftitemid Finished file manager draft, e.g. from
     *        {@see \local_coursepilot\activity_file_trash::resolve_restore_into_draft()}.
     * @return void
     */
    public static function write_pseudofield_draft(int $cmid, string $fieldname, int $draftitemid): void {
        global $CFG;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        pseudofield_carry_forward::apply($cm->modname, registry::for($cm->modname), $moduleinfo,
            self::read_settings($cmid), $cm, [$fieldname => $draftitemid]);
        $moduleinfo->{$fieldname} = $draftitemid;
        \update_moduleinfo($cm, $moduleinfo, $course);
    }

    /**
     * @param int $cmid
     * @param string $fieldsjson
     * @param string $location
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $cmid,
        string $fieldsjson,
        string $location = material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'fields_json' => $fieldsjson,
            'location' => $location,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        self::authorise($cm);

        $modname = (string) $cm->modname;
        $catalogclass = self::catalog_for($modname);
        // Cheap part of the self-release (Spec 0015 §11, ADR 0017, ticket #399):
        // blocks only THIS activity type when a detected Moodle version change
        // has produced a catalog deviation. Reading stays untouched (no
        // read tool calls assert_writable()).
        write_gate::assert_writable($modname);

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        $before = self::read_settings($cmid);
        // Rules, file checks and the native sequence live in the catalog core (#646, #647).
        $patch = write_target::update_activity(
            $catalogclass,
            $cm,
            get_course((int) $cm->course),
            $patch,
            $before,
            $params['location'],
            $params[learner_locks::PARAMETER]
        );

        $after = self::read_settings($cmid);
        [$changes, $sideeffects] = self::diff_and_side_effects($modname, $patch, $before, $after);

        return [
            'cmid' => (int) $cmid,
            'modname' => $modname,
            'message' => self::build_message($changes, $sideeffects, self::written_pseudofields($catalogclass, $patch)),
            'changes' => $changes,
            'side_effects' => $sideeffects,
        ];
    }

    /**
     * Checks context and capabilities (issue #523: extracted from execute()
     * to keep the function under the 50-line limit).
     *
     * @param \stdClass $cm
     * @return \context_module
     */
    private static function authorise(\stdClass $cm): \context_module {
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check moved forward (Spec 0015 §3.3: "in a
        // colleague's course: read yes, write no - with a clear message").
        // get_moduleinfo_data() checks the same capability again later anyway
        // via can_update_moduleinfo() - the call here is cheap
        // (only require_capability(), no DB access) and ensures that
        // a missing edit permission is not hidden behind a
        // field validation message.
        require_capability('moodle/course:manageactivities', $context);

        return $context;
    }

    /**
     * The catalog class for $modname, provided the write path is this endpoint
     * (Spec 0015 §3.1: some activity types have a single-purpose tool of
     * their own, e.g. quiz -> update_quiz_settings).
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
     * Current state as an associative array - the same composition as
     * {@see get_module_settings}, reused via its settings_json
     * instead of duplicated (ticket #384: "same shape reusable for the
     * read part").
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Before/after values per field that actually changed, plus
     * triggered side effects - from a real before/after comparison
     * (not taken from the patch itself), so that a parallel manual
     * change to another field correctly stays unmentioned and a
     * patch that merely repeats the existing value is not reported as a
     * change.
     *
     * @param string $modname
     * @param array $patch
     * @param array $before
     * @param array $after
     * @return array{0: array, 1: string[]}
     */
    private static function diff_and_side_effects(string $modname, array $patch, array $before, array $after): array {
        $changes = [];
        $sideeffects = [];
        $catalogclass = registry::for($modname);
        $triggers = $catalogclass::write_options()['side_effect_triggers'] ?? [];

        foreach (array_keys($patch) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $after[$fieldname] ?? null;
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'field' => $fieldname,
                    'before_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            if (isset($triggers[$fieldname][$newvalue]) && $oldvalue != $newvalue) {
                $sideeffects[] = $triggers[$fieldname][$newvalue];
            }
        }

        return [$changes, $sideeffects];
    }

    /**
     * The pseudofields from the patch - those the before/after comparison
     * fundamentally cannot see (#403).
     *
     * Pseudofields by definition have no column in the instance table
     * ("assignsubmission_file_enabled" lives in assign_plugin_config, the
     * choice options in choice_options). read_settings() reads the current state
     * of the database fields, where they are null both before and after - the
     * diff stays empty although something was written. A real comparison
     * would need a read layer per activity type; instead the
     * message states explicitly what it cannot compare.
     *
     * @param class-string<module_catalog> $catalogclass
     * @param array $patch
     * @return array<string, mixed> Field name => value set.
     */
    private static function written_pseudofields(string $catalogclass, array $patch): array {
        $names = array_column($catalogclass::pseudofields(), 'name');
        return array_intersect_key($patch, array_flip($names));
    }

    /**
     * The teacher-facing change message (Spec 0015 §3.3: "the response
     * is the change message").
     *
     * @param array $changes
     * @param string[] $sideeffects
     * @param array<string, mixed> $pseudofields Pseudofields written, see
     *        {@see self::written_pseudofields()} - not comparable, but set.
     * @return string
     */
    private static function build_message(array $changes, array $sideeffects, array $pseudofields = []): string {
        if (!$changes && !$pseudofields) {
            return 'No change: the patch already matched the current state.';
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['field'] . '" from ' . $change['before_json'] . ' to ' . $change['after_json'];
        }
        $message = $parts ? ('Changed: ' . implode(', ', $parts) . '.') : '';

        if ($pseudofields) {
            $set = [];
            foreach ($pseudofields as $fieldname => $value) {
                $set[] = '"' . $fieldname . '" = '
                    . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $message .= ($message ? ' ' : '')
                . 'Set, but without a database field and therefore not comparable with the previous state: '
                . implode(', ', $set) . '.';
        }

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing side-effect note'),
                'Triggered side effects from catalog category 5, empty when none were triggered'
            ),
        ]);
    }
}
