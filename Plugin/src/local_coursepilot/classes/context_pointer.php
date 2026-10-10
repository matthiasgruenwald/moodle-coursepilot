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
 * Interpret the context pointer decoded from JSON (Issue #490, Spec #486 §2)
 * using pure value transformations without file or network access.
 *
 * - **First format** (Issue #445): two flat paths under legacy keys
 *   "kontextbereich"/"materialordner", both interpreted as Moodle locations.
 * - **Second format** (Issue #490): one object per target, with `location`
 *   "moodle" (`path`) or "external" (`instanceid`, `path`, `fingerprint`).
 *   Target keys are "context_area" and "material_store" ({@see TARGETS}).
 *
 * Keys and values are English since #602 (ADR 0024); {@see normalise()}
 * translates legacy German keys when reading.
 *
 * Location history is appended and read raw by location_selection (Spec §2),
 * without resolution here. `previous_location` is resolved by
 * {@see resolve_previous()} (Issue #498, Spec #486 §9): the read-only
 * previous_location branch needs the same structure and IServ checks as
 * the two current targets.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_pointer {
    /** @var string[] The two targets, required fields in both formats. */
    public const TARGETS = ['context_area', 'material_store'];

    /**
     * @var array<string, string> Legacy German pointer keys to English keys (#602, ADR 0024).
     *      "materialordner" is the material key from the first format (Issue #445).
     */
    private const LEGACY_KEY_MAP = [
        'kontextbereich' => 'context_area',
        'materialbestand' => 'material_store',
        'materialordner' => 'material_store',
        'ort' => 'location',
        'pfad' => 'path',
        'instanzid' => 'instanceid',
        'pruefmerkmal' => 'fingerprint',
        'basispfad' => 'basepath',
        'konto' => 'account',
        'ortsverlauf' => 'location_history',
        'vorheriger_ort' => 'previous_location',
        'datum' => 'date',
        'ziel' => 'target',
        'von' => 'from_text',
        'nach' => 'to_text',
    ];

    /** @var array<string, string> Legacy German pointer values to English values (#602). */
    private const LEGACY_VALUE_MAP = [
        'extern' => pointer_location::EXTERNAL,
        'kontextbereich' => 'context_area',
        'materialbestand' => 'material_store',
    ];

    /**
     * Translate legacy German keys and values (before #602) to English:
     * recursive, idempotent, without validation. Translate values only under
     * "location" and "target"; preserve paths.
     *
     * @param mixed[] $decoded
     * @return mixed[]
     */
    public static function normalise(array $decoded): array {
        $result = [];
        foreach ($decoded as $key => $value) {
            $key = is_string($key) ? (self::LEGACY_KEY_MAP[$key] ?? $key) : $key;
            if (is_array($value)) {
                $value = self::normalise($value);
            } else if (is_string($value) && in_array($key, ['location', 'target'], true)) {
                $value = self::LEGACY_VALUE_MAP[$value] ?? $value;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    /**
     * Resolves target.
     *
     * @param mixed[] $decoded Pointer content already decoded from JSON.
     * @param string $pointerkey The resolving area's {@see storage_area::$pointerkey}.
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    public static function resolve_target(array $decoded, string $pointerkey): pointer_location {
        $decoded = self::normalise($decoded);
        $pair = self::is_legacy($decoded) ? self::resolve_pair_legacy($decoded) : self::resolve_pair_v2($decoded);

        // Resolution check 7 (Issue #495, Spec #486 §2): the material store must not
        // equal or be nested within the context area. Both targets are resolved
        // together (check 1). Location-neutral server/account/path comparison
        // keys prevent material reads from entering the context area.
        if (str_starts_with($pair['material_store']->comparison_key(), $pair['context_area']->comparison_key())) {
            throw new \moodle_exception('materialstoreincontext', 'local_coursepilot');
        }

        // Resolution check 8 (Issue #497, Spec #486 §2/§5): instances identified as
        // IServ by their fingerprint are reachable only under "Files/".
        // Check both targets, as for check 7, without network access.
        foreach ($pair as $location) {
            if ($location->kind === pointer_location::EXTERNAL && ($location->fingerprint['iserv'] ?? false) === true) {
                $first = strtok((string) $location->relativepath, '/');
                if ($first !== \local_coursepilot\webdav\webdav_instance::ISERV_FILES_AREA) {
                    throw self::iserv_files_only_exception($location);
                }
            }
        }

        return $pair[$pointerkey];
    }

    /**
     * Resolves pair legacy.
     *
     * @param mixed[] $decoded
     * @return array{context_area: pointer_location, material_store: pointer_location}
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_pair_legacy(array $decoded): array {
        foreach (self::TARGETS as $key) {
            if (!is_string($decoded[$key] ?? null)) {
                self::incomplete();
            }
        }
        return [
            'context_area' => pointer_location::moodle('/' . self::validate_path($decoded['context_area']) . '/'),
            'material_store' => pointer_location::moodle('/' . self::validate_path($decoded['material_store']) . '/'),
        ];
    }

    /**
     * Resolves pair v2.
     *
     * @param mixed[] $decoded
     * @return array{context_area: pointer_location, material_store: pointer_location}
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_pair_v2(array $decoded): array {
        // Completeness (check 1, Spec §2): both targets must have valid structures,
        // regardless of which is requested; the same rule as the first format.
        foreach (self::TARGETS as $targetfield) {
            if (!is_array($decoded[$targetfield] ?? null)) {
                self::incomplete();
            }
        }

        $pair = [];
        foreach (self::TARGETS as $targetfield) {
            $pair[$targetfield] = self::resolve_single_v2($decoded[$targetfield]);
        }
        return $pair;
    }

    /**
     * Resolves single v2.
     *
     * @param mixed[] $target
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_single_v2(array $target): pointer_location {
        $location = $target['location'] ?? null;

        if ($location === pointer_location::MOODLE) {
            if (!is_string($target['path'] ?? null)) {
                self::incomplete();
            }
            return pointer_location::moodle('/' . self::validate_path($target['path']) . '/');
        }

        if ($location === pointer_location::EXTERNAL) {
            return self::resolve_external($target);
        }

        self::incomplete();
    }

    /**
     * Resolve the previous context location (Issue #498, Spec #486 §9), using
     * the same second-format structure through {@see resolve_single_v2()}.
     * Apply the IServ check (8), but not the nesting check (7): the previous
     * location is never compared with the current material store.
     *
     * @param mixed[] $value Value of "previous_location" in the pointer document.
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable/webdaviservfilesonly
     */
    public static function resolve_previous(array $value): pointer_location {
        $location = self::resolve_single_v2(self::normalise($value));
        if ($location->kind === pointer_location::EXTERNAL && ($location->fingerprint['iserv'] ?? false) === true) {
            $first = strtok((string) $location->relativepath, '/');
            if ($first !== \local_coursepilot\webdav\webdav_instance::ISERV_FILES_AREA) {
                throw self::iserv_files_only_exception($location);
            }
        }
        return $location;
    }

    /**
     * Build `webdaviservfilesonly` (check 8) with host and instance ID in $a
     * (Issue #516, Spec #486 §8). This lets pointer_writer name the instance
     * and host in failure-response part 5 even when resolution failed before
     * producing a {@see pointer_location}.
     *
     * @param pointer_location $location Already identified as EXTERNAL/iserv.
     * @return \moodle_exception
     */
    private static function iserv_files_only_exception(pointer_location $location): \moodle_exception {
        return new \moodle_exception('webdaviservfilesonly', 'local_coursepilot', '', (object) [
            'page' => \local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE,
            'server' => $location->fingerprint['server'] ?? '',
            'instanceid' => $location->instanceid,
        ]);
    }

    /**
     * Recognize the first format: "context_area" is a flat string rather than
     * an object. Both paths represent Moodle locations (Spec §2).
     *
     * @param mixed[] $decoded
     * @return bool
     */
    private static function is_legacy(array $decoded): bool {
        return is_string($decoded['context_area'] ?? null);
    }

    /**
     * Resolves external.
     *
     * @param mixed[] $target
     * @return pointer_location
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function resolve_external(array $target): pointer_location {
        $instanceid = $target['instanceid'] ?? null;
        $relativepath = $target['path'] ?? null;
        $fingerprint = $target['fingerprint'] ?? null;

        if (
            !is_numeric($instanceid) || (int) $instanceid <= 0
            || !is_string($relativepath)
            || !is_array($fingerprint)
            || !is_string($fingerprint['server'] ?? null)
            || !is_string($fingerprint['basepath'] ?? null)
            || !is_string($fingerprint['account'] ?? null)
        ) {
            self::incomplete();
        }

        return pointer_location::external((int) $instanceid, self::validate_path($relativepath), [
            'server' => $fingerprint['server'],
            'basepath' => $fingerprint['basepath'],
            'account' => $fingerprint['account'],
            // Optional IServ detection (Issue #497, Spec #486 §2 check 8).
            // Pointers predating #497 omit this field and default to false.
            'iserv' => (bool) ($fingerprint['iserv'] ?? false),
        ]);
    }

    /**
     * Same segment validation as the first format (Issue #445): nonempty,
     * no `.`/`..` segments; backslashes count as path separators.
     *
     * @param string $value
     * @return string Trimmed path without leading or trailing slashes.
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    public static function validate_path(string $value): string {
        $normalised = str_replace('\\', '/', $value);
        if (trim($normalised, '/') === '') {
            self::incomplete();
        }
        $trimmed = trim($normalised, '/');
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \moodle_exception('pointerunreachable', 'local_coursepilot', '', storage_anchor::POINTER_FILENAME);
            }
        }
        return $trimmed;
    }

    /**
     * Provides incomplete.
     *
     * @throws \moodle_exception pointerincomplete
     */
    private static function incomplete(): never {
        throw new \moodle_exception('pointerincomplete', 'local_coursepilot', '', storage_anchor::POINTER_FILENAME);
    }
}
