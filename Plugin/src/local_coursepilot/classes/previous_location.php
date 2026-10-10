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

namespace local_coursepilot;

/**
 * The legacy files (Issue #498, Spec #486 §9): the context files that after
 * a location change still lie at the previous location. Concerns only the
 * context area - the material stock has no legacy files (the old
 * material root in Moodle is the workbench and stays).
 *
 * No storage space of its own: the previous location is in the field
 * `previous_location` of the context pointer document ({@see storage_anchor::write_pointer_document()}),
 * written exclusively by {@see location_selection::apply()} on
 * completion. There is only ever one - a new switch displaces it,
 * the files of the displaced location stay untouched (Spec §9).
 *
 * Ends only explicitly, via {@see dismiss()} - never by the passage of time,
 * never by name equality (the same principle as {@see pending_write_notice}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class previous_location {
    /**
     * The raw value of the field "previous_location" in the context pointer document,
     * or null if no legacy files are open (no pointer, pointer of the
     * first version, or the field is missing/invalid).
     *
     * @return array|null
     */
    public static function current(): ?array {
        $document = storage_anchor::read_raw_pointer();
        $value = $document['previous_location'] ?? null;
        return is_array($value) ? $value : null;
    }

    /**
     * Whether legacy files are open - the fact for `coursepilot_list_skills`
     * (Issue #498 acceptance criterion: "without counting").
     *
     * @return bool
     */
    public static function open(): bool {
        return self::current() !== null;
    }

    /**
     * The resolved previous location, for the read-only switch on
     * `list_context_files`/`read_context_file` (Spec §6/§9). Only applies
     * while legacy files are open - without open legacy files a named
     * error instead of a silent empty result.
     * @return pointer_location
     * @throws \moodle_exception previouslocationclosed, or as {@see context_pointer::resolve_previous()}.
     */
    public static function require_open_location(): pointer_location {
        $value = self::current();
        if ($value === null) {
            throw new \moodle_exception('previouslocationclosed', 'local_coursepilot');
        }
        return context_pointer::resolve_previous($value);
    }

    /**
     * Explicitly ends the legacy files (Spec §9: "Ends only explicitly") -
     * removes only the field "previous_location" from the pointer document, leaves
     * context area, material stock and location history unchanged. Never touches
     * the files of the previous location themselves (Spec §9: "its files
     * stay untouched").
     *
     * @return bool true if open legacy files were removed; false
     *         if none were open.
     */
    public static function dismiss(): bool {
        $document = storage_anchor::read_raw_pointer();
        if ($document === null || ($document['previous_location'] ?? null) === null) {
            return false;
        }
        unset($document['previous_location']);
        storage_anchor::write_pointer_document($document);
        return true;
    }
}
