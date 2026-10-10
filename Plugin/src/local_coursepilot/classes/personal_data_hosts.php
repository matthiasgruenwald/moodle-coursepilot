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
 * Approved external hosts for personal context data (Issue #493,
 * ADR 0021 §3, Spec #486 §6/§11). The school lists one domain per line in
 * `local_coursepilot | personaldatahosts`. Each domain includes subdomains,
 * matched at dot boundaries without wildcards. An empty list allows only
 * Private Files, which context-tool callers always approve; this class
 * manages only the external host list.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personal_data_hosts {
    /**
     * Whether a resolved WebDAV host is approved: an exact configured domain
     * or one of its subdomains ({@see \local_coursepilot\webdav\webdav_instance::resolve()}).
     *
     * @param string $host
     * @return bool
     */
    public static function allowed(string $host): bool {
        $host = self::normalise($host);
        if ($host === '') {
            return false;
        }
        foreach (self::configured() as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reject an external location whose host is not approved (Issue #493,
     * ADR 0021 §3). Private Files (any non-EXTERNAL location) are always approved.
     * Shared by write_context_file and append_context_file, which must check
     * the entire resulting file, including append and copy (Spec #486 §6).
     *
     * @param pointer_location|null $location
     * @param string $path Client path for the error message.
     * @throws \moodle_exception contextfilehostnotallowed
     */
    public static function require_allowed_location(?pointer_location $location, string $path): void {
        if (
            $location !== null && $location->kind === pointer_location::EXTERNAL
                && !self::allowed((string) ($location->fingerprint['server'] ?? ''))
        ) {
            throw new \moodle_exception('contextfilehostnotallowed', 'local_coursepilot', '', $path);
        }
    }

    /**
     * Returns configured domains, lowercased, with empty lines removed.
     *
     * @return string[] Configured domains, lowercased, with empty lines removed.
     */
    public static function configured(): array {
        return self::parse((string) (get_config('local_coursepilot', 'personaldatahosts') ?: ''));
    }

    /**
     * Check an unsaved setting for single-part names or wildcard entries,
     * both of which are rejected when saving.
     *
     * @param string $raw Raw setting value, one entry per line.
     * @return string|null First invalid entry, or null if all are valid.
     */
    public static function first_invalid_entry(string $raw): ?string {
        foreach (self::parse($raw) as $domain) {
            if (!str_contains($domain, '.') || str_contains($domain, '*')) {
                return $domain;
            }
        }
        return null;
    }

    /**
     * Parses the personal data hosts.
     *
     * @param string $raw
     * @return string[]
     */
    private static function parse(string $raw): array {
        $domains = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = self::normalise($line);
            if ($line !== '') {
                $domains[] = $line;
            }
        }
        return $domains;
    }

    /**
     * Normalises the personal data hosts.
     *
     * @param string $value
     * @return string
     */
    private static function normalise(string $value): string {
        return strtolower(trim($value));
    }
}
