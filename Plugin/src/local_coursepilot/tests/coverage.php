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

/**
 * Coverage-Quellumfang fuer Moodles PHPUnit-Werkzeug (Issue #268).
 *
 * Zaehlt jede PHP-Datei des nativen Produktionsplugins in den Nenner der
 * 80%-Line-Coverage-Schwelle - auch nie ausgefuehrte Zeilen (Moodles
 * tool_phpunit-Mechanismus behandelt jede hier gelistete Datei standardmaessig
 * als "includeUncoveredFiles", d. h. eine PHP-Datei, die keinen einzigen Test
 * durchlaeuft, zaehlt mit 0% statt gar nicht mitgezaehlt zu werden).
 *
 * Bewusst NICHT gelistet, mit fachlicher Begruendung statt "niedrige
 * Abdeckung":
 * - tests/    - die Testsuite selbst, kein Produktionscode.
 * - amd/      - reines JavaScript (amd/src/location_selection.js, amd/build/*.js),
 *               enthaelt keine PHP-Zeilen, die ein PHP-Coverage-Treiber
 *               ueberhaupt erfassen koennte.
 * - templates/ - Mustache-Vorlagen, keine PHP-Zeilen.
 * - skills/   - deutsche Skill-Korpus-Prosa (Markdown) fuer Lehrkraefte,
 *               keine PHP-Zeilen.
 * - LICENSE, README.md - keine Programmdateien.
 *
 * lang/ ist bewusst ENTHALTEN: lang/en/local_coursepilot.php ist
 * ausgeliefertes PHP (Stringtabelle), kein generierter Code.
 */

defined('MOODLE_INTERNAL') || die();

return new class extends phpunit_coverage_info {
    /** @var array Verzeichnisse des nativen Produktionscodes. */
    protected $includelistfolders = [
        'admin',
        'classes',
        'db',
        'lang',
        'oauth',
        'workbench',
    ];

    /** @var array Einzeldateien im Plugin-Wurzelverzeichnis. */
    protected $includelistfiles = [
        'connections.php',
        'history.php',
        'lib.php',
        'mcp.php',
        'oauth.php',
        'location_selection.php',
        'location_selection_browse.php',
        'settings.php',
        'surface.php',
        'version.php',
    ];
};
