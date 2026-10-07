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
 * The pending-write note (Issue #492, ADR 0023, Spec #486 §8/§10): the
 * record of failed write operations at the fixed anchor, next to the
 * context pointer ({@see storage_anchor::PENDING_FILENAME}) - where
 * Coursepilot can write even when the external storage is silent
 * (CONTEXT.md "Ausstandsnotiz").
 *
 * One entry per failed operation, never the content: identifier, course ID,
 * timestamp, relative path, operation (create/overwrite/append/unknown) and
 * error class (Issue #516, Spec #486 §8). Disappears only explicitly - by making it up
 * ({@see pointer_writer}, via `pending_entry=<identifier>`, #571: declared in
 * English since then) or by explicit dismissal
 * ({@see \local_coursepilot\external\dismiss_pending_entry}) - never by the passage of time.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pending_write_notice {

    /**
     * Records a failed write operation and returns the newly
     * assigned identifier.
     *
     * @param string $path Relative client path of the target file, never the content.
     * @param string $operation One of the {@see pending_write_translation}::OP_* constants.
     * @param string $errorclass Error class (e.g. a {@see \local_coursepilot\webdav\webdav_error} constant
     *        or a webdavinstance* error key), never free text.
     * @param int $courseid Course ID (Issue #516, Spec #486 §8) - 0 if the
     *        failed call was not assigned to a course.
     * @return string Newly assigned identifier.
     * @throws \moodle_exception pendingnotequotaexceeded if the note itself
     *         can no longer be written (Private Files quota full).
     */
    public static function record(string $path, string $operation, string $errorclass, int $courseid): string {
        $entries = self::all();
        $identifier = self::generate_identifier($entries);
        $entries[$identifier] = [
            'timestamp' => time(),
            'path' => $path,
            'operation' => $operation,
            'error_class' => $errorclass,
            'course_id' => $courseid,
        ];
        self::save($entries);
        return $identifier;
    }

    /**
     * Dismisses an entry - used both when making it up (successful
     * write with `pending_entry=<identifier>`, #571: declared in English
     * since then) and when the teacher explicitly dismisses it
     * ({@see \local_coursepilot\external\dismiss_pending_entry}): the same
     * operation, two occasions (ADR 0023 point 3).
     *
     * An empty or unknown identifier is a harmless no-op (Issue
     * #506) - `write_context_file`/`append_context_file` therefore call
     * straight through, without first checking the optional "pending_entry" parameter
     * for "" themselves.
     *
     * @param string $identifier
     * @return bool true if an entry with this identifier existed and was removed.
     */
    public static function dismiss(string $identifier): bool {
        $entries = self::all();
        if (!isset($entries[$identifier])) {
            return false;
        }
        unset($entries[$identifier]);
        self::save($entries);
        return true;
    }

    /**
     * All open entries, bundled per target file, oldest first -
     * for the handshake ({@see \local_coursepilot\external\list_skills}). Purely
     * local, without network access: reads only the note file itself.
     *
     * @return array<int, array{path: string, entries: array<int, array{
     *         identifier: string, timestamp: int, operation: string, error_class: string, course_id: int}>}>
     */
    public static function list_grouped(): array {
        $bypath = [];
        foreach (self::all() as $identifier => $entry) {
            $bypath[$entry['path']][] = [
                'identifier' => $identifier,
                'timestamp' => $entry['timestamp'],
                'operation' => $entry['operation'],
                'error_class' => $entry['error_class'],
                // Backward compatible (Issue #516): an entry written before this
                // issue does not know the field yet.
                'course_id' => $entry['course_id'] ?? 0,
            ];
        }

        $groups = [];
        foreach ($bypath as $path => $entriesforpath) {
            usort($entriesforpath, static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp']);
            $groups[] = ['path' => $path, 'entries' => $entriesforpath];
        }
        usort($groups, static fn (array $a, array $b): int => $a['entries'][0]['timestamp'] <=> $b['entries'][0]['timestamp']);
        return $groups;
    }

    /**
     * Raw entries, identifier => {timestamp, path, operation, error_class, course_id}.
     * Empty if no note file exists, no person is logged in,
     * or the file does not contain a valid JSON object (ponytail: no
     * dedicated repair path for a note file broken by hand -
     * it is written plugin-internally, a defective state is the
     * rare edge case, not the normal case).
     *
     * @return array<string, array{timestamp: int, path: string, operation: string, error_class: string, course_id: int}>
     */
    private static function all(): array {
        global $USER;

        if (empty($USER->id)) {
            return [];
        }

        $file = get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::PENDING_FILENAME
        );
        if (!$file) {
            return [];
        }

        $decoded = json_decode($file->get_content(), true);
        return (is_array($decoded) && !array_is_list($decoded)) ? $decoded : [];
    }

    /**
     * @param array<string, array{timestamp: int, path: string, operation: string, error_class: string, course_id: int}> $entries
     * @throws \moodle_exception pendingnotequotaexceeded
     */
    private static function save(array $entries): void {
        $content = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $contextid = storage_anchor::own_context()->id;
        $directory = storage_anchor::anchor_root();
        $existing = get_file_storage()->get_file(
            $contextid,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            $directory,
            storage_anchor::PENDING_FILENAME
        );

        $oldsize = $existing ? $existing->get_filesize() : 0;
        $remaining = storage_anchor::remaining_quota();
        if ($remaining !== null && (strlen($content) - $oldsize) > $remaining) {
            throw new \moodle_exception('pendingnotequotaexceeded', 'local_coursepilot');
        }

        storage_anchor::replace(
            $existing ?: null,
            storage_anchor::filerecord($contextid, $directory, storage_anchor::PENDING_FILENAME),
            $content
        );
    }

    /**
     * @param array<string, mixed> $existing Already assigned identifiers (keys).
     * @return string
     */
    private static function generate_identifier(array $existing): string {
        do {
            $identifier = strtoupper(random_string(8));
        } while (isset($existing[$identifier]));
        return $identifier;
    }
}
