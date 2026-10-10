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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\activity_file_trash;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use local_coursepilot\history\version_history;
use local_coursepilot\quiz\arrangement;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * "Three versions ago it was better" as an executable write
 * (Spec 0015 §10.7, ticket #395, phase 4): the old state is carried forward
 * as the new latest version instead of rewinding the activity or
 * duplicating it as a backup copy - cmid stays stable, links and
 * prerequisites stay valid.
 *
 * No write mechanism of its own: the target state is split into two patches
 * and set exclusively through the existing write paths - the "normal"
 * instance fields via {@see update_module_settings::execute()}, the five
 * completion fields via {@see set_completion::execute()} (ticket #392, the
 * only write path for them). This endpoint therefore inherits every
 * validation, every side-effect message and the course_module_updated
 * observer, which records the write-back itself as a new version (#385) -
 * no special handling needed.
 *
 * Completion guard rail (Spec 0015 §8): the completion fields go through
 * exactly the same two-step flow as any other set_completion() call
 * (ticket #392) - "confirmed" is passed through unchanged instead of being
 * hard-set to true here. If the write would delete existing completion
 * data of learners and "confirmed" is not set, set_completion writes
 * nothing and reports the number of affected learners - exactly that
 * message appears in this endpoint's response (instead of a weaker one of
 * its own). Without data-loss risk (no existing completion data, or only
 * "completionexpected" differs) the restore runs through immediately like
 * any other set_completion() call - "completionunlocked is never applied
 * automatically" is set_completion's own rule, which this endpoint
 * inherits unchanged instead of tightening or bypassing it.
 *
 * Own capability local/coursepilot:restoreversion instead of local/coursepilot:use
 * (Spec 0015 §10.7: the restore is a separate, consequential write) - the
 * actual write-back additionally requires moodle/course:manageactivities
 * via the endpoints it calls.
 *
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class restore_activity_version extends external_api {
    /**
     * The completion fields (identical to
     * {@see set_completion}::ALLOWED_FIELDS plus its module-specific
     * fields, ticket #461) - kept separately here because set_completion
     * holds them as private constants and this endpoint only needs them to
     * keep them out of the generic patch and handle them separately.
     * "completionsubmit" is not in the version state at all for any other
     * activity type, so it drops out there already via the
     * array_key_exists() check.
     *
     * @var string[]
     */
    private const COMPLETION_FIELDS = [
        'completion',
        'completionview',
        'completionusegrade',
        'completionpassgrade',
        'completionexpected',
        'completionsubmit',
    ];

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'target_version' => new external_value(PARAM_INT, 'Version number to write back to'),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms deleting existing completion data of learners, if writing back the '
                    . 'completion fields would trigger that (two-step confirmation of set_completion). Without '
                    . 'data-loss risk this parameter has no effect. Omit or false on the first call.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Runs the restore activity version tool.
     *
     * @param int $cmid
     * @param int $targetversion
     * @param bool $confirmed
     * @return array
     */
    public static function execute(int $cmid, int $targetversion, bool $confirmed = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'target_version' => $targetversion,
            'confirmed' => $confirmed,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:restoreversion', $context);
        // Native permission check moved forward, like every other write tool
        // (Spec 0015 §3.3) - the actual write calls
        // (update_module_settings/set_completion) check it again anyway.
        require_capability('moodle/course:manageactivities', $context);

        $modname = (string) $cm->modname;

        // Per ADR 0016, quiz has its own write path for settings
        // (update_quiz_settings) - just like any call with a catalog write
        // route (see self::catalog_for()), this endpoint's field patch is
        // therefore blocked for quiz, unchanged since ticket #395/#385
        // (acceptance criterion 7). What #396 ADDS is independent of that:
        // the arrangement (slots/question references/sections/feedback) -
        // that needs neither the field catalog nor update_module_settings.
        $catalogclass = registry::for($modname);
        if ($catalogclass !== null && ($catalogclass::write_options()['restores_arrangement'] ?? false)) {
            return self::execute_quiz_arrangement_only($cm, $params['target_version']);
        }

        $catalogclass = self::catalog_for($modname);

        $target = version_history::state_at($params['cmid'], $params['target_version']);
        $before = self::read_settings($params['cmid']);

        $normalpatch = self::build_normal_patch($catalogclass, $before, $target);
        $completionpatch = self::build_completion_patch($before, $target);

        $changes = [];
        if ($normalpatch) {
            $result = update_module_settings::execute($params['cmid'], json_encode($normalpatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $changes = array_merge($changes, $result['changes']);
        }

        $restoredfiles = self::restore_files($cm, $context, $params['target_version']);

        $completionwarning = null;
        if ($completionpatch) {
            try {
                $result = set_completion::execute(
                    $params['cmid'],
                    json_encode($completionpatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $params['confirmed']
                );
                $changes = array_merge($changes, $result['changes']);
            } catch (moodle_exception $e) {
                // set_completion's own two-step flow applies (ticket #392): without
                // confirmation AND a real data-loss risk it writes nothing and
                // reports the number of affected learners - exactly the "data-loss
                // warning" this endpoint is meant to reuse instead of inventing
                // its own. Anything else (e.g. completion disabled in the
                // course) is a real error of this call.
                if ($e->errorcode !== 'completiondatalossconfirmationrequired') {
                    throw $e;
                }
                $completionwarning = $e->getMessage();
            }
        }

        return [
            'cmid' => $params['cmid'],
            'modname' => $modname,
            'message' => self::build_message($params['target_version'], $changes, $completionwarning, null, $restoredfiles),
            'changes' => $changes,
        ];
    }

    /**
     * Brings back files the target state had in an unlocked material
     * reference field (Spec 0018 §9.1, issue #432): for each
     * {@see update_module_settings::material_reference_specs()} field of this
     * activity type, it compares which target files (from
     * {@see version_history::files_at()}, only gap=0 rows - the others
     * are a documented gap) are currently missing. If one is missing and
     * sits in the recycle bin ({@see activity_file_trash::find_for_restore()}),
     * it is written back - all files that stayed unchanged are left
     * untouched. If a target file is missing AND cannot be found in the
     * recycle bin, the existing gap stays (no error, no regression).
     *
     * @param \stdClass $cm
     * @param \context_module $context
     * @param int $targetversion
     * @return string[] File names that were actually restored.
     */
    private static function restore_files(\stdClass $cm, context_module $context, int $targetversion): array {
        $modname = (string) $cm->modname;
        $specs = update_module_settings::material_reference_specs($modname);
        if (!$specs) {
            return [];
        }

        $targetfiles = version_history::files_at((int) $cm->id, $targetversion);
        $fs = get_file_storage();
        $restoredfilenames = [];

        foreach ($specs as $fieldname => $spec) {
            $wanted = array_filter($targetfiles, static fn(\stdClass $f): bool =>
                $f->component === $spec['component'] && $f->filearea === $spec['filearea'] && (int) $f->gap === 0);
            if (!$wanted) {
                continue;
            }

            $tobuild = [];
            $changedfilenames = [];
            foreach ($wanted as $target) {
                $current = $fs->get_file($context->id, $spec['component'], $spec['filearea'], 0, '/', $target->filename);
                if ($current && $current->get_contenthash() === $target->contenthash) {
                    // Already the target state - carry over untouched.
                    $tobuild[] = $current;
                    continue;
                }

                $fromtrash = activity_file_trash::find_for_restore(
                    $context->id,
                    (int) $cm->id,
                    $target->filename,
                    $target->contenthash
                );
                if ($fromtrash) {
                    $tobuild[] = $fromtrash;
                    $changedfilenames[] = $target->filename;
                } else if ($current) {
                    // Not found in the recycle bin (e.g. never replaced,
                    // but the content differs anyway) - better to keep the
                    // existing current state than to lose the file
                    // entirely.
                    $tobuild[] = $current;
                }
            }

            if (!$changedfilenames) {
                // Nothing actually differs - no unnecessary write.
                continue;
            }

            $draftitemid = activity_file_trash::resolve_restore_into_draft(
                $context->id,
                $spec['component'],
                $spec['filearea'],
                $tobuild
            );
            update_module_settings::write_pseudofield_draft((int) $cm->id, $fieldname, $draftitemid);
            $restoredfilenames = array_merge($restoredfilenames, $changedfilenames);
        }

        return $restoredfilenames;
    }

    /**
     * The complete write-back for quiz (#396): ONLY the arrangement, no
     * field patch (see the class doc of this method's call site in
     * {@see self::execute()} - ADR 0016, acceptance criterion 7 "settings
     * stay unchanged as in ticket 07"). If there is no arrangement that
     * differs from the target state, the message stays the same as for any
     * other call of this endpoint for quiz: "writevehicleblocked" -
     * this endpoint fundamentally cannot write back anything but the
     * arrangement for quiz.
     *
     * @param \stdClass $cm
     * @param int $targetversion
     * @return array
     * @throws moodle_exception arrangementrestoreblocked if the quiz already has attempts and
     *         the arrangement differs; writevehicleblocked if the arrangement does not differ.
     */
    private static function execute_quiz_arrangement_only(\stdClass $cm, int $targetversion): array {
        $arrangementmessage = self::restore_quiz_arrangement($cm, $targetversion);
        if ($arrangementmessage === null) {
            throw new moodle_exception('writevehicleblocked', 'local_coursepilot', '', [
                'modname' => 'quiz',
                'write_route' => 'update_quiz_settings',
            ]);
        }

        return [
            'cmid' => (int) $cm->id,
            'modname' => 'quiz',
            'message' => self::build_message($targetversion, [], null, $arrangementmessage),
            'changes' => [],
        ];
    }

    /**
     * Writes the arrangement state (ticket #396) back if the target state
     * has a differing arrangement. If an older state lacks the
     * arrangement_json (created before #396) or the arrangement already
     * matches the current state, nothing is done - that belongs to the
     * documented history gaps
     * ({@see version_history} gap notice).
     *
     * @param \stdClass $cm
     * @param int $targetversion
     * @return string|null Additional sentence for the response, or null without an arrangement change.
     * @throws moodle_exception arrangementrestoreblocked if the quiz already has attempts.
     */
    private static function restore_quiz_arrangement(\stdClass $cm, int $targetversion): ?string {
        $target = version_history::arrangement_at((int) $cm->id, $targetversion);
        if ($target === null) {
            return null;
        }

        $quizid = (int) $cm->instance;
        if (!arrangement::differs(arrangement::capture($quizid), $target)) {
            return null;
        }

        // Throws arrangementrestoreblocked BEFORE any write attempt if the
        // quiz already has attempts (attempts guard rail, #396) - no
        // caught core API exception.
        arrangement::restore($quizid, $target);

        return 'The question arrangement was also restored to version ' . $targetversion . '. '
            . 'Note: questions appear in their latest version; no version is pinned retroactively.';
    }

    /**
     * Identical to {@see update_module_settings::catalog_for()} - duplicated
     * instead of shared (both classes stay readable on their own, see the
     * class doc of this file "no write mechanism of its own").
     *
     * @param string $modname
     * @return class-string<\local_coursepilot\catalog\module_catalog>
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
     * Current state as an associative array, the same shape as returned by
     * {@see version_history::state_at()} - for the before/after
     * comparison when building the patch.
     *
     * @param int $cmid
     * @return array
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * All non-completion fields that actually differ between $before and
     * $target - exactly the set of fields update_module_settings accepts as
     * a patch (shared block plus catalog fields/pseudofields, without the
     * blocklist). A catalog field that sits under a different key in the
     * target state (e.g. "sectionnum" - the target state only knows
     * "section") is automatically left out instead of being written wrongly.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<\local_coursepilot\catalog\module_catalog> $catalogclass
     * @param array $before
     * @param array $target
     * @return array
     */
    private static function build_normal_patch(string $catalogclass, array $before, array $target): array {
        $blocklist = array_unique(array_merge(shared_block::BLOCKLIST, $catalogclass::blocklist()));
        $fields = array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());

        $patch = [];
        foreach ($fields as $field) {
            $name = $field->name;
            if (in_array($name, $blocklist, true) || !array_key_exists($name, $target)) {
                continue;
            }
            $newvalue = $target[$name];
            if (($before[$name] ?? null) != $newvalue) {
                $patch[$name] = $newvalue;
            }
        }

        return $patch;
    }

    /**
     * The completion fields that actually differ between $before and $target -
     * independent of "confirmed": whether they are actually written is decided
     * by {@see self::execute()}.
     *
     * @param array $before
     * @param array $target
     * @return array
     */
    private static function build_completion_patch(array $before, array $target): array {
        $patch = [];
        foreach (self::COMPLETION_FIELDS as $name) {
            if (!array_key_exists($name, $target)) {
                continue;
            }
            $newvalue = (int) $target[$name];
            if ((int) ($before[$name] ?? 0) !== $newvalue) {
                $patch[$name] = $newvalue;
            }
        }
        return $patch;
    }

    /**
     * The teacher-facing change message (Spec 0015: "the response is the
     * change message") - appends set_completion's real data-loss warning
     * (with the number of affected learners) instead of inventing a weaker
     * message of its own.
     *
     * @param int $targetversion
     * @param array $changes
     * @param string|null $completionwarning set_completion's message if its own
     *        two-step flow prevented writing the completion fields.
     * @param string|null $arrangementmessage Additional sentence from {@see self::restore_quiz_arrangement()}.
     * @param string[] $restoredfiles File names that {@see self::restore_files()} brought back from the
     *        recycle bin (Spec 0018 §9.1, issue #432).
     * @return string
     */
    private static function build_message(
        int $targetversion,
        array $changes,
        ?string $completionwarning,
        ?string $arrangementmessage = null,
        array $restoredfiles = []
    ): string {
        if (!$changes && !$restoredfiles && $arrangementmessage === null) {
            $base = 'No change: the activity already matches version ' . $targetversion . '.';
        } else if (!$changes && !$restoredfiles) {
            $base = 'Restored to version ' . $targetversion . ' - no settings fields differ.';
        } else {
            $parts = [];
            foreach ($changes as $change) {
                $parts[] = '"' . $change['field'] . '" from ' . $change['before_json'] . ' to ' . $change['after_json'];
            }
            $base = $parts
                ? ('Restored to version ' . $targetversion . ' - the old state becomes the new latest '
                    . 'version: ' . implode(', ', $parts) . '.')
                : ('Restored to version ' . $targetversion . '.');
        }

        if ($restoredfiles) {
            $base .= ' File' . (count($restoredfiles) === 1 ? '' : 's') . ' restored from the recycle bin: '
                . implode(', ', $restoredfiles) . '.';
        }

        if ($completionwarning !== null) {
            $base .= ' Completion fields not restored: ' . $completionwarning
                . ' Calling restore_activity_version again with "confirmed": true restores them as well.';
        }

        if ($arrangementmessage !== null) {
            $base .= ' ' . $arrangementmessage;
        }

        return $base;
    }

    /**
     * Describes the return value of execute.
     *
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
        ]);
    }
}
