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
 * First storage contract adapter (issue #536, Spec 0021): Moodle Private
 * Files. Implements {@see storage_port} through {@see storage_anchor} for
 * path resolution, segment validation, quota and temporary-file writes.
 * Reuses the building blocks of {@see context_files} and {@see material_files}
 * behind the location-neutral interface.
 *
 * Does not resolve context pointers: supplied areas have no
 * {@see storage_area::$pointerkey}, so {@see storage_anchor::root()} never
 * branches into the previous pointer resolution path. Adapter selection is
 * handled elsewhere, not by this adapter. Its checksum is Moodle contenthash.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class private_files_storage_port implements storage_port {
    /**
     * Creates the private files storage port.
     *
     * @param pointer_location|null $location Already resolved Moodle location.
     *        Null is allowed for contract tests without a pointer.
     */
    public function __construct(
        /** @var ?pointer_location Already resolved Moodle location. */
        private readonly ?pointer_location $location = null,
    ) {
    }

    /**
     * Reads the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function read(storage_area $area, string $path): ?array {
        [$directory, $filename] = $this->resolve_file($area, $path);
        $found = storage_anchor::read_content($directory, $filename);
        if ($found === null) {
            return null;
        }
        return [
            'content' => $found['content'],
            'checksum' => $found['contenthash'],
            'size' => $found['size'],
            'mimetype' => $found['mimetype'],
            'timemodified' => $found['timemodified'],
        ];
    }

    /**
     * Lists the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function list(storage_area $area, string $path): array {
        $directory = $this->resolve_directory($area, $path);
        $entries = [];
        foreach (storage_anchor::list_entries($directory) as $entry) {
            $entries[] = [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'size' => $entry['size'],
                'mimetype' => $entry['mimetype'],
                'checksum' => $entry['contenthash'],
                'timemodified' => $entry['timemodified'],
            ];
        }
        return $entries;
    }

    /**
     * Writes the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @param string $content The content.
     * @param ?string $expectedchecksum The expectedchecksum.
     */
    public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$directory, $filename] = $this->resolve_writable_file($area, $path);
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        $existing = storage_anchor::read_content($directory, $filename);
        $this->require_checksum_match($existing, $expectedchecksum, $clientpath);

        $oldsize = $existing['size'] ?? 0;
        storage_anchor::require_quota($area, strlen($content) - $oldsize);
        if ($expectedchecksum === storage_port::MISSING_CHECKSUM) {
            // A missing preflight must stay create-only through persistence.
            // storage_anchor::write() rereads and may replace a concurrent file.
            $record = storage_anchor::filerecord(storage_anchor::own_context()->id, $directory, $filename);
            try {
                get_file_storage()->create_file_from_string($record, $content);
            } catch (\stored_file_creation_exception $e) {
                if (storage_anchor::read_content($directory, $filename) !== null) {
                    throw new storage_conflict_exception($clientpath);
                }
                throw $e;
            }
        } else {
            storage_anchor::write($directory, $filename, $content);
        }

        $written = storage_anchor::read_content($directory, $filename);
        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $written['size'],
            'checksum' => $written['contenthash'],
        ];
    }

    /**
     * Appends the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @param string $content The content.
     * @param ?string $expectedchecksum The expectedchecksum.
     */
    public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$directory, $filename] = $this->resolve_writable_file($area, $path);
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        $existing = storage_anchor::read_content($directory, $filename);

        $this->require_checksum_match($existing, $expectedchecksum, $clientpath);
        storage_anchor::require_quota($area, strlen($content));
        $size = storage_anchor::append($directory, $filename, $content);

        $written = storage_anchor::read_content($directory, $filename);
        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $size,
            'checksum' => $written['contenthash'],
        ];
    }

    /**
     * Deletes the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function delete(storage_area $area, string $path): bool {
        [$directory, $filename] = $this->resolve_file($area, $path);
        return storage_anchor::delete($directory, $filename);
    }

    /**
     * Resolves file.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @return array{0: string, 1: string}
     */
    private function resolve_file(storage_area $area, string $path): array {
        $normalised = storage_anchor::normalise_client_path($area, $path);
        if ($normalised === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $segments = explode('/', $normalised);
        $filename = array_pop($segments);
        return [$this->resolve_directory($area, implode('/', $segments)), $filename];
    }

    /**
     * Resolves writable file.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @return array{0: string, 1: string}
     */
    private function resolve_writable_file(storage_area $area, string $path): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        return [$this->resolve_directory($area, implode('/', $folders)), $filename];
    }

    /**
     * Resolves directory.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @return string
     */
    private function resolve_directory(storage_area $area, string $path): string {
        $relative = storage_anchor::normalise_client_path($area, $path);
        $root = $this->location === null
            ? storage_anchor::resolve_directory($area, '')
            : rtrim((string) $this->location->path, '/') . '/';
        return $relative === '' ? $root : $root . $relative . '/';
    }

    /**
     * Rejects a conditional write whose checksum no longer matches current
     * content, including a now missing file. A null checksum skips comparison
     * and keeps unconditional overwrite/create behaviour.
     *
     * @param ?array $existing The existing.
     * @param string|null $expectedchecksum
     * @param string $clientpath For the error message.
     * @param array{content: string, mimetype: string, size: int, contenthash: string,
     *        timemodified: int}|null $existing
     * @throws storage_conflict_exception
     */
    private function require_checksum_match(?array $existing, ?string $expectedchecksum, string $clientpath): void {
        if ($expectedchecksum === null) {
            return;
        }
        if ($expectedchecksum === storage_port::MISSING_CHECKSUM) {
            if ($existing !== null) {
                throw new storage_conflict_exception($clientpath);
            }
            return;
        }
        $currentchecksum = $existing['contenthash'] ?? null;
        if ($currentchecksum !== $expectedchecksum) {
            throw new storage_conflict_exception($clientpath);
        }
    }
}
