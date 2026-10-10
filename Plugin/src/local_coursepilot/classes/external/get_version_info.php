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

namespace local_coursepilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\plugin_meta;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle and plugin version information (#425 F3).
 *
 * The question-type notes header (spike-fragetypen.md) indicates freshness
 * but previously had no tool to obtain versions and stayed undetermined.
 * Instance checks (#340) and support also need this information.
 *
 * Read-only without course capabilities: these versions appear in Moodle
 * footers and belong to no course. Dispatcher already checks remote access.
 *
 * #573: date/message previously used datum/meldung, a gap missed by
 * #569–#572 and found while removing the translation layer. Now declared
 * directly in English like every other tool.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class get_version_info extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Runs the get version info tool.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;

        self::validate_context(\context_system::instance());

        // Read version/release from the running version.php, not config_plugins.
        // Deploying without upgrade.php can diverge from installed DB metadata:
        // support must see that mismatch. Same canonical source as
        // dispatcher::plugin_release() (#577).
        $plugin = plugin_meta::current();
        $installed = get_config('local_coursepilot', 'version');

        $pluginversion = (int) $plugin->version;
        $pluginrelease = (string) $plugin->release;

        $message = get_string('versioninfosummary', 'local_coursepilot', (object) [
            'moodlerelease' => $CFG->release,
            'branch' => $CFG->branch,
            'pluginrelease' => $pluginrelease,
            'pluginversion' => $pluginversion,
        ]);
        if ($installed !== false && (int) $installed !== $pluginversion) {
            $message .= ' ' . get_string('versioninfoupgradewarning', 'local_coursepilot', (int) $installed);
        }

        return [
            'moodle_release' => (string) $CFG->release,
            'moodle_version' => (string) $CFG->version,
            'moodle_branch' => (string) $CFG->branch,
            'plugin_version' => $pluginversion,
            'plugin_release' => $pluginrelease,
            'plugin_version_db' => $installed === false ? 0 : (int) $installed,
            'date' => date('Y-m-d'),
            'message' => $message,
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'moodle_release' => new external_value(PARAM_TEXT, 'Moodle release, e.g. "5.0.2 (Build: 20250714)"'),
            'moodle_version' => new external_value(PARAM_TEXT, 'Moodle version stamp, e.g. "2025041400.05"'),
            'moodle_branch' => new external_value(PARAM_TEXT, 'Moodle branch, e.g. "500"'),
            'plugin_version' => new external_value(PARAM_INT, '$plugin->version from the running source version.php'),
            'plugin_release' => new external_value(PARAM_TEXT, '$plugin->release, e.g. "2.0.0-beta"'),
            'plugin_version_db' => new external_value(
                PARAM_INT,
                'Plugin version registered in the database; a mismatch indicates a missing upgrade.php run'
            ),
            'date' => new external_value(PARAM_TEXT, 'Server date YYYY-MM-DD - fills "last verified on"'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing summary of the same version data'),
        ]);
    }
}
