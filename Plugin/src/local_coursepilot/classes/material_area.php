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
 * Location-neutral material operations (list, upload, preview, delete;
 * issue #539, Spec 0021). {@see \local_coursepilot\external\list_material_files}
 * and sibling tools no longer strip internal etag fields or reproduce write/delete
 * sequencing. This facade owns those decisions, as {@see context_area} does
 * for context (issue #538).
 *
 * Material write tools target the workbench exclusively
 * ({@see material_files::workbench_area()}), always Moodle Private Files.
 * Writes/deletes use {@see storage_port} through {@see private_files_storage_port},
 * the same adapter used by context_area for Private Files.
 *
 * {@see \local_coursepilot\external\crop_material_file} deliberately writes
 * outside this facade: crop needs Moodle's source metadata for provenance,
 * which has no WebDAV equivalent in the neutral contract. It therefore keeps
 * {@see material_files::write()} (unchanged since before #539) without location
 * branching; the workbench is always Moodle.
 *
 * List/read for material store and workbench use
 * {@see material_files::list_entries_for_location()}/
 * {@see material_files::read_content_for_location()} through
 * {@see storage_anchor::port()} (issue #645), selecting the same per-location
 * adapter as context. {@see list()}/{@see read_for_location()} are thin facades.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class material_area {
    /**
     * Lists one level of the requested material location with identical fields for both locations (issues #539/#645).
     *
     * @param string $locationkey {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH}.
     * @param string $path
     * @return array{directory: string, entries: array}
     * @throws \moodle_exception As in {@see material_files::list_entries_for_location()}.
     */
    public static function list(string $locationkey, string $path): array {
        return material_files::list_entries_for_location($locationkey, $path);
    }

    /**
     * Reads a file for {@see \local_coursepilot\external\preview_material_file}
     * and the source path of {@see \local_coursepilot\external\crop_material_file}
     * (issue #539). Thin facade over {@see material_files::read_content_for_location()}:
     * store/workbench and Moodle/external selection remain there through
     * {@see storage_anchor::port()}.
     *
     * @param string $locationkey {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH}.
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     * @throws \moodle_exception As in {@see material_files::read_content_for_location()}.
     */
    public static function read_for_location(string $locationkey, string $path): ?array {
        return material_files::read_content_for_location($locationkey, $path);
    }

    /**
     * Reads an existing workbench file through the anchor, or null if missing.
     * Used for pre-write/delete existence and size checks ({@see write()},
     * {@see delete()}, {@see \local_coursepilot\external\delete_material_files}).
     *
     * @param string $path
     * @return array{content: string, checksum: string, size: int, mimetype: string,
     *         timemodified: int}|null
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function read(string $path): ?array {
        return self::port()->read(material_files::workbench_area(), $path);
    }

    /**
     * Writes a workbench file through the anchor (issue #539), used by
     * {@see \local_coursepilot\external\upload_material_file}. Path, extension,
     * quota and temporary-file sequencing belong to the
     * {@see private_files_storage_port} adapter. Crop targets still use
     * {@see material_files::write()} because
     * {@see \local_coursepilot\external\crop_material_file} needs Moodle's source
     * metadata; see class documentation.
     *
     * @param string $path
     * @param string $content Complete new content.
     * @param string $expectedcontenthash '' means unconditional overwrite/create.
     * @return array{path: string, created: bool, size: int, oldsize: int, warning: ?string}
     * @throws \moodle_exception materialfilechanged (checksum conflict),
     *         materialfiledisallowedtype, invalidmaterialpath, materialquotaexceeded
     */
    public static function write(string $path, string $content, string $expectedcontenthash = ''): array {
        $area = material_files::workbench_area();
        $port = self::port();

        $existing = $port->read($area, $path);
        $oldsize = $existing['size'] ?? 0;

        try {
            $written = $port->write($area, $path, $content, $expectedcontenthash !== '' ? $expectedcontenthash : null);
        } catch (storage_conflict_exception $e) {
            throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $path);
        }

        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $oldsize,
            'warning' => material_files::quota_warning($written['size'] - $oldsize),
        ];
    }

    /**
     * Deletes a workbench file through the anchor if present
     * (issue #539, {@see \local_coursepilot\external\delete_material_files}).
     *
     * @param string $path
     * @return bool true if a file was deleted; false if none existed.
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function delete(string $path): bool {
        return self::port()->delete(material_files::workbench_area(), $path);
    }

    /**
     * Workbench storage adapter through the anchor: always Private Files
     * (issues #539/#645). The workbench has no external location
     * ({@see storage_area::$externalfallback}); see class documentation.
     *
     * @return storage_port
     */
    private static function port(): storage_port {
        return storage_anchor::port(material_files::workbench_area());
    }
}
