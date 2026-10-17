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

namespace local_coursepilot\catalog;

use context_module;
use core_external\external_api;
use local_coursepilot\material_files;
use local_coursepilot\write_gate;
use moodle_exception;

/**
 * Native activity settings writes, material drafts and their change report (ADR 0016).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class module_settings {
    /**
     * Material-reference fields and their native file areas for this activity type.
     *
     * @param string $modname
     * @return array<string, array{component: string, filearea: string}>
     */
    public static function material_reference_specs(string $modname): array {
        $catalogclass = registry::for($modname);
        return $catalogclass === null ? [] : ($catalogclass::write_options()['material_reference_fields'] ?? []);
    }

    /**
     * Raw write path for exactly ONE material reference pseudofield
     * ({@see self::material_reference_specs()}), with an already
     * finished file manager draft instead of material folder paths - for
     * {@see \local_coursepilot\history\version_restore}, which writes files
     * back from the trash ({@see \local_coursepilot\activity_file_trash}) instead of
     * from the material folder (Spec 0018 §9.1).
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
        pseudofield_carry_forward::apply(
            $cm->modname,
            registry::for($cm->modname),
            $moduleinfo,
            self::read_settings($cmid),
            $cm,
            [$fieldname => $draftitemid]
        );
        $moduleinfo->{$fieldname} = $draftitemid;
        \update_moduleinfo($cm, $moduleinfo, $course);
    }

    /**
     * Patch settings through the catalog write target and report actual changes.
     *
     * @param int $cmid
     * @param string $fieldsjson
     * @param string $location
     * @param string[] $confirmlearnerlocks
     * @return mixed[]
     */
    public static function write(
        int $cmid,
        string $fieldsjson,
        string $location = material_files::LOCATION_STORE,
        array $confirmlearnerlocks = []
    ): array {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        self::authorise($cm);

        $modname = (string) $cm->modname;
        $catalogclass = self::catalog_for($modname);
        // Cheap part of the self-release (Spec 0015 §11, ADR 0017):
        // blocks only THIS activity type when a detected Moodle version change
        // has produced a catalog deviation. Reading stays untouched (no
        // read tool calls assert_writable()).
        write_gate::assert_writable($modname);

        $patch = json_decode($fieldsjson, true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        $before = self::read_settings($cmid);
        // Rules, file checks and the native sequence live in the catalog core.
        $patch = write_target::update_activity(
            $catalogclass,
            $cm,
            get_course((int) $cm->course),
            $patch,
            $before,
            $location,
            $confirmlearnerlocks
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
     * Check permissions before patch validation can mask a missing edit capability.
     *
     * @param \stdClass $cm
     * @return \context_module
     */
    private static function authorise(\stdClass $cm): \context_module {
        $context = context_module::instance($cm->id);
        external_api::validate_context($context);
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
    public static function catalog_for(string $modname): string {
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
     * Current catalog state as an associative array for the before/after comparison.
     *
     * @param int $cmid
     * @return mixed[]
     */
    private static function read_settings(int $cmid): array {
        $result = module_state::read_settings($cmid);
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
     * @param mixed[] $patch
     * @param mixed[] $before
     * @param mixed[] $after
     * @return array{0: mixed[], 1: string[]}
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
     * fundamentally cannot see.
     *
     * Pseudofields by definition have no column in the instance table
     * ("assignsubmission_file_enabled" lives in assign_plugin_config, the
     * choice options in choice_options). read_settings() reads the current state
     * of the database fields, where they are null both before and after - the
     * diff stays empty although something was written. A real comparison
     * would need a read layer per activity type; instead the
     * message states explicitly what it cannot compare.
     *
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param mixed[] $patch
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
     * @param mixed[] $changes
     * @param string[] $sideeffects
     * @param mixed[] $pseudofields Pseudofields written, see
     *        {@see self::written_pseudofields()} - not comparable, but set.
     * @return string
     */
    private static function build_message(array $changes, array $sideeffects, array $pseudofields = []): string {
        if (!$changes && !$pseudofields) {
            return get_string('modulenochange', 'local_coursepilot');
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = get_string('modulefieldchange', 'local_coursepilot', (object) [
                'field' => $change['field'], 'before' => $change['before_json'], 'after' => $change['after_json'],
            ]);
        }
        $message = $parts ? get_string('modulechangedfields', 'local_coursepilot', implode(', ', $parts)) : '';

        if ($pseudofields) {
            $message .= ($message ? ' ' : '') . self::pseudofield_message($pseudofields);
        }

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * Explain writes that the state comparison cannot observe.
     *
     * @param mixed[] $pseudofields
     * @return string
     */
    private static function pseudofield_message(array $pseudofields): string {
        $set = [];
        foreach ($pseudofields as $fieldname => $value) {
            $set[] = get_string('modulepseudofield', 'local_coursepilot', (object) [
                'field' => $fieldname,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
        return get_string('modulepseudofieldswritten', 'local_coursepilot', implode(', ', $set));
    }
}
