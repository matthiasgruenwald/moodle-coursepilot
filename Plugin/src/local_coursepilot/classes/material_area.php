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
 * Location-neutral material operations for the store and workbench.
 * Reads follow the selected store; writes target the Moodle workbench.
 *
 * All writes use storage_write to retain the authorised preflight state and
 * record storage outages. Image provenance travels through the port's typed
 * source argument. Generated exports retain their permissive name policy.
 *
 * List/read use the material domain's context-area isolation and the anchor's
 * selected adapter; tools never reproduce those storage decisions.
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
     * @return array{directory: string, entries: mixed[]}
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
     * Writes a workbench file using the common conditional persistence operation.
     * Path, extension, quota and temporary-file sequencing belong to the adapter.
     *
     * @param string $path
     * @param string $content Complete new content.
     * @param string $expectedcontenthash Explicit checksum; otherwise uses the preflight state.
     * @param string $source Optional serialised provenance for image tools.
     * @return array{path: string, created: bool, size: int, oldsize: int, warning: ?string}
     * @throws \moodle_exception materialfilechanged (checksum conflict),
     *         materialfiledisallowedtype, invalidmaterialpath, materialquotaexceeded
     */
    public static function write(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $source = ''
    ): array {
        $area = material_files::workbench_area();
        $location = storage_anchor::effective_location($area);
        return self::write_to(storage_anchor::port_at($location), $location, $path, $content, $expectedcontenthash, $source);
    }

    /**
     * Writes generated exports through the same condition without the upload name policy.
     *
     * @param string $path Relative workbench export path.
     * @param string $content Complete generated content.
     * @return array{path: string, created: bool, size: int, oldsize: int, warning: ?string}
     */
    public static function write_export(string $path, string $content): array {
        $area = material_files::export_area();
        $location = storage_anchor::effective_location($area);
        return self::write_to(storage_anchor::port_at($location), $location, $path, $content, area: $area);
    }

    /**
     * Writes through a selected workbench port with the shared preflight condition.
     *
     * @param storage_port $port Selected adapter.
     * @param pointer_location $location Workbench location.
     * @param string $path Relative material path.
     * @param string $content Complete replacement content.
     * @param string $expectedcontenthash Explicit client checksum if supplied.
     * @param string $source Serialised provenance for image tools.
     * @param storage_area|null $area Workbench upload or generated-export policy.
     * @return array{path: string, created: bool, size: int, oldsize: int, warning: ?string}
     */
    public static function write_to(
        storage_port $port,
        pointer_location $location,
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $source = '',
        ?storage_area $area = null
    ): array {
        $area = $area ?? material_files::workbench_area();
        storage_anchor::writable_segments($area, $path);
        private_files_storage_port::require_manage_own_files();
        $writer = new storage_write($port, $area, $location, $path);
        $existing = $writer->existing();
        $oldsize = $existing['size'] ?? 0;
        $warning = material_files::quota_warning(strlen($content) - $oldsize);
        try {
            $written = $writer->write($content, $expectedcontenthash, source: $source);
        } catch (storage_conflict_exception $e) {
            throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $path);
        }
        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $oldsize,
            'warning' => $warning,
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
