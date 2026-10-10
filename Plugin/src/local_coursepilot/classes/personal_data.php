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
 * The switch for personal context data (issue #344, ADR 0011).
 * Acts on the mark in the YAML front matter of a context file
 * (`coursepilot.personenbezug: true`), not on its content - see
 * specification 0010, section "Frontmatter"/the personal-data variants section.
 *
 * ponytail: no YAML parser in the project (no symfony/yaml, no
 * ext-yaml) and not needed for a single, clearly named flag check
 * either - a regex on the front matter block is enough.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personal_data {
    /**
     * Whether the instance delivers context files marked as personal data
     * to the AI. Default: off (see `settings.php`).
     *
     * @return bool
     */
    public static function allowed(): bool {
        return (bool) get_config('local_coursepilot', 'allowpersonaldata');
    }

    /**
     * Whether the file content is marked as personal data in the YAML front matter
     * (`coursepilot.personenbezug: true`).
     *
     * @param string $content
     * @return bool
     */
    public static function is_marked(string $content): bool {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
            return false;
        }
        return (bool) preg_match('/personenbezug:\s*true\b/', $matches[1]);
    }
}
