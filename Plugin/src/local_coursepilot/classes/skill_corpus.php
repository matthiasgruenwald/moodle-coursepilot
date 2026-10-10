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

use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Skill corpus (Spec 0020 §3.1, Issue #450): Markdown files under
 * skills/adapter and skills/reference. The directory is the source;
 * adding a reference file needs no index or code registration.
 *
 * Names are identifiers rather than paths (Spec 0020 §4). get() checks
 * only names scanned by list(); path-like inputs simply match no entry.
 *
 * No cache (Spec 0020 §4): two file reads per session need no MUC layer.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class skill_corpus {
    /** @var string[] The two corpus subdirectories and kinds. */
    private const KINDS = ['adapter', 'reference'];

    /**
     * Catalog entries expose name, kind, trigger and length, without content
     * (Spec 0020 §4).
     *
     * @return array<int, array{name: string, kind: string, trigger: string, length: int, path: string}>
     */
    public static function list(): array {
        $entries = [];
        foreach (self::KINDS as $kind) {
            $paths = glob(self::dir($kind) . '/*.md') ?: [];
            sort($paths);
            foreach ($paths as $path) {
                $content = (string) file_get_contents($path);
                $entries[] = [
                    'name' => basename($path, '.md'),
                    'kind' => $kind,
                    'trigger' => self::trigger_of($content),
                    'length' => mb_strlen($content),
                    'path' => $path,
                ];
            }
        }
        return $entries;
    }

    /**
     * Content, referenced parts and corpus version for one entry.
     *
     * @param string $name Identifier from list(), not a path.
     * @return array{content: string, referenced_parts: string[], corpus_version: string}
     * @throws moodle_exception unknownskillname, listing valid names.
     */
    public static function get(string $name): array {
        foreach (self::list() as $entry) {
            if ($entry['name'] === $name) {
                $content = (string) file_get_contents($entry['path']);
                return [
                    'content' => $content,
                    'referenced_parts' => self::referenced_names($content),
                    'corpus_version' => self::corpus_version(),
                ];
            }
        }
        throw new moodle_exception('unknownskillname', 'local_coursepilot', '', [
            'name' => $name,
            'names' => implode(', ', array_column(self::list(), 'name')),
        ]);
    }

    /**
     * @param string $kind
     * @return string
     */
    private static function dir(string $kind): string {
        global $CFG;
        return $CFG->dirroot . '/local/coursepilot/skills/' . $kind;
    }

    /**
     * Trigger text: frontmatter description for adapters and references
     * (Spec 0020 §4, Issue #453). Without frontmatter, use the first nonempty
     * line with heading markers removed.
     *
     * @param string $content
     * @return string
     */
    private static function trigger_of(string $content): string {
        $description = self::frontmatter_description($content);
        if ($description !== null) {
            return $description;
        }
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return ltrim($line, "# \t");
            }
        }
        return '';
    }

    /**
     * @param string $content
     * @return string|null
     */
    private static function frontmatter_description(string $content): ?string {
        if (!str_starts_with($content, "---\n")) {
            return null;
        }
        $end = strpos($content, "\n---", 4);
        if ($end === false) {
            return null;
        }
        foreach (explode("\n", substr($content, 4, $end - 4)) as $line) {
            if (str_starts_with($line, 'description:')) {
                return trim(substr($line, strlen('description:')));
            }
        }
        return null;
    }

    /**
     * Referenced corpus names, recognized from coursepilot_get_skill("name")
     * (Spec 0020 §3.3) and legacy skills/<name>.md paths (Spec 0012 §5.1).
     *
     * @param string $content
     * @return string[]
     */
    private static function referenced_names(string $content): array {
        preg_match_all('/coursepilot_get_skill\(["\']([A-Za-z0-9_-]+)["\']\)|skills\/([A-Za-z0-9_-]+)\.md/', $content, $matches);
        $names = array_filter(array_merge($matches[1], $matches[2]), static fn (string $name): bool => $name !== '');
        return array_values(array_unique($names));
    }

    /**
     * Corpus version: release and version of the running plugin files,
     * as in external/get_version_info.
     *
     * @return string
     */
    private static function corpus_version(): string {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');

        return $plugin->release . ' (' . $plugin->version . ')';
    }
}
