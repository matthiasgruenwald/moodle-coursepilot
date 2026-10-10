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

use coding_exception;
use context_module;
use core_availability\tree;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\registry;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The only write path for restrictions (Spec 0015, ticket #393): builds the
 * native "availability" JSON from teacher-friendly arguments instead of
 * accepting it raw - raw JSON is not a contract an AI hits reliably, and a
 * broken value written via the direct DB route makes the course page
 * unreachable (availability/classes/info.php builds a tree from the stored
 * JSON and throws a coding_exception on an invalid structure).
 *
 * Through this endpoint the JSON is created exclusively from the native
 * "get_json()" factories of the three practically relevant condition types
 * (availability_completion, availability_date, availability_group - Spec
 * 0015, deliberate ponytail restriction instead of a full rebuild of the
 * availability API) and is checked with core_availability\tree BEFORE
 * writing - an invalid condition therefore fails right here, never only on
 * the next page view.
 *
 * "availability"/"availabilityconditionsjson" stays on the blocklist of
 * update_module_settings (shared_block::BLOCKLIST does not list it as a
 * field at all) - this endpoint is the only write path.
 *
 * Writing goes through get_moduleinfo_data()/update_moduleinfo() (ADR
 * 0016), never directly into course_modules.availability - the change
 * history (#385-387) observes course_module_updated automatically.
 *
 * "profile" conditions are not offered by this endpoint (they remain
 * settable only via the native form route or
 * update_module_settings/direct editing, not via Coursepilot) - the read
 * path (get_module_settings) keeps masking them unchanged (ADR 0011).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class set_restriction extends external_api {
    /**
     * Teacher-facing status words for "completion" -> Moodle's
     * COMPLETION_xx values (lib/completionlib.php: INCOMPLETE=0, COMPLETE=1,
     * COMPLETE_PASS=2, COMPLETE_FAIL=3). Referenced as literals instead of
     * constants so that this class constant does not depend on the load
     * order of completionlib.php when the file is loaded.
     *
     * @var array<string, int>
     */
    private const COMPLETION_STATUS = [
        'complete' => 1,
        'incomplete' => 0,
        'pass' => 2,
        'fail' => 3,
    ];

    /**
     * Teacher-facing direction words for "date" -> Moodle's
     * DIRECTION_xx values (availability_date\condition::DIRECTION_FROM/
     * DIRECTION_UNTIL). Referenced as literals, for the same reason as
     * {@see self::COMPLETION_STATUS}.
     *
     * @var array<string, string>
     */
    private const DATE_DIRECTION = [
        'from' => '>=',
        'until' => '<',
    ];

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'conditions_json' => new external_value(
                PARAM_RAW,
                'JSON array of conditions (empty array removes all conditions). Each entry is an object '
                    . 'with "type": "completion" (fields "activity_cmid", "status": complete|incomplete|'
                    . 'pass|fail), "date" (fields "direction": from|until, "timestamp": Unix time) or '
                    . '"group" (field "group_id", 0 or omitted = any group). All entries must be '
                    . 'satisfied at the same time (AND).'
            ),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Runs the set restriction tool.
     *
     * @param int $cmid
     * @param string $conditionsjson
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(int $cmid, string $conditionsjson, array $confirmlearnerlocks = []): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'conditions_json' => $conditionsjson,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check in the course context (Spec 0015 §3.3/§9.2),
        // identical to update_module_settings/set_completion - no dedicated
        // Coursepilot write capability.
        require_capability('moodle/course:manageactivities', $context);

        if (empty($CFG->enableavailability)) {
            // Without conditional availability enabled site-wide, Moodle
            // would discard the field anyway (course/modlib.php) - clear
            // message instead of a silent no-op.
            throw new moodle_exception('restrictionsnotenabled', 'local_coursepilot');
        }

        $modname = (string) $cm->modname;
        $catalogclass = registry::require_catalogued($modname);

        $conditionsraw = json_decode($params['conditions_json'], true);
        if (!is_array($conditionsraw) || json_last_error() !== JSON_ERROR_NONE || self::is_json_object($conditionsraw)) {
            throw new moodle_exception('invalidrestrictionjson', 'local_coursepilot');
        }

        $conditions = [];
        foreach ($conditionsraw as $condition) {
            if (!is_array($condition) || self::is_json_object($condition) === false) {
                throw new coding_exception('conditions_json must be an array of JSON objects.');
            }
            $conditions[] = self::build_condition((int) $cm->course, $condition);
        }

        $availabilityjson = self::build_availability_json($conditions);
        learner_locks::assert_confirmed(
            $modname,
            self::grade_condition_locks($conditions, (string) ($cm->availability ?? '')),
            $params[learner_locks::PARAMETER]
        );

        $course = get_course((int) $cm->course);
        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        // Same preparation as update_module_settings/set_completion
        // (#388/#392): without it, e.g. page_update_instance() reads a
        // missing pseudo-field unguarded and writes content to null, even
        // though this endpoint does not patch any other field.
        $before = self::read_settings((int) $cmid);
        pseudofield_carry_forward::apply($modname, $catalogclass, $moduleinfo, $before, $cm, []);
        $moduleinfo->availabilityconditionsjson = $availabilityjson;

        \update_moduleinfo($cm, $moduleinfo, $course);

        return [
            'cmid' => (int) $cmid,
            'modname' => (string) $cm->modname,
            'message' => self::build_message(count($conditions)),
        ];
    }

    /**
     * Returns current state, same shape as get_module_settings.
     *
     * @param int $cmid
     * @return array Current state, same shape as get_module_settings.
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Some test data generators/DB drivers return numeric IDs as strings
     * instead of integers - teacher-friendly arguments should still count as
     * valid as long as they unambiguously mean a positive integer (no
     * "3.5", no "3abc").
     *
     * @param mixed $value
     * @return int|null Positive integer, or null if invalid.
     */
    private static function positive_int($value): ?int {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    /**
     * When decoding, PHP makes no distinction between a JSON array and a
     * JSON object - both become associative arrays. "conditions_json" must
     * however be a list (JSON array), not an object.
     *
     * @param array $value
     * @return bool
     */
    private static function is_json_object(array $value): bool {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }

    /**
     * Builds a native condition object (core_availability\condition::save()
     * shape) from a teacher-friendly condition - each time via the official
     * get_json() factory of the three supported condition types.
     *
     * @param int $courseid
     * @param array $condition
     * @return stdClass
     * @throws moodle_exception restrictionunknowntype|restrictionactivitynotfound|restrictioninvalidstatus|
     *         restrictioninvaliddate|restrictiongroupnotfound
     */
    private static function build_condition(int $courseid, array $condition): stdClass {
        $type = $condition['type'] ?? null;
        switch ($type) {
            case 'completion':
                return self::build_completion_condition($courseid, $condition);
            case 'date':
                return self::build_date_condition($condition);
            case 'group':
                return self::build_group_condition($courseid, $condition);
            default:
                throw new moodle_exception(
                    'restrictionunknowntype',
                    'local_coursepilot',
                    '',
                    ['field' => 'type', 'value' => json_encode($type)]
                );
        }
    }

    /**
     * Builds completion condition.
     *
     * @param int $courseid
     * @param array $condition
     * @return stdClass
     */
    private static function build_completion_condition(int $courseid, array $condition): stdClass {
        $activitycmid = self::positive_int($condition['activity_cmid'] ?? null);
        if (
            $activitycmid === null
                || !get_coursemodule_from_id('', $activitycmid, $courseid, false, IGNORE_MISSING)
        ) {
            throw new moodle_exception(
                'restrictionactivitynotfound',
                'local_coursepilot',
                '',
                ['field' => 'activity_cmid', 'value' => json_encode($activitycmid)]
            );
        }

        $status = $condition['status'] ?? null;
        if (!is_string($status) || !array_key_exists($status, self::COMPLETION_STATUS)) {
            throw new moodle_exception(
                'restrictioninvalidstatus',
                'local_coursepilot',
                '',
                ['field' => 'status', 'value' => json_encode($status)]
            );
        }

        return \availability_completion\condition::get_json($activitycmid, self::COMPLETION_STATUS[$status]);
    }

    /**
     * Builds date condition.
     *
     * @param array $condition
     * @return stdClass
     */
    private static function build_date_condition(array $condition): stdClass {
        $direction = $condition['direction'] ?? null;
        $timestamp = $condition['timestamp'] ?? null;
        if (!is_string($direction) || !array_key_exists($direction, self::DATE_DIRECTION) || !is_int($timestamp)) {
            throw new moodle_exception(
                'restrictioninvaliddate',
                'local_coursepilot',
                '',
                ['field' => 'direction/timestamp', 'value' => json_encode($condition)]
            );
        }

        return \availability_date\condition::get_json(self::DATE_DIRECTION[$direction], $timestamp);
    }

    /**
     * Builds group condition.
     *
     * @param int $courseid
     * @param array $condition
     * @return stdClass
     */
    private static function build_group_condition(int $courseid, array $condition): stdClass {
        global $DB;

        $rawgroupid = $condition['group_id'] ?? 0;
        $isanygroup = $rawgroupid === 0 || $rawgroupid === null || $rawgroupid === '0';
        $groupid = $isanygroup ? 0 : self::positive_int($rawgroupid);
        if ($groupid === null) {
            throw new moodle_exception(
                'restrictiongroupnotfound',
                'local_coursepilot',
                '',
                ['field' => 'group_id', 'value' => json_encode($rawgroupid)]
            );
        }
        if ($groupid > 0 && !$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
            throw new moodle_exception(
                'restrictiongroupnotfound',
                'local_coursepilot',
                '',
                ['field' => 'group_id', 'value' => json_encode($groupid)]
            );
        }

        return \availability_group\condition::get_json($groupid);
    }

    /**
     * Lock (#583): a completion condition on an activity whose grade comes
     * from the teacher makes learners wait - as soon as the condition
     * depends on the grade ("pass"/"fail") or the activity's own completion
     * runs via the grade. An already existing, unchanged condition needs no
     * renewed confirmation.
     *
     * @param stdClass[] $conditions Native conditions from {@see self::build_condition()}.
     * @param string $currentavailability Current availability JSON of the activity.
     * @return array<int, array{id: string, detail: string}>
     */
    private static function grade_condition_locks(array $conditions, string $currentavailability): array {
        $existing = \local_coursepilot\cm_references::completion_pairs(json_decode($currentavailability, true) ?: []);
        $gradedstatus = [self::COMPLETION_STATUS['pass'], self::COMPLETION_STATUS['fail']];
        $locks = [];
        foreach ($conditions as $condition) {
            if ($condition->type !== 'completion' || in_array($condition->cm . ':' . $condition->e, $existing, true)) {
                continue;
            }
            $target = get_coursemodule_from_id('', $condition->cm, 0, false, MUST_EXIST);
            $catalogclass = registry::for((string) $target->modname);
            if ($catalogclass === null || $catalogclass::grade_origin((int) $target->instance) !== learner_locks::GRADE_TEACHER) {
                continue;
            }
            $bygrade = $target->completiongradeitemnumber !== null || !empty($target->completionpassgrade);
            if (!in_array($condition->e, $gradedstatus, true) && !$bygrade) {
                continue;
            }
            $locks[] = [
                'id' => 'teacher_grade:' . $condition->cm,
                'detail' => '"teacher_grade:' . $condition->cm . '": the condition on ' . $target->modname
                    . ' (cmid ' . $condition->cm . ') is only met once the teacher has graded the learner.',
            ];
        }
        return $locks;
    }

    /**
     * Wraps the individual conditions into the native tree
     * (core_availability\tree root format, AND combination, all visible to
     * learners) and checks the result with core_availability\tree BEFORE
     * writing - a structure that core_availability\tree rejects would also
     * be rejected by availability/classes/info.php on the next page view
     * (uncaught there: that makes the course page unreachable). An empty
     * conditions array yields an empty string (restriction removed, see
     * course/modlib.php).
     *
     * @param stdClass[] $conditions
     * @return string
     * @throws moodle_exception invalidrestrictionjson
     */
    private static function build_availability_json(array $conditions): string {
        if (!$conditions) {
            return '';
        }

        $structure = (object) [
            'op' => tree::OP_AND,
            'c' => $conditions,
            'showc' => array_fill(0, count($conditions), true),
        ];

        try {
            new tree($structure);
        } catch (coding_exception $e) {
            // Should never be reached thanks to the validation above - last
            // safeguard so that a structure is never written which
            // availability/classes/info.php would reject later.
            throw new moodle_exception('invalidrestrictionjson', 'local_coursepilot', '', ['field' => 'conditions_json']);
        }

        return json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Builds message.
     *
     * @param int $count
     * @return string
     */
    private static function build_message(int $count): string {
        if ($count === 0) {
            return 'All restrictions were removed.';
        }
        return $count === 1
            ? 'Restriction set: 1 condition must be met.'
            : 'Restrictions set: ' . $count . ' conditions must all be met.';
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
        ]);
    }
}
