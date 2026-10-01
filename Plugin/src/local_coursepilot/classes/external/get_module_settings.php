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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\module_state;
use local_coursepilot\catalog\registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Vollstaendiger Ist-Stand einer Aktivitaet (Spec 0015 §3.2, Ticket #384):
 * das get_moduleinfo_data()-Feldobjekt als JSON, ohne Coursepilot-eigene
 * Zwischendarstellung - jede Uebersetzungsschicht muesste bei jedem
 * Moodle-Update nachgezogen werden.
 *
 * Kein Aufruf von course/modlib.php::get_moduleinfo_data(): die Funktion
 * ruft intern can_update_moduleinfo(), das 'moodle/course:manageactivities'
 * verlangt - dieser Endpunkt ist laut Abnahmekriterium auch ohne
 * Bearbeitungsrecht nutzbar (nur 'local/coursepilot:use', wie die uebrigen
 * Lesewerkzeuge). Die Feldzusammenstellung ist deshalb hier dupliziert, mit
 * der Lese-Capability statt der Bearbeiten-Capability.
 *
 * ponytail: drei Anreicherungsbloecke aus get_moduleinfo_data() bleiben aussen
 * vor - introeditor legt einen Draft-Dateibereich an (Schreib-Nebenwirkung in
 * einem Lesewerkzeug, unerwuenscht; intro/introformat stehen bereits roh in
 * der Instanzzeile), "advancedgradingmethod_*" sind Formular-Hilfsfelder fuer
 * den Bewertungsdialog, und die outcome_<id>/gradepass-/gradecat-Feldnamen aus
 * den Grade-Items der Instanz (course/modlib.php:848-886) sind kein
 * DB-Ist-Stand der Aktivitaet selbst, sondern vom Gradebook abgeleitet. Falls
 * ein Rundtrip sie braucht: gezielt ergaenzen. Fuer Quiz liest module_state
 * gradepass/grademax aus dem primaeren Grade-Item (#580), ohne Formatierung.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_module_settings extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
        ]);
    }

    /**
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $data = module_state::effective_settings($cm);
        $catalogclass = registry::for((string) $cm->modname);

        return [
            'cmid' => (int) $cm->id,
            'modname' => (string) $cm->modname,
            'settings_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'learner_locks' => $catalogclass === null ? [] : learner_locks::existing($catalogclass, $data),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'settings_json' => new external_value(
                PARAM_RAW,
                get_string('modulesettingsdescription', 'local_coursepilot')
            ),
            'learner_locks' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name in the catalog vocabulary'),
                    'value_json' => new external_value(PARAM_RAW, 'JSON-encoded current value'),
                    'reason' => new external_value(PARAM_TEXT, 'Why this value makes learners wait for the teacher'),
                ]),
                'Existing learner locks of this activity (describe_module_fields: learner_lock), empty when none'
            ),
        ]);
    }
}
