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
 * An area is a value set, not a type (issue #444): only the differences
 * between {@see context_files} and {@see material_files}. Both share
 * component/filearea/item/user context in {@see storage_anchor}.
 *
 * The write-name policy ({@see $checkwritablename}) is the one area-specific
 * method: the .md allowlist for context_files, the extension allowlist for
 * material_files. A Closure throws the appropriate moodle_exception and
 * area error key on violation.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_area {
    /**
     * Creates the storage area.
     *
     * @param string $rootsetting Plugin setting naming the root folder.
     * @param string $defaultroot Default root if the setting is empty.
     * @param string $invalidpathkey Language key for rejected paths
     *        (`.`/`..` segments or invalid folder segments).
     * @param string $quotaerrorkey Language key for writes exceeding the user quota.
     * @param \Closure $checkwritablename Throws an area-specific Type: \Closure(string):void.
     *        moodle_exception for an invalid filename; otherwise returns.
     * @param string|null $pointerkey Area field in the context pointer (issue #445),
     *        e.g. context_area/material_store. Null for areas without pointers
     *        (e.g. pure test areas), which always use the configured default root.
     * @param bool $externalfallback For external pointers, fall back to the
     *        configured default root instead of throwing (issue #520, Spec #486 §1):
     *        workbench stays at the anchor while material store failures remain
     *        explicit; see {@see storage_anchor::root()}.
     */
    public function __construct(
        /** @var string Plugin setting naming the root folder. */
        public readonly string $rootsetting,
        /** @var string Default root if the setting is empty. */
        public readonly string $defaultroot,
        /** @var string Language key for rejected paths */
        public readonly string $invalidpathkey,
        /** @var string Language key for writes exceeding the user quota. */
        public readonly string $quotaerrorkey,
        /** @var \Closure The checkwritablename. */
        public readonly \Closure $checkwritablename,
        /** @var ?string Area field in the context pointer (issue #445), */
        public readonly ?string $pointerkey = null,
        /** @var bool For external pointers, fall back to the */
        public readonly bool $externalfallback = false,
    ) {
    }
}
