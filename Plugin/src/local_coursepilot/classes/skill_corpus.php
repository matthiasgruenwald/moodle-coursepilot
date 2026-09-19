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

use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Skill-Korpus (Spec 0020 §3.1, Issue #450): Markdown-Dateien im Plugin,
 * zwei Unterordner (`skills/adapter`, `skills/referenz`), das Verzeichnis
 * selbst ist die Quelle - kein zweiter Index, keine Registrierungsliste je
 * Datei. Eine neue Referenzdatei ist damit eine Datei, kein Code-Aenderungs-
 * vorgang.
 *
 * Der Name ist ein Bezeichner, kein Pfad (Spec 0020 §4): {@see get()} prueft
 * ausschliesslich gegen die aus dem Verzeichnis gescannten Namen - kein
 * Zeichenfilter, keine Pfadnormalisierung. Ein Name mit Pfadanteilen
 * (`../`, fuehrender `/`, Backslash, kodierte Variante) matcht schlicht
 * keinen Dateinamen aus {@see list()} und faellt damit auf denselben Weg wie
 * ein Tippfehler.
 *
 * Kein Cache (Spec 0020 §4): zwei Dateilesevorgaenge je Sitzung rechtfertigen
 * keine MUC-Schicht.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class skill_corpus {

    /** @var string[] Die beiden Korpus-Unterordner = die beiden Arten. */
    private const KINDS = ['adapter', 'referenz'];

    /**
     * Der Katalog: je Korpus-Datei Name, Art, Auslöser und Umfang - kein
     * Inhalt (Spec 0020 §4).
     *
     * @return array<int, array{name: string, art: string, ausloeser: string, umfang: int, path: string}>
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
                    'art' => $kind,
                    'ausloeser' => self::ausloeser($content),
                    'umfang' => mb_strlen($content),
                    'path' => $path,
                ];
            }
        }
        return $entries;
    }

    /**
     * Inhalt, referenzierte Teile und Korpus-Stand eines einzelnen Eintrags.
     *
     * @param string $name Bezeichner aus {@see list()}, kein Pfad.
     * @return array{content: string, referenzierte_teile: string[], korpus_stand: string}
     * @throws moodle_exception unknownskillname, nennt die gueltigen Namen.
     */
    public static function get(string $name): array {
        foreach (self::list() as $entry) {
            if ($entry['name'] === $name) {
                $content = (string) file_get_contents($entry['path']);
                return [
                    'content' => $content,
                    'referenzierte_teile' => self::referenced_names($content),
                    'korpus_stand' => self::korpus_stand(),
                ];
            }
        }
        throw new moodle_exception('unknownskillname', 'local_coursepilot', '', [
            'name' => $name,
            'namen' => implode(', ', array_column(self::list(), 'name')),
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
     * Auslöser einer Korpus-Datei: die Frontmatter-Beschreibung (Spec 0020
     * §4, seit Issue #453 fuer Adapter und Referenzteile gleichermassen),
     * ersatzweise (kein Frontmatter) die erste nichtleere Zeile ohne
     * Ueberschriftenzeichen.
     *
     * @param string $content
     * @return string
     */
    private static function ausloeser(string $content): string {
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
     * Namen der im Inhalt referenzierten Korpus-Teile. Erkannt an zwei
     * Mustern: `coursepilot_get_skill("name")` (Spec 0020 §3.3, der
     * Namensverweis ohne Pfad, den die umgebauten Adapter nutzen) und, fuer
     * noch unveraendert uebernommene Referenzteile, das ältere
     * `skills/<name>.md` (Spec 0012 §5.1: der Pfadbegriff faellt erst mit
     * deren Umbau).
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
     * Der Korpus-Stand: Plugin-Release und -Version der laufenden Dateien
     * (derselbe Griff wie {@see \local_coursepilot\external\get_version_info}).
     *
     * @return string
     */
    private static function korpus_stand(): string {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');

        return $plugin->release . ' (' . $plugin->version . ')';
    }
}
