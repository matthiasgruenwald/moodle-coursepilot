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
 * Resolved context or material pointer target in the second format
 * (Issue #490, Spec #486 §2). Either Moodle with a resolved Private Files
 * path, or external with instance ID, relative path and selection-time
 * fingerprint (server, base path, account). Value object built by
 * context_pointer and read by storage_anchor and webdav_instance.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_location {
    /** @var string Target is in Moodle Private Files. */
    public const MOODLE = 'moodle';

    /** @var string Target is in a WebDAV user instance. */
    public const EXTERNAL = 'external';

    /**
     * Creates the pointer location.
     *
     * @param string $kind The kind.
     * @param ?string $path The path.
     * @param ?int $instanceid The instanceid.
     * @param ?string $relativepath The relativepath.
     * @param ?array $fingerprint The fingerprint.
     */
    private function __construct(
        /** @var string The kind. */
        public readonly string $kind,
        /** @var ?string The path. */
        public readonly ?string $path = null,
        /** @var ?int The instanceid. */
        public readonly ?int $instanceid = null,
        /** @var ?string The relativepath. */
        public readonly ?string $relativepath = null,
        /** @var ?array The fingerprint. */
        public readonly ?array $fingerprint = null,
    ) {
    }

    /**
     * Creates the moodle pointer location.
     *
     * @param string $path Always with leading and trailing slashes.
     * @return self
     */
    public static function moodle(string $path): self {
        return new self(self::MOODLE, path: $path);
    }

    /**
     * Creates the external pointer location.
     *
     * @param int $instanceid WebDAV user instance repository_instances.id.
     * @param string $relativepath Selected folder relative to the instance base path.
     * @param mixed[] $fingerprint Type: array{server:string,basepath:string,account:string}.
     *        Server/base path/account at selection time (Spec §2).
     * @return self
     */
    public static function external(int $instanceid, string $relativepath, array $fingerprint): self {
        return new self(self::EXTERNAL, instanceid: $instanceid, relativepath: $relativepath, fingerprint: $fingerprint);
    }

    /**
     * Comparison key for context/material overlap checks (Issue #495,
     * Spec #486 §2 check 7): server, account and effective path, normalized
     * with a trailing slash. Moodle and external prefixes cannot overlap.
     * Both Moodle targets share the teacher's Private Files by definition.
     *
     * External effective paths include the instance base path and relative
     * selected path (Issue #518). Omitting the base path could falsely equate
     * different locations or miss actual nesting when relative paths coincide.
     *
     * @param string $subpath Additional subpath from this location, already segment-validated
     *        (e.g. through storage_anchor::normalise_client_path()).
     * @return string
     */
    public function comparison_key(string $subpath = ''): string {
        if ($this->kind === self::MOODLE) {
            return 'moodle|' . self::normalised_path((string) $this->path, $subpath);
        }
        $server = strtolower((string) ($this->fingerprint['server'] ?? ''));
        $account = (string) ($this->fingerprint['account'] ?? '');
        $basepath = (string) ($this->fingerprint['basepath'] ?? '');
        $effectivepath = trim($basepath, '/') . '/' . trim((string) $this->relativepath, '/');
        return 'external|' . $server . '|' . $account . '|' . self::normalised_path($effectivepath, $subpath);
    }

    /**
     * Provides normalised path.
     *
     * @param string $base
     * @param string $subpath
     * @return string Always with leading and trailing slashes; root is "/".
     */
    private static function normalised_path(string $base, string $subpath): string {
        $combined = trim($base, '/') . ($subpath !== '' ? '/' . trim($subpath, '/') : '');
        $trimmed = trim($combined, '/');
        return $trimmed === '' ? '/' : '/' . $trimmed . '/';
    }
}
