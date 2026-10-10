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

namespace local_coursepilot;

/**
 * Copies material into declared activity areas after a successful XML round trip.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_file_supplement {
    /** @var array<string, class-string> Per-kind file declarations and native completion adapters. */
    private const TYPES = ['lightboxgallery' => activity_files\lightboxgallery::class];

    /**
     * Provides supports.
     *
     * @param string $modname The modname.
     * @return bool
     */
    public static function supports(string $modname): bool {
        return isset(self::TYPES[$modname]);
    }

    /**
     * Rejects what can be judged without the new activity, so nothing is restored for refused input.
     *
     * The capability is checked at course level here (module capabilities inherit from it); apply()
     * repeats it on the new module context.
     *
     * @param string $modname The modname.
     * @param \context $context The context.
     * @param mixed[] $entries The entries.
     */
    public static function validate(string $modname, \context $context, array $entries): void {
        if (!$entries) {
            return;
        }
        $type = self::TYPES[$modname] ?? null;
        if ($type === null) {
            throw new \moodle_exception('activityfilesunsupported', 'local_coursepilot');
        }
        material_files::require_manage_own_files();
        require_capability($type::CAPABILITY, $context);
        $seen = [];
        foreach ($entries as $entry) {
            if (!array_key_exists($entry['filearea'], $type::FILEAREAS)) {
                throw new \moodle_exception('activityfileinvalidarea', 'local_coursepilot', '', $entry['filearea']);
            }
            $filename = basename(material_files::normalise_path($entry['path']));
            $key = $entry['filearea'] . '/' . $filename;
            if (isset($seen[$key])) {
                throw new \moodle_exception('activityfileduplicate', 'local_coursepilot', '', $filename);
            }
            $seen[$key] = true;
        }
    }

    /**
     * Only the new, still hidden activity owned by this create call may be passed.
     *
     * @param \stdClass $cm The cm.
     * @param mixed[] $entries The entries.
     */
    public static function apply(\stdClass $cm, array $entries): void {
        global $DB;
        if (!$entries) {
            return;
        }
        $type = self::TYPES[$cm->modname];
        $context = \context_module::instance($cm->id);
        self::validate($cm->modname, $context, $entries);
        // Backup DDL has finished. This transaction contains only the supplement's writes,
        // including temporary drafts, so a missing later path leaves no partial supplement.
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($entries as $entry) {
                self::copy($cm, $context, $type, $entry);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Copies the activity file supplement.
     *
     * @param \stdClass $cm The cm.
     * @param \context_module $context The context.
     * @param string $type The type.
     * @param mixed[] $entry The entry.
     */
    private static function copy(\stdClass $cm, \context_module $context, string $type, array $entry): void {
        $fs = get_file_storage();
        $area = $entry['filearea'];
        $itemid = $type::FILEAREAS[$area];
        $component = 'mod_' . $cm->modname;
        $draftid = material_files::resolve_into_draft(
            $context->id,
            $component,
            $area,
            $itemid,
            [$entry['path']],
            $entry['location'] ?? material_files::LOCATION_STORE
        );
        try {
            file_save_draft_area_files($draftid, $context->id, $component, $area, $itemid);
            $filename = basename(material_files::normalise_path($entry['path']));
            $file = $fs->get_file($context->id, $component, $area, $itemid, '/', $filename);
            $type::complete($cm, $file, $entry['caption'] ?? '');
        } finally {
            $fs->delete_area_files(material_files::own_context()->id, 'user', 'draft', $draftid);
        }
    }
}
