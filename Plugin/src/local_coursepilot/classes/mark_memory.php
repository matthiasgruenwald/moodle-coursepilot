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
 * The mark memory (issue #493, spec #486 §6 "mark memory
 * (latency)"): without this memory a listing would need 1 + N accesses
 * to know the personal-data mark of every `.md` file - externally
 * additionally throttled. Therefore only the one bit "marked
 * yes/no" per file is remembered, in Moodle, without network.
 *
 * The key is path, size, modification time and the check value of the
 * storage adapter (column `etag`, since issue #645 the {@see storage_port} check value) -
 * all already known from the single PROPFIND/directory entry of the listing,
 * no additional access needed to form the key.
 * If the stored key no longer matches the current entry, the
 * memory counts as empty for this file - {@see lookup()} then returns
 * `null`, the caller reads the file anew and records the result via
 * {@see remember()}. Content is **never** stored, only the bit -
 * a lost memory therefore costs only time, never correctness
 * ({@see \local_coursepilot\external\read_context_file} checks the content
 * itself again on every read anyway).
 *
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class mark_memory {

    /** @var string Table name, see db/install.xml. */
    private const TABLE = 'local_coursepilot_context_mark';

    /**
     * The remembered bit for a file, if the key still matches.
     *
     * @param string $path Client path of the file, relative to the context area.
     * @param int $size
     * @param int $timemodified
     * @param string|null $etag
     * @return bool|null null if nothing is remembered or the key
     *         no longer matches (file has changed) - the caller
     *         then reads it again itself.
     */
    public static function lookup(string $path, int $size, int $timemodified, ?string $etag): ?bool {
        global $DB, $USER;

        if (empty($USER->id)) {
            return null;
        }

        $record = $DB->get_record(self::TABLE, ['userid' => (int) $USER->id, 'pathhash' => sha1($path)]);
        if (!$record) {
            return null;
        }
        if ((int) $record->filesize !== $size
                || (int) $record->timemodified !== $timemodified
                || (string) $record->etag !== (string) ($etag ?? '')) {
            return null;
        }
        return (bool) $record->ismarked;
    }

    /**
     * Remembers the bit for a file - creates the entry or replaces
     * it, depending on whether one already exists.
     * @param string|null $etag
     * @param bool $marked
     */
    public static function remember(string $path, int $size, int $timemodified, ?string $etag, bool $marked): void {
        global $DB, $USER;

        if (empty($USER->id)) {
            return;
        }

        $pathhash = sha1($path);
        $data = (object) [
            'userid' => (int) $USER->id,
            'path' => $path,
            'pathhash' => $pathhash,
            'filesize' => $size,
            'timemodified' => $timemodified,
            'etag' => $etag,
            'ismarked' => $marked ? 1 : 0,
        ];

        $existingid = $DB->get_field(self::TABLE, 'id', ['userid' => (int) $USER->id, 'pathhash' => $pathhash]);
        if ($existingid) {
            $data->id = $existingid;
            $DB->update_record(self::TABLE, $data);
        } else {
            $DB->insert_record(self::TABLE, $data);
        }
    }
}
