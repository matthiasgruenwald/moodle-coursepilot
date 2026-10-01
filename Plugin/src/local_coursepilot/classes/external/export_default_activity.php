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
use local_coursepilot\catalog\activity_kind;
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
        self::require_developed($modname);

        require_once($CFG->dirroot . '/course/modlib.php');
        $course = get_course($params['courseid']);
        $binmark = $DB->get_manager()->table_exists('tool_recyclebin_course')
            ? (int) $DB->get_field_sql('SELECT MAX(id) FROM {tool_recyclebin_course} WHERE courseid = ?', [$course->id])
            : null;
        $cm = null;
        try {
            $cm = self::create_default($course, $modname);
            $xml = activity_backup::export($cm);
        } finally {
            self::remove($course, $cm, $binmark);
        }

        return ['courseid' => (int) $course->id, 'modname' => $modname, 'xml' => $xml];
    }

    /**
     * Removes the throwaway activity and every trace of the removal: the history cascades
     * with the delete event; recycle bin items created since $binmark are deleted.
     * No transaction: the backup runs DDL, which commits implicitly on MySQL/MariaDB.
     *
     * @param \stdClass $course
     * @param \stdClass|null $cm
     * @param int|null $binmark highest recycle bin item id of the course before the run, null without recycle bin
     */
    private static function remove(\stdClass $course, ?\stdClass $cm, ?int $binmark): void {
        global $DB;
        if ($cm !== null) {
            course_get_format($course)->delete_module(get_fast_modinfo($course)->get_cm((int) $cm->id), false);
        }
        if ($binmark !== null) {
            $bin = new \tool_recyclebin\course_bin($course->id);
            foreach ($DB->get_records_select('tool_recyclebin_course', 'courseid = ? AND id > ?', [$course->id, $binmark]) as $item) {
                $bin->delete_item($item);
            }
        }
        rebuild_course_cache($course->id, true);
    }

    /**
     * @param string $modname
     * @throws moodle_exception defaultactivitycatalogued, or the exclusion reason of the kind
     */
    private static function require_developed(string $modname): void {
        $kind = registry::kind($modname);
        if ($kind->kind === activity_kind::CATALOGUED) {
            throw new moodle_exception('defaultactivitycatalogued', 'local_coursepilot', '', ['modname' => $modname]);
        }
        if ($kind->kind === activity_kind::EXCLUDED) {
            throw new moodle_exception($kind->reasonkey, 'local_coursepilot');
        }
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
