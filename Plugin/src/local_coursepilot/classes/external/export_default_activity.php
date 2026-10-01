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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\activity_backup;
use local_coursepilot\course_module_placement;
use local_coursepilot\catalog\registry;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Default activity XML of a developed activity type (Spec 0026, #589): creates the
 * activity with the Moodle form defaults, exports it with {@see activity_backup::export()}
 * and removes it again ({@see self::remove()}). Nothing is left in the course.
 * Read-only for the caller (exception in tool_registry::is_write_class).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class export_default_activity extends external_api {

    /** Section used for the throwaway activity; always exists. */
    private const SECTION = 0;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID (the activity is created there temporarily and removed again)'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Developed activity type, e.g. "book"'),
        ]);
    }

    /**
     * @param int $courseid
     * @param string $modname
     * @return array
     * @throws moodle_exception defaultactivitycatalogued, kindexcluded*
     */
    public static function execute(int $courseid, string $modname): array {
        global $CFG, $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'modname' => $modname]);
        $modname = $params['modname'];

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('moodle/backup:backupactivity', $context);
        registry::require_developed($modname, 'defaultactivitycatalogued');
        require_capability("mod/$modname:addinstance", $context);

        require_once($CFG->dirroot . '/course/modlib.php');
        $course = get_course($params['courseid']);
        $before = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$course->id]);
        try {
            $xml = activity_backup::export(self::create_default($course, $modname));
        } finally {
            // Also covers a create that fails after the cm row exists.
            $created = array_diff($DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$course->id]), $before);
            try {
                self::remove($course, $created);
            } catch (\Throwable $cleanup) {
                debugging('export_default_activity cleanup failed: ' . $cleanup->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return ['courseid' => (int) $course->id, 'modname' => $modname, 'xml' => $xml];
    }

    /**
     * Discards the throwaway activity: the history cascades with the delete event, the
     * recycle bin stays untouched (see {@see course_module_placement::discard_failed()}).
     * No transaction: the backup runs DDL, which commits implicitly on MySQL/MariaDB.
     *
     * @param \stdClass $course
     * @param int[] $cmids course modules created by the run
     */
    private static function remove(\stdClass $course, array $cmids): void {
        foreach ($cmids as $cmid) {
            course_module_placement::discard_failed((int) $cmid);
        }
        rebuild_course_cache($course->id, true);
    }

    /**
     * Creates the activity from the module form defaults, as course/modedit.php would
     * for an untouched "add" form.
     *
     * @param \stdClass $course
     * @param string $modname
     * @return \stdClass course module record
     */
    private static function create_default(\stdClass $course, string $modname): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . "/mod/$modname/mod_form.php");
        [$module, , $cw, $cm, $data] = \prepare_new_moduleinfo_data($course, $modname, self::SECTION);
        $formclass = "mod_{$modname}_mod_form";
        $form = new $formclass($data, $cw->section, $cm, $course);
        $form->set_data($data);
        // Unsubmitted form: exportValues() yields the defaults (get_data() would be null).
        // The three-state visibility element cannot export without a submission; it is set below.
        $values = (function () {
            if ($this->_form->elementExists('visible')) {
                $this->_form->removeElement('visible');
            }
            return $this->_form->exportValues();
        })->call($form);

        $moduleinfo = (object) array_merge((array) $data, $values);
        $moduleinfo->modulename = $modname;
        $moduleinfo->module = (int) $module->id;
        $moduleinfo->section = self::SECTION;
        $moduleinfo->cmidnumber ??= '';
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $created = \add_moduleinfo($moduleinfo, $course);
        return get_coursemodule_from_id($modname, $created->coursemodule, $course->id, false, MUST_EXIST);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID the default activity was created in temporarily'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Activity type, e.g. "book"'),
            'xml' => new external_value(PARAM_RAW, 'Activity XML (<module>.xml of the backup) with Moodle default values'),
        ]);
    }
}
