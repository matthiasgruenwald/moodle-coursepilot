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

use context_course;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_question\local\bank\question_bank_helper;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

/**
 * Idempotentes Anlegen einer benannten Fragenbank-Aktivitaet (Spec 0017 §1,
 * Ticket #412): legt eine Fragenbank mit dem genannten Namen an, oder
 * verwendet eine gleichnamige bestehende wieder - ein zweiter Lauf mit
 * demselben Namen erzeugt keine zweite Bank.
 *
 * Eigenstaendige Portierung von
 * local_coursepilot\external\ensure_question_bank - local_coursepilot hat laut
 * Spec 0012 keine Laufzeitabhaengigkeit auf das andere Plugin (siehe
 * get_question_categories.php aus #342, derselbe Fund). Anders als das
 * lokale Vorbild: Lehrkraft-deutsche Meldung statt Englisch (CLAUDE.md), und
 * nur die native Moodle-Berechtigungspruefung - keine Zusatz-Capability.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ensure_question_bank extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Kurs-ID'),
            'name' => new external_value(PARAM_TEXT, 'Name der Fragensammlung, z.B. "Biologie 9a - Immunsystem"'),
        ]);
    }

    /**
     * @param int $courseid
     * @param string $name
     * @return array
     */
    public static function execute(int $courseid, string $name): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Native Berechtigungspruefung: eine Fragenbank ist eine Aktivitaet.
        require_capability('moodle/course:manageactivities', $coursecontext);

        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.course = :courseid
                   AND m.name = :modulename
                   AND qb.type = :type
                   AND qb.name = :name
              ORDER BY cm.id ASC";

        $existing = $DB->get_record_sql($sql, [
            'courseid' => $course->id,
            'modulename' => $modulename,
            'type' => question_bank_helper::TYPE_STANDARD,
            'name' => $params['name'],
        ], IGNORE_MULTIPLE);

        if ($existing) {
            $bankcontext = context_module::instance((int) $existing->id);
            self::validate_context($bankcontext);
            require_capability('local/coursepilot:use', $bankcontext);
            $topcategory = question_get_top_category($bankcontext->id, true);

            return [
                'questionbankid' => (int) $existing->id,
                'name' => $params['name'],
                'contextid' => (int) $bankcontext->id,
                'topcategoryid' => (int) $topcategory->id,
                'angelegt' => false,
                'meldung' => 'Fragensammlung "' . $params['name'] . '" existierte bereits, wird wiederverwendet.',
            ];
        }

        $bankcm = question_bank_helper::create_default_open_instance(
            $course,
            $params['name'],
            question_bank_helper::TYPE_STANDARD
        );
        $bankcontext = $bankcm->context;
        self::validate_context($bankcontext);
        require_capability('local/coursepilot:use', $bankcontext);
        $topcategory = question_get_top_category($bankcontext->id, true);

        return [
            'questionbankid' => (int) $bankcm->id,
            'name' => $params['name'],
            'contextid' => (int) $bankcontext->id,
            'topcategoryid' => (int) $topcategory->id,
            'angelegt' => true,
            'meldung' => 'Fragensammlung "' . $params['name'] . '" angelegt.',
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID der (angelegten oder wiederverwendeten) Fragensammlung'),
            'name' => new external_value(PARAM_TEXT, 'Name der Fragensammlung'),
            'contextid' => new external_value(PARAM_INT, 'Kontext-ID der Fragensammlung'),
            'topcategoryid' => new external_value(PARAM_INT, 'ID der obersten Kategorie der Fragensammlung'),
            'angelegt' => new external_value(PARAM_BOOL, 'true, wenn neu angelegt; false, wenn eine gleichnamige wiederverwendet wurde'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Meldung'),
        ]);
    }
}
