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

namespace local_coursepilot\external;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Repo-Pruefung (Ticket #391, Abnahmekriterium): keine der sechs in
 * Moodle 5.2 deprecated Funktionen (MDL-86854/MDL-86862 und Nachbarschaft)
 * wird durch die neuen Struktur-/Positions-Endpunkte NEU eingefuehrt.
 * move_section_to()/moveto_module() werden weiterhin intern von Moodle-Core
 * selbst genutzt (z.B. hinter stateactions::cm_move()/section_move_after())
 * - das ist Moodles eigene Implementierung hinter der Abstraktion, kein
 * Aufruf aus diesem Plugin heraus, und deshalb hier NICHT geprueft. Geprueft
 * wird ausschliesslich der eigene Plugin-Code.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversNothing]
final class no_deprecated_move_functions_test extends \advanced_testcase {

    /** @var string[] Funktionsnamen, die Ticket #391 als in 5.2 deprecated benennt. */
    private const FORBIDDEN_FUNCTIONS = [
        'move_section_to',
        'moveto_module',
        'course_delete_module',
        'duplicate_module',
        'set_coursemodule_groupmode',
        'course_set_marker',
        'set_section_visible',
    ];

    public function test_plugin_source_never_calls_forbidden_functions_directly(): void {
        // Nur classes/ (Produktivcode), nicht tests/: tests/retention_test.php
        // ruft course_delete_module() bewusst auf, um eine Loeschung zu
        // simulieren (Aufbewahrungsfrist-Test, #387) - eine legitime,
        // bereits bestehende Nutzung, kein Verstoss durch Ticket #391.
        $root = dirname(__DIR__, 2) . '/classes';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = self::strip_comments_and_strings(file_get_contents($file->getPathname()));
            foreach (self::FORBIDDEN_FUNCTIONS as $functionname) {
                if (preg_match('/(?<![\w:>])' . preg_quote($functionname, '/') . '\s*\(/', $contents) === 1) {
                    $violations[] = $functionname . ' in ' . $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations, "Verbotene Funktionsaufrufe gefunden:\n" . implode("\n", $violations));
    }

    /**
     * Entfernt Kommentare und String-Literale (tokenbasiert), damit ein
     * dokumentierender Prosa-Verweis auf eine verbotene Funktion (wie in
     * dieser Klasse selbst, oder in move_section.php/move_module.php)
     * keinen falschen Treffer erzeugt - geprueft wird nur tatsaechlicher
     * PHP-Code.
     *
     * @param string $source
     * @return string
     */
    private static function strip_comments_and_strings(string $source): string {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }
}
