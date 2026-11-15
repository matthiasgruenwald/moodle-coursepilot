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

namespace local_coursepilot\tests;

use local_coursepilot\storage_area;
use local_coursepilot\storage_port;

/**
 * Injects an intervening storage action before delegating a conditional write.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class interleaved_storage_port implements storage_port {
    /**
     * Selects the real adapter and the intervening storage action.
     *
     * @param storage_port $inner Real storage adapter.
     * @param \Closure $beforewrite Intervening storage action.
     */
    public function __construct(
        /** @var storage_port Real adapter. */
        private readonly storage_port $inner,
        /** @var \Closure(): void Intervening storage action. */
        private readonly \Closure $beforewrite,
    ) {
    }

    /**
     * Delegates the read operation at the storage boundary.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative target path.
     * @return array{content: string, checksum: string, size: int, mimetype: string, timemodified: int}|null
     */
    public function read(storage_area $area, string $path): ?array {
        return $this->inner->read($area, $path);
    }

    /**
     * Delegates the list operation at the storage boundary.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative target path.
     * @return array<int, array{name: string, type: string, size: int, mimetype: string, checksum: string, timemodified: int}>
     */
    public function list(storage_area $area, string $path): array {
        return $this->inner->list($area, $path);
    }

    /**
     * Delegates the write operation at the storage boundary.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative target path.
     * @param string $content Content to persist.
     * @param string|null $expectedchecksum Authorised preflight state.
     * @param string $source Serialised provenance.
     * @return array{path: string, created: bool, size: int, checksum: string}
     */
    public function write(
        storage_area $area,
        string $path,
        string $content,
        ?string $expectedchecksum = null,
        string $source = ''
    ): array {
        ($this->beforewrite)();
        return $this->inner->write($area, $path, $content, $expectedchecksum, $source);
    }

    /**
     * Delegates the append operation at the storage boundary.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative target path.
     * @param string $content Content to persist.
     * @param string|null $expectedchecksum Authorised preflight state.
     * @return array{path: string, created: bool, size: int, checksum: string}
     */
    public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        ($this->beforewrite)();
        return $this->inner->append($area, $path, $content, $expectedchecksum);
    }

    /**
     * Delegates the delete operation at the storage boundary.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative target path.
     * @return bool
     */
    public function delete(storage_area $area, string $path): bool {
        return $this->inner->delete($area, $path);
    }
}
