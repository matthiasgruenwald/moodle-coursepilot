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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\plugin_meta;

defined('MOODLE_INTERNAL') || die();

/**
 * Versionsauskunft ueber Moodle und das Plugin (#425 F3).
 *
 * Anlass war der Kopf der Fragetyp-Ablage (`spike-fragetypen.md`): er ist die
 * Verfallsanzeige der Datei, konnte seinen Versionsstand aber von keinem
 * Werkzeug beziehen und blieb deshalb bei "nicht ermittelt" - eine
 * Verfallsanzeige, die nichts anzeigt. Derselbe Bedarf besteht bei der
 * Instanzpruefung (#340) und in jedem Support-Fall ("welche Version laeuft
 * dort eigentlich?").
 *
 * Rein lesend, keine Kurs-Capability: die Angaben stehen ohnehin in jeder
 * Moodle-Fusszeile und sind an keinen Kurs gebunden. Der Fernzugriff selbst
 * ist bereits durch 'local/coursepilot:useremote' im Dispatcher geprueft.
 *
 * #573: die Rueckgabeschluessel "date"/"message" waren bis hierher als
 * "datum"/"meldung" deklariert - eine von #569-#572 uebersehene Luecke, die
 * beim Entfernen der Uebersetzungsschicht aufgefallen ist. Jetzt unmittelbar
 * englisch wie jedes andere Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class get_version_info extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        global $CFG;

        self::validate_context(\context_system::instance());

        // $plugin->version/->release kommen aus version.php der laufenden
        // Dateien, nicht aus config_plugins: bei einem Deploy ohne
        // upgrade.php-Lauf laufen beide auseinander, und genau dieser Fall
        // ist der, den ein Support-Blick sehen muss. Dieselbe kanonische
        // Quelle wie dispatcher::plugin_release() (#577).
        $plugin = plugin_meta::current();
        $installed = get_config('local_coursepilot', 'version');

        $pluginversion = (int) $plugin->version;
        $pluginrelease = (string) $plugin->release;

        $meldung = 'Moodle ' . $CFG->release . ' (Branch ' . $CFG->branch . '), Coursepilot-Plugin '
            . $pluginrelease . ' (Version ' . $pluginversion . ').';
        if ($installed !== false && (int) $installed !== $pluginversion) {
            $meldung .= ' Achtung: In der Datenbank steht Version ' . (int) $installed
                . ' - upgrade.php wurde nach dem letzten Deploy nicht ausgefuehrt.';
        }

        return [
            'moodle_release' => (string) $CFG->release,
            'moodle_version' => (string) $CFG->version,
            'moodle_branch' => (string) $CFG->branch,
            'plugin_version' => $pluginversion,
            'plugin_release' => $pluginrelease,
            'plugin_version_db' => $installed === false ? 0 : (int) $installed,
            'date' => date('Y-m-d'),
            'message' => $meldung,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'moodle_release' => new external_value(PARAM_TEXT, 'Moodle-Release, z.B. "5.0.2 (Build: 20250714)"'),
            'moodle_version' => new external_value(PARAM_TEXT, 'Moodle-Versionsstempel, z.B. "2025041400.05"'),
            'moodle_branch' => new external_value(PARAM_TEXT, 'Moodle-Zweig, z.B. "500"'),
            'plugin_version' => new external_value(PARAM_INT, '$plugin->version aus version.php der laufenden Dateien'),
            'plugin_release' => new external_value(PARAM_TEXT, '$plugin->release, z.B. "2.0.0-beta"'),
            'plugin_version_db' => new external_value(
                PARAM_INT,
                'In der Datenbank eingetragene Plugin-Version; weicht sie ab, fehlt ein upgrade.php-Lauf'
            ),
            'date' => new external_value(PARAM_TEXT, 'Server date YYYY-MM-DD - fills "last verified on"'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German summary of the same version data'),
        ]);
    }
}
