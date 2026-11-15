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
use local_coursepilot\catalog\quiz_write;
use local_coursepilot\write_gate;
use moodle_exception;

/**
 * Public quiz settings update adapter; the catalog owns the atomic native write.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_quiz_settings extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz'),
            'fields_json' => new external_value(
                PARAM_RAW,
                get_string('quizpatchfields', 'local_coursepilot')
            ),
            'mode' => new external_value(
                PARAM_ALPHANUMEXT,
                'Mode bundle: "mini-check", "progress-check" or "final-test". Bundle values apply only '
                    . 'to fields not explicitly named in fields_json. Empty = keep the mode.',
                VALUE_DEFAULT,
                ''
            ),
            'grade' => new external_value(
                PARAM_FLOAT,
                'New maximum grade of the quiz - runs through Moodle\'s own grading path (scales existing '
                    . 'attempt grades and overall feedback boundaries automatically), not via fields_json. '
                    . '-1 = do not change.',
                VALUE_DEFAULT,
                -1.0
            ),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Runs the update quiz settings tool.
     *
     * @param int $cmid
     * @param string $fieldsjson
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks
     * @return mixed[]
     */
    public static function execute(
        int $cmid,
        string $fieldsjson,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'fields_json' => $fieldsjson,
            'mode' => $mode,
            'grade' => $grade,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        write_gate::assert_writable('quiz');

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        return quiz_write::update($cm, $patch, $params['mode'], $params['grade'], $params[learner_locks::PARAMETER]);
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing side-effect note'),
                'Triggered side effects, empty when none were triggered'
            ),
        ]);
    }
}
