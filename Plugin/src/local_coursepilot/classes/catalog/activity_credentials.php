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

/**
 * Output policy for activity credentials; snapshots and native writes remain raw.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_credentials {
    /** @var array<string, string[]> Credential columns declared once per activity type. */
    private const HIDDEN = [
        'lti' => ['password', 'resourcekey', 'servicesalt'],
        'lesson' => ['password'],
        'bigbluebuttonbn' => ['moderatorpass', 'viewerpass', 'guestpassword', 'guestlinkuid'],
    ];

    /**
     * Quiz access passwords are intentional catalog settings and learner locks.
     * Uncatalogued credential-shaped columns fail closed; reviewed catalogs keep their settings.
     *
     * @param string $modname
     * @param string $field
     * @return bool
     */
    public static function hidden(string $modname, string $field): bool {
        if ($modname === 'quiz' && $field === 'password') {
            return false;
        }
        return in_array($field, self::HIDDEN[$modname] ?? [], true)
            || (registry::for($modname) === null
                && preg_match('/(?:password|secret|salt|key|token|pass)(?:_|$)/i', $field) === 1);
    }

    /**
     * Omit credentials rather than returning placeholders that could be written back.
     *
     * @param string $modname
     * @param mixed[] $state
     * @return mixed[]
     */
    public static function settings(string $modname, array $state): array {
        return array_filter($state, static fn(string $field): bool => !self::hidden($modname, $field), ARRAY_FILTER_USE_KEY);
    }
}
