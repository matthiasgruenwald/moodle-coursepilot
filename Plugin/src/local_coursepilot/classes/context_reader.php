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
 * The read pipeline of one context file, shared by read_context_file and the session start
 * (#784): canonical name with legacy fallback, personal-data lock (#344, ADR 0011), table of
 * contents, section or whole file (#782, ADR 0037 item 3).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_reader {
    /**
     * Reads one file with the personal-data lock and the table-of-contents selection. Shared with the
     * session start, so both tools behave identically.
     *
     * @param string $path Normalised path.
     * @param pointer_location|null $previous Previous location, null for the current one.
     * @param string $section Heading of the section to read, empty for none.
     * @param bool $whole Return the whole file even when it is long.
     * @return mixed[]|null The tool response, null when the file does not exist.
     * @throws \moodle_exception contextfilelocked for marked personal data with the switch off.
     */
    public static function read_selected(string $path, ?pointer_location $previous, string $section, bool $whole): ?array {
        $file = self::load($path, $previous);
        if ($file === null) {
            return null;
        }

        // Personal-data switch (#344, ADR 0011): checks the frontmatter marker,
        // not a content-based guess; see local_coursepilot\personal_data.
        if (personal_data::is_marked($file['content']) && !personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }

        // The hash, size and time always describe the whole file (#782).
        $selected = self::select($file['path'], $file['content'], $section, $whole);
        return [
            'path' => $file['path'],
            'filename' => basename($file['path']),
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'content' => $selected['content'],
            'contenthash' => $file['contenthash'],
            'timemodified' => $file['timemodified'],
            'partial' => $selected['partial'],
            'toc' => $selected['toc'],
            'hint' => $selected['hint'],
        ];
    }

    /**
     * Chooses what to return of the content: all, one section or only the table of contents.
     *
     * @param string $path Resolved path, for messages.
     * @param string $content Whole file content.
     * @param string $section Heading to read, empty for none.
     * @param bool $whole
     * @return array{content: string, partial: bool, toc: array<int, array<string, mixed>>, hint: string}
     */
    private static function select(string $path, string $content, string $section, bool $whole): array {
        $lines = context_toc::lines($content);
        $all = ['content' => $content, 'partial' => false, 'toc' => [], 'hint' => ''];
        if ($whole || ($section === '' && count($lines) <= context_toc::MAX_LINES)) {
            return $all;
        }
        $sections = context_toc::sections($lines);
        if ($section === '') {
            return self::table_of_contents($sections, count($lines));
        }
        $found = context_toc::find($sections, $section);
        if (count($found) !== 1) {
            throw self::section_error($found, $sections, $section, $path);
        }
        [$start, $end] = [$found[0]['start_line'], $found[0]['end_line']];
        return [
            'content' => context_toc::slice($lines, $start, $end),
            'partial' => true,
            'toc' => [],
            'hint' => get_string('contextfilesection', 'local_coursepilot', [
                'heading' => $found[0]['heading'], 'start' => $start, 'end' => $end, 'lines' => count($lines),
            ]),
        ];
    }

    /**
     * The answer for a long file without a wanted section: no content, the table of contents and a hint.
     *
     * @param mixed[] $sections From {@see context_toc::sections()}.
     * @param int $count Number of lines.
     * @return array{content: string, partial: bool, toc: array<int, array<string, mixed>>, hint: string}
     */
    private static function table_of_contents(array $sections, int $count): array {
        $hint = $sections
            ? get_string('contextfiletoc', 'local_coursepilot', ['lines' => $count, 'example' => $sections[0]['heading']])
            : get_string('contextfiletocnoheadings', 'local_coursepilot', $count);
        return ['content' => '', 'partial' => true, 'toc' => $sections, 'hint' => $hint];
    }

    /**
     * The error for a wanted section that is not found exactly once.
     *
     * @param mixed[] $found Sections with the wanted heading.
     * @param mixed[] $sections All sections of the file.
     * @param string $section Wanted heading.
     * @param string $path Resolved path, for the message.
     * @return \moodle_exception
     */
    private static function section_error(array $found, array $sections, string $section, string $path): \moodle_exception {
        $a = ['section' => $section, 'path' => $path];
        if ($found) {
            return new \moodle_exception('contextsectionambiguous', 'local_coursepilot', '', $a + [
                'count' => count($found),
                'lines' => implode(', ', array_column($found, 'start_line')),
            ]);
        }
        return new \moodle_exception('contextsectionunknown', 'local_coursepilot', '', $a + [
            'headings' => $sections ? implode('; ', array_column($sections, 'heading')) : '-',
        ]);
    }

    /**
     * Reads the canonical file; old names are read only when it is absent. Access and storage
     * errors are not caught and the selected current/previous location is never crossed.
     *
     * @param string $path Normalised path.
     * @param pointer_location|null $previous Previous location, null for the current one.
     * @return mixed[]|null File data, null when neither the canonical nor the legacy file exists.
     */
    private static function load(string $path, ?pointer_location $previous): ?array {
        $read = static fn(string $p): ?array => $previous
            ? context_area::read_previous_location($p, $previous)
            : context_area::read($p);
        $file = $read($path);
        $legacyname = [
            'templates.md' => 'vorlagen.md',
            'notepad.md' => 'merkzettel.md',
            'CONTEXT-people.md' => 'CONTEXT.personen.md',
        ][basename($path)] ?? null;
        if ($file === null && $legacyname !== null) {
            $file = $read((dirname($path) === '.' ? '' : dirname($path) . '/') . $legacyname);
        }
        return $file;
    }
}
