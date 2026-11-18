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
 * Pure reference matching of the archive tool (Spec 0030, ADR 0036 item 3): which lines of an index
 * file point to a moved file. A reference is any path-like piece of a line: the target of a
 * Markdown link (also in `<...>`), a backtick path, or a bare word. It counts when it resolves,
 * relative to the folder of the index file or to the root (also with a leading `/`), to a moved
 * file: `#anchor` and `?query` are dropped, `./` and `..` and `%20` resolved, case ignored, an
 * address with a scheme never matches. Better one report too many than one too few: the AI checks
 * every hit. The result names file, line and path as written, never the line itself (data, K3).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_references {
    /** Longest candidate path taken from a line. */
    private const MAX_CANDIDATE = 300;

    /** Longest path returned; fragment and query never are (K3: the file is untrusted data). */
    private const MAX_REPORTED = 120;

    /**
     * Finds the references to moved files in one index file.
     *
     * @param string $file Path of the index file, relative to the context root.
     * @param string $text Its content.
     * @param string[] $moved Moved files by their old path, relative to the context root.
     * @return array<int, array{file: string, line: int, path: string, moved: string}>
     */
    public static function find(string $file, string $text, array $moved): array {
        $folder = str_contains($file, '/') ? substr($file, 0, strrpos($file, '/')) : '';
        $targets = [];
        foreach ($moved as $path) {
            $targets[strtolower($path)] = $path;
        }
        $found = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $index => $line) {
            $reported = [];
            foreach (self::candidates($line) as $candidate) {
                foreach (array_unique([self::resolve($candidate, $folder), self::resolve($candidate, '')]) as $resolved) {
                    $target = $resolved === null ? null : ($targets[strtolower($resolved)] ?? null);
                    if ($target === null || isset($reported[$target . '|' . $candidate])) {
                        continue;
                    }
                    $reported[$target . '|' . $candidate] = true;
                    $found[] = ['file' => $file, 'line' => $index + 1, 'path' => self::reported($candidate), 'moved' => $target];
                }
            }
        }
        return $found;
    }

    /**
     * The path as written, without `#fragment` and `?query`, cut to a safe length.
     *
     * @param string $candidate
     * @return string
     */
    private static function reported(string $candidate): string {
        return mb_strcut(preg_split('/[#?]/', $candidate, 2)[0], 0, self::MAX_REPORTED, 'UTF-8');
    }

    /**
     * All path-like pieces of a line: link targets, backtick contents and words.
     *
     * @param string $line
     * @return string[]
     */
    private static function candidates(string $line): array {
        $found = [];
        foreach (
            [
                '/\]\(\s*<([^>]*)>/', '/\]\(\s*([^)\s]*)/', '/\x60([^\x60]+)\x60/', '/[^\s()\[\]<>\x60"\'|*,;]+/',
            ] as $pattern
        ) {
            preg_match_all($pattern, $line, $matches);
            array_push($found, ...($matches[1] ?? $matches[0]));
        }
        $found = array_map('trim', array_unique($found));
        return array_values(array_filter(
            $found,
            static fn (string $c): bool => $c !== '' && strlen($c) <= self::MAX_CANDIDATE
        ));
    }

    /**
     * Resolves a written path against a folder; null when it leaves the root, has a scheme or
     * nothing is left.
     *
     * @param string $written Path as written in the index file.
     * @param string $base Folder relative to the root, '' for the root.
     * @return string|null
     */
    private static function resolve(string $written, string $base): ?string {
        $path = preg_replace('/[.:!]+$/', '', $written);
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path)) {
            return null;
        }
        foreach (['#', '?'] as $separator) {
            $at = strpos($path, $separator);
            $path = $at === false ? $path : substr($path, 0, $at);
        }
        $path = str_replace('\\', '/', rawurldecode($path));
        $parts = ($path !== '' && $path[0] !== '/' && $base !== '') ? explode('/', $base) : [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part !== '..') {
                $parts[] = $part;
                continue;
            }
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
        }
        return $parts === [] ? null : implode('/', $parts);
    }
}
