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

namespace local_coursepilot\history;

defined('MOODLE_INTERNAL') || die();

/**
 * Positive history file allowlist, shared by capture and reads of historical states.
 * Personal-data settings never expand the teaching-design file areas.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class file_policy {
    /** Whether this component/file area belongs to the activity's restorable design. */
    public static function allows(string $modname, string $component, string $filearea): bool {
        if ($component === 'mod_' . $modname && $filearea === 'intro') {
            return true;
        }
        $catalog = \local_coursepilot\catalog\registry::for($modname);
        $specs = $catalog === null ? [] : ($catalog::write_options()['material_reference_fields'] ?? []);
        foreach ($specs as $spec) {
            if ($component === $spec['component'] && $filearea === $spec['filearea']) {
                return true;
            }
        }
        return false;
    }
}
