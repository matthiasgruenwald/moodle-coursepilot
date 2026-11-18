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
 * Table of contents of a long Markdown context file (Spec 0030, ADR 0037 item 3).
 * Pure: works on text only, no storage. Ported from the moocp-contextmanager
 * "Inhaltsverzeichnis": sections are the "##"/"###" headings, outside code
 * fences, each running to the next heading of the same or a higher level.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_toc {
    /** Files with more lines than this are delivered as a table of contents. */
    public const MAX_LINES = 100;

    /**
     * Splits text into lines like Dart's LineSplitter: \n, \r\n and \r, no empty last line
     * for a trailing line break.
     *
     * @param string $text
     * @return string[]
     */
    public static function lines(string $text): array {
        if ($text === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\n|\r/', $text);
        if (end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /**
     * The sections of the file in heading order.
     *
     * @param string[] $lines From {@see lines()}.
     * @return array<int, array{level: int, heading: string, start_line: int, end_line: int}> 1-based, inclusive.
     */
    public static function sections(array $lines): array {
        return self::with_ends(self::headings($lines), count($lines));
    }

    /**
     * The level two and three headings outside code fences.
     *
     * @param string[] $lines
     * @return array<int, array{level: int, heading: string, start_line: int}>
     */
    private static function headings(array $lines): array {
        $heads = [];
        $fence = null;
        foreach ($lines as $i => $line) {
            if ($fence !== null) {
                if (self::closes_fence($line, $fence)) {
                    $fence = null;
                }
                continue;
            }
            if (preg_match('/^\s*(\x60{3,}|~{3,})/', $line, $m)) {
                $fence = $m[1];
                continue;
            }
            if (preg_match('/^(#{2,3}) +(\S.*?)\s*$/', $line, $m)) {
                $heads[] = ['level' => strlen($m[1]), 'heading' => $m[2], 'start_line' => $i + 1];
            }
        }
        return $heads;
    }

    /**
     * Adds the last line of each section: the line before the next heading of the same or a higher level.
     *
     * @param mixed[] $heads
     * @param int $count Number of lines of the file.
     * @return array<int, array{level: int, heading: string, start_line: int, end_line: int}>
     */
    private static function with_ends(array $heads, int $count): array {
        $sections = [];
        foreach ($heads as $k => $head) {
            $end = $count;
            for ($j = $k + 1; $j < count($heads); $j++) {
                if ($heads[$j]['level'] <= $head['level']) {
                    $end = $heads[$j]['start_line'] - 1;
                    break;
                }
            }
            $sections[] = $head + ['end_line' => $end];
        }
        return $sections;
    }

    /**
     * Sections whose heading text equals the wanted one, ignoring case, blanks and a leading hash marker.
     *
     * @param mixed[] $sections From {@see sections()}.
     * @param string $wanted
     * @return mixed[] Matches, in file order.
     */
    public static function find(array $sections, string $wanted): array {
        $wanted = self::normalise(preg_replace('/^#{1,6}\s+/', '', ltrim($wanted)));
        $found = [];
        foreach ($sections as $section) {
            if (self::normalise($section['heading']) === $wanted) {
                $found[] = $section;
            }
        }
        return $found;
    }

    /**
     * Lower-cases, trims and collapses blanks.
     *
     * @param string $text
     * @return string
     */
    private static function normalise(string $text): string {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * The text of lines $start to $end (1-based, inclusive).
     *
     * @param string[] $lines
     * @param int $start
     * @param int $end
     * @return string
     */
    public static function slice(array $lines, int $start, int $end): string {
        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * Whether a line closes the open fence: same character, at least as long, nothing else.
     *
     * @param string $line
     * @param string $open Opening fence.
     * @return bool
     */
    private static function closes_fence(string $line, string $open): bool {
        return preg_match('/^\s*(\x60{3,}|~{3,})\s*$/', $line, $m) === 1
            && $m[1][0] === $open[0] && strlen($m[1]) >= strlen($open);
    }
}
