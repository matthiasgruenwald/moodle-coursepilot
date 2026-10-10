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
 * PHPUnit coverage scope (#268).
 *
 * Include every native production PHP file in the 80% line-coverage
 * denominator, even when never executed. Moodle’s tool_phpunit includes
 * uncovered files, counting them at 0% rather than omitting them.
 *
 * Exclude tests (test code), amd (JavaScript), templates (Mustache), skills
 * (Markdown corpus), LICENSE and README.md; none contain production PHP.
 * Include lang because the shipped English string table is PHP source,
 * not generated code.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Coverage scope of the plugin for tool_phpunit.
 */
return new class extends phpunit_coverage_info {
    /** @var array Verzeichnisse des nativen Produktionscodes. */
    protected $includelistfolders = [
        'admin',
        'classes',
        'db',
        'lang',
        'oauth',
        'workbench',
        'werkbank',
    ];

    /** @var array Individual files at the plugin root. */
    protected $includelistfiles = [
        'connections.php',
        'history.php',
        'lib.php',
        'mcp.php',
        'oauth.php',
        'location_selection.php',
        'location_selection_browse.php',
        'ortswahl.php',
        'ortswahl_browse.php',
        'settings.php',
        'surface.php',
        'version.php',
    ];
};
