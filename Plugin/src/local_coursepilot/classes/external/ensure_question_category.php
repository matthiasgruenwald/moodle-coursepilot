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

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Idempotently find or create a question bank category (Spec 0017 §1, #412).
 * Combines what used to be get_question_categories plus create_question_category,
 * so a skill need not first ask whether the category exists on every run.
 *
 * Resolve the context from the parent category (Spec 0017, following
 * local_coursepilot\external\update_question_category::resolve_question_bank_context()).
 * parent identifies an existing category, usually ensure_question_bank's
 * topcategoryid or a previously created child. That supplies the question bank
 * context without an additional courseid/questionbankid parameter.
 *
 * A same-named category matches only under the same parent. A namesake under
 * another parent, e.g. in another question collection, does not count.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ensure_question_category extends external_api {
    /** @var int Sort position of new categories; identical to local_coursepilot\question_category_defaults::SORTORDER. */
    private const SORTORDER = 999;

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(
                PARAM_TEXT,
                'Category name, convention: "<section number> <title>", e.g. "7.2 Materials and their properties"'
            ),
            'parent' => new external_value(PARAM_INT, 'ID of the parent category (e.g. topcategoryid from ensure_question_bank)'),
        ]);
    }

    /**
     * Runs the ensure question category tool.
     *
     * @param string $name
     * @param int $parent
     * @return mixed[]
     */
    public static function execute(string $name, int $parent): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'name' => $name,
            'parent' => $parent,
        ]);

        $parentcategory = $DB->get_record('question_categories', ['id' => $params['parent']], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $parentcategory->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:managecategory', $context);

        $existing = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'parent' => $parentcategory->id,
            'name' => $params['name'],
        ]);

        if ($existing) {
            return [
                'id' => (int) $existing->id,
                'name' => $existing->name,
                'parent' => (int) $existing->parent,
                'contextid' => (int) $context->id,
                'created' => false,
                'message' => get_string('questioncategoryreused', 'local_coursepilot', $params['name']),
            ];
        }

        $record = new \stdClass();
        $record->name = $params['name'];
        $record->contextid = $context->id;
        $record->info = '';
        $record->infoformat = FORMAT_HTML;
        $record->stamp = make_unique_id_code();
        $record->parent = $parentcategory->id;
        $record->sortorder = self::SORTORDER;
        $record->idnumber = null;

        $newid = $DB->insert_record('question_categories', $record);

        return [
            'id' => (int) $newid,
            'name' => $params['name'],
            'parent' => (int) $parentcategory->id,
            'contextid' => (int) $context->id,
            'created' => true,
            'message' => get_string('questioncategorycreated', 'local_coursepilot', $params['name']),
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'ID of the (created or reused) category'),
            'name' => new external_value(PARAM_TEXT, 'Category name'),
            'parent' => new external_value(PARAM_INT, 'ID of the parent category'),
            'contextid' => new external_value(PARAM_INT, 'Context ID of the question bank'),
            'created' => new external_value(
                PARAM_BOOL,
                'true if newly created; false if a same-named one under the same parent was reused'
            ),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing message'),
        ]);
    }
}
