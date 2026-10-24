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

namespace local_coursepilot\history;

use context_module;
use core_external\external_api;
use local_coursepilot\activity_file_trash;
use local_coursepilot\catalog\completion_settings;
use local_coursepilot\catalog\module_settings;
use local_coursepilot\catalog\module_state;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;
use local_coursepilot\quiz\arrangement;
use moodle_exception;

/**
 * Carry a saved activity state forward through the catalog's native writes (ADR 0016).
 * Restore preserves existing completion confirmations, file gaps and quiz arrangement rules.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_restore {
    /**
     * Carry a saved state forward, keeping the native write and confirmation rules.
     *
     * @param int $cmid
     * @param int $targetversion
     * @param bool $confirmed
     * @return mixed[]
     */
    public static function restore(int $cmid, int $targetversion, bool $confirmed = false): array {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        external_api::validate_context($context);
        require_capability('local/coursepilot:restoreversion', $context);
        // Check editing permission before reading the target or preparing a patch.
        require_capability('moodle/course:manageactivities', $context);

        $modname = (string) $cm->modname;

        // ADR 0016 keeps quiz settings on their dedicated route; only its arrangement is restored here.
        $catalogclass = registry::for($modname);
        if ($catalogclass !== null && ($catalogclass::write_options()['restores_arrangement'] ?? false)) {
            return self::execute_quiz_arrangement_only($cm, $targetversion);
        }

        $catalogclass = module_settings::catalog_for($modname);

        $target = version_history::state_at($cmid, $targetversion);
        $before = self::read_settings($cmid);

        $normalpatch = self::build_normal_patch($catalogclass, $before, $target);
        $completionpatch = self::build_completion_patch($before, $target);

        $changes = [];
        if ($normalpatch) {
            $result = module_settings::write(
                $cmid,
                json_encode($normalpatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            $changes = array_merge($changes, $result['changes']);
        }

        $restoredfiles = self::restore_files($cm, $context, $targetversion);

        $completionwarning = null;
        if ($completionpatch) {
            try {
                $result = completion_settings::write(
                    $cmid,
                    json_encode($completionpatch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $confirmed
                );
                $changes = array_merge($changes, $result['changes']);
            } catch (moodle_exception $e) {
                // Preserve completion's existing partial-restore warning; other failures remain errors.
                if ($e->errorcode !== 'completiondatalossconfirmationrequired') {
                    throw $e;
                }
                $completionwarning = $e->getMessage();
            }
        }

        return [
            'cmid' => $cmid,
            'modname' => $modname,
            'message' => self::build_message($targetversion, $changes, $completionwarning, null, $restoredfiles),
            'changes' => $changes,
        ];
    }

    /**
     * Brings back files the target state had in an unlocked material
     * reference field (Spec 0018 §9.1): for each
     * {@see module_settings::material_reference_specs()} field of this
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
        $specs = module_settings::material_reference_specs($modname);
        if (!$specs) {
            return [];
        }

        $targetfiles = version_history::files_at((int) $cm->id, $targetversion);
        $restoredfilenames = [];

        foreach ($specs as $fieldname => $spec) {
            $wanted = array_filter($targetfiles, static fn(\stdClass $f): bool =>
                $f->component === $spec['component'] && $f->filearea === $spec['filearea'] && (int) $f->gap === 0);
            if (!$wanted) {
                continue;
            }

            [$tobuild, $changedfilenames] = self::files_to_restore($cm, $context, $spec, $wanted);

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
            module_settings::write_pseudofield_draft((int) $cm->id, $fieldname, $draftitemid);
            $restoredfilenames = array_merge($restoredfilenames, $changedfilenames);
        }

        return $restoredfilenames;
    }

    /**
     * Resolve target files from current storage or trash, retaining unrecoverable current files.
     *
     * @param \stdClass $cm
     * @param context_module $context
     * @param string[] $spec Component and filearea of the material-reference field.
     * @param \stdClass[] $wanted
     * @return array{0: \stored_file[], 1: string[]}
     */
    private static function files_to_restore(\stdClass $cm, context_module $context, array $spec, array $wanted): array {
        $tobuild = [];
        $changedfilenames = [];
        foreach ($wanted as $target) {
            $current = get_file_storage()->get_file($context->id, $spec['component'], $spec['filearea'], 0, '/', $target->filename);
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
        return [$tobuild, $changedfilenames];
    }

    /**
     * Restore only quiz arrangement; unchanged or absent arrangement cannot use this write route.
     *
     * @param \stdClass $cm
     * @param int $targetversion
     * @return mixed[]
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
     * Restore a differing arrangement, retaining gaps and the native attempts guard.
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
        // quiz already has attempts - no
        // caught core API exception.
        arrangement::restore($quizid, $target);

        return get_string('restorearrangement', 'local_coursepilot', $targetversion);
    }

    /**
     * Current state as an associative array, the same shape as returned by
     * {@see version_history::state_at()} - for the before/after
     * comparison when building the patch.
     *
     * @param int $cmid
     * @return mixed[]
     */
    private static function read_settings(int $cmid): array {
        $result = module_state::read_settings($cmid);
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
     * @param string $catalogclass Type: class-string<\local_coursepilot\catalog\module_catalog>.
     * @param mixed[] $before
     * @param mixed[] $target
     * @return mixed[]
     */
    private static function build_normal_patch(string $catalogclass, array $before, array $target): array {
        $blocklist = array_unique(array_merge(shared_block::blocked_fields(), $catalogclass::blocklist()));
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
     * by {@see self::restore()}.
     *
     * @param mixed[] $before
     * @param mixed[] $target
     * @return mixed[]
     */
    private static function build_completion_patch(array $before, array $target): array {
        $patch = [];
        $fields = array_intersect_key(completion_settings::allowed_fields((string) $before['modulename']), $target);
        foreach (array_keys($fields) as $name) {
            $newvalue = (int) $target[$name];
            if ((int) ($before[$name] ?? 0) !== $newvalue) {
                $patch[$name] = $newvalue;
            }
        }
        return $patch;
    }

    /**
     * The teacher-facing change message (Spec 0015: "the response is the
     * change message") - appends completion_settings' real data-loss warning
     * (with the number of affected learners) instead of inventing a weaker
     * message of its own.
     *
     * @param int $targetversion
     * @param mixed[] $changes
     * @param string|null $completionwarning completion_settings' message if its own
     *        two-step flow prevented writing the completion fields.
     * @param string|null $arrangementmessage Additional sentence from {@see self::restore_quiz_arrangement()}.
     * @param string[] $restoredfiles File names that {@see self::restore_files()} brought back from the
     *        recycle bin (Spec 0018 §9.1).
     * @return string
     */
    private static function build_message(
        int $targetversion,
        array $changes,
        ?string $completionwarning,
        ?string $arrangementmessage = null,
        array $restoredfiles = []
    ): string {
        $base = self::settings_message($targetversion, $changes, $restoredfiles, $arrangementmessage);

        if ($restoredfiles) {
            $base .= ' ' . get_string(
                count($restoredfiles) === 1 ? 'restorefile' : 'restorefiles',
                'local_coursepilot',
                implode(', ', $restoredfiles)
            );
        }

        if ($completionwarning !== null) {
            $base .= ' ' . get_string('restorecompletionwarning', 'local_coursepilot', $completionwarning);
        }

        if ($arrangementmessage !== null) {
            $base .= ' ' . $arrangementmessage;
        }

        return $base;
    }

    /**
     * Describe the restored settings or the unchanged state using the existing response vocabulary.
     *
     * @param int $targetversion
     * @param mixed[] $changes
     * @param string[] $restoredfiles
     * @param string|null $arrangementmessage
     * @return string
     */
    private static function settings_message(
        int $targetversion,
        array $changes,
        array $restoredfiles,
        ?string $arrangementmessage
    ): string {
        $haschanges = $changes || $restoredfiles;
        if (!$haschanges && $arrangementmessage === null) {
            $base = get_string('restorenochange', 'local_coursepilot', $targetversion);
        } else if (!$haschanges) {
            $base = get_string('restorenosettings', 'local_coursepilot', $targetversion);
        } else {
            $parts = [];
            foreach ($changes as $change) {
                $parts[] = get_string('modulefieldchange', 'local_coursepilot', (object) [
                    'field' => $change['field'], 'before' => $change['before_json'], 'after' => $change['after_json'],
                ]);
            }
            $base = $parts
                ? get_string('restoresettings', 'local_coursepilot', (object) [
                    'version' => $targetversion, 'changes' => implode(', ', $parts),
                ])
                : get_string('restoreversion', 'local_coursepilot', $targetversion);
        }

        return $base;
    }
}
