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
 * Read-only fake port over a folder tree: folder path => entry names ("name/" is a folder) or a
 * Throwable that listing the folder throws. Optional callback on every list.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class tree_storage_port implements storage_port {
    /**
     * Selects the tree and the optional list callback.
     *
     * @param mixed[] $tree Folder path => entry names, or a Throwable.
     * @param \Closure|null $onlist Called with the folder path on every list.
     */
    public function __construct(
        /** @var mixed[] Folder path => entry names, or a Throwable. */
        private readonly array $tree,
        /** @var \Closure|null Called with the folder path on every list. */
        private readonly ?\Closure $onlist = null,
    ) {
    }

    /**
     * Lists one folder of the tree.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative folder path.
     * @return mixed[]
     */
    public function list(storage_area $area, string $path): array {
        if ($this->onlist) {
            ($this->onlist)($path);
        }
        $names = $this->tree[$path] ?? [];
        if ($names instanceof \Throwable) {
            throw $names;
        }
        return array_map(static fn (string $n): array => [
            'name' => rtrim($n, '/'), 'type' => str_ends_with($n, '/') ? 'folder' : 'file',
            'size' => 0, 'mimetype' => '', 'checksum' => '', 'timemodified' => 0,
        ], $names);
    }

    /**
     * Not used.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative path.
     * @return mixed[]|null
     */
    public function read(storage_area $area, string $path): ?array {
        throw new \coding_exception('not used');
    }

    /**
     * Not used.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative path.
     * @param string $content Content.
     * @param string|null $expectedchecksum Expected state.
     * @param string $source Provenance.
     * @return mixed[]
     */
    public function write(
        storage_area $area,
        string $path,
        string $content,
        ?string $expectedchecksum = null,
        string $source = ''
    ): array {
        throw new \coding_exception('not used');
    }

    /**
     * Not used.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative path.
     * @param string $content Content.
     * @param string|null $expectedchecksum Expected state.
     * @return mixed[]
     */
    public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        throw new \coding_exception('not used');
    }

    /**
     * Not used.
     *
     * @param storage_area $area Target area.
     * @param string $path Relative path.
     * @return bool
     */
    public function delete(storage_area $area, string $path): bool {
        throw new \coding_exception('not used');
    }
}
