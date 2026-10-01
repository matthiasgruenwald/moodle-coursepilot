<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

use context_course;
use context_module;
use core_question\local\bank\question_bank_helper;
use local_coursepilot\question_category_defaults;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Renames and/or moves a question category subtree into a selected
 * named question bank context without deleting questions or categories.
 */
class update_question_category extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID of the target course'),
            'categoryid'     => new external_value(PARAM_INT, 'Question category ID to rename and/or move'),
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the target named question bank'),
            'name'           => new external_value(PARAM_TEXT, 'New category name (empty string keeps the current name)', VALUE_DEFAULT, ''),
            'parent'         => new external_value(PARAM_INT, 'Target parent category ID (0 = top category of the selected question bank)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $courseid,
        int $categoryid,
        int $questionbankid,
        string $name = '',
        int $parent = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'categoryid'     => $categoryid,
            'questionbankid' => $questionbankid,
            'name'           => $name,
            'parent'         => $parent,
        ]);

        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $sourcecontext = \context::instance_by_id((int) $category->contextid, MUST_EXIST);
        self::validate_context($sourcecontext);
        require_capability('local/coursepilot:use', $sourcecontext);
        require_capability('moodle/question:managecategory', $sourcecontext);

        $targetcontext = self::resolve_question_bank_context((int) $course->id, $params['questionbankid']);
        self::validate_context($targetcontext);
        require_capability('local/coursepilot:use', $targetcontext);
        require_capability('moodle/question:managecategory', $targetcontext);

        $sourcetopcategory = question_get_top_category($sourcecontext->id, true);
        if ((int) $category->id === (int) $sourcetopcategory->id) {
            throw new \invalid_parameter_exception('The top category cannot be renamed or moved.');
        }

        $targettopcategory = question_get_top_category($targetcontext->id, true);
        $targetparentid = $params['parent'] > 0 ? $params['parent'] : (int) $targettopcategory->id;

        if ($targetparentid === (int) $category->id) {
            throw new \invalid_parameter_exception('A question category cannot be its own parent.');
        }

        if ($params['parent'] > 0) {
            $targetparent = $DB->get_record('question_categories', ['id' => $targetparentid], '*', MUST_EXIST);
            if ((int) $targetparent->contextid !== (int) $targetcontext->id) {
                throw new \invalid_parameter_exception('Target parent category was not found in the selected question bank.');
            }
        }

        $subtreeids = self::collect_subtree_ids((int) $category->id);
        if (in_array($targetparentid, $subtreeids, true)) {
            throw new \invalid_parameter_exception('A question category cannot be moved into one of its own child categories.');
        }

        $targetname = trim($params['name']) !== '' ? $params['name'] : $category->name;

        $conflict = $DB->get_record('question_categories', [
            'contextid' => $targetcontext->id,
            'parent' => $targetparentid,
            'name' => $targetname,
        ]);
        if ($conflict && (int) $conflict->id !== (int) $category->id) {
            throw new \invalid_parameter_exception(
                'A question category with this name already exists under the selected target parent.'
            );
        }

        $moved = (int) $category->contextid !== (int) $targetcontext->id
            || (int) $category->parent !== $targetparentid;
        $renamed = $targetname !== $category->name;

        $transaction = $DB->start_delegated_transaction();

        if ((int) $category->contextid !== (int) $targetcontext->id) {
            foreach ($subtreeids as $subtreeid) {
                $record = new \stdClass();
                $record->id = $subtreeid;
                $record->contextid = $targetcontext->id;
                $DB->update_record('question_categories', $record);
            }
        }

        $update = new \stdClass();
        $update->id = (int) $category->id;
        $update->name = $targetname;
        $update->parent = $targetparentid;
        if ($moved) {
            $update->sortorder = self::next_sortorder();
        }
        $DB->update_record('question_categories', $update);

        $transaction->allow_commit();

        return [
            'id' => (int) $category->id,
            'name' => $targetname,
            'parent' => $targetparentid,
            'contextid' => (int) $targetcontext->id,
            'moved' => $moved,
            'renamed' => $renamed,
            'updatedcategories' => count($subtreeids),
            'message' => 'Question category "' . $targetname . '" successfully updated.',
        ];
    }

    private static function resolve_question_bank_context(int $courseid, int $questionbankid): context_module {
        global $DB;

        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.id = :questionbankid
                   AND cm.course = :courseid
                   AND m.name = :modulename";
        $bankrecord = $DB->get_record_sql($sql, [
            'questionbankid' => $questionbankid,
            'courseid' => $courseid,
            'modulename' => $modulename,
        ]);

        if (!$bankrecord) {
            throw new \invalid_parameter_exception('Selected question bank was not found in this course.');
        }

        return context_module::instance((int) $bankrecord->id);
    }

    private static function collect_subtree_ids(int $categoryid): array {
        global $DB;

        $ids = [];
        $queue = [$categoryid];

        while (!empty($queue)) {
            $currentid = array_shift($queue);
            $ids[] = $currentid;

            $children = $DB->get_records('question_categories', ['parent' => $currentid], 'id ASC', 'id');
            foreach ($children as $child) {
                $queue[] = (int) $child->id;
            }
        }

        return $ids;
    }

    private static function next_sortorder(): int {
        return question_category_defaults::SORTORDER;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Updated category ID'),
            'name' => new external_value(PARAM_TEXT, 'Current category name after update'),
            'parent' => new external_value(PARAM_INT, 'Current parent category ID after update'),
            'contextid' => new external_value(PARAM_INT, 'Context ID of the selected target question bank'),
            'moved' => new external_value(PARAM_BOOL, 'true if the category parent and/or context changed'),
            'renamed' => new external_value(PARAM_BOOL, 'true if the category name changed'),
            'updatedcategories' => new external_value(PARAM_INT, 'Number of affected categories including moved child categories'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
