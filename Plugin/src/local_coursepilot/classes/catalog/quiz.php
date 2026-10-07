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

namespace local_coursepilot\catalog;

/**
 * Field catalog for mod_quiz (Spec 0015 §4.6, ticket #383): one vocabulary,
 * two write paths. {@see write_route()} returns update_quiz_settings because
 * quiz remains the justified exception to update_moduleinfo() in ADR 0016.
 * Field names do not always match columns, grade cannot be changed through
 * the form path, and question/page/section content lives in quiz_slots.
 * Shared concepts use the same names as assign (e.g. timelimit), so teachers
 * need no second reference for a quiz.
 *
 * Existing pitfalls:
 * - Six live quiz sources provide value ranges rather than copied lists:
 *   quiz_get_overdue_handling_options(), quiz_get_grading_options(),
 *   quiz_questions_per_page_options(), quiz_get_navigation_options()
 *   (mod/quiz/locallib.php or lib.php),
 *   \mod_quiz\access_manager::get_browser_security_choices(), and
 *   \question_engine::get_behaviour_options() (question/engine/lib.php).
 * - password is unavailable under its own name through the form path.
 *   The form uses quizpassword (mod/quiz/mod_form.php:289-291, passwordunmask);
 *   data_preprocessing()/data_postprocessing() mirror it to password.
 *   password is blocked; quizpassword is its pseudofield.
 * - Eight review* columns are bitmasks, not directly writable through the form.
 *   quiz_process_options() (mod/quiz/lib.php) computes them from 32 checkboxes:
 *   eight types (attempt, correctness, maxmarks, marks, specificfeedback,
 *   generalfeedback, rightanswer, overallfeedback) times four timings
 *   (during, immediately, open, closed), e.g. attemptduring. See
 *   \mod_quiz\question\display_options::DURING and peers. The masks are blocked;
 *   the 32 individual checkboxes are pseudofields.
 * - completionattemptsexhausted/completionminattempts, like
 *   assign::completionsubmit (#382), are module-specific completion fields.
 *   Without completionunlocked they risk data loss (mod/quiz/mod_form.php:531-541;
 *   data_postprocessing() silently resets completionminattempts to 0).
 *   Blocked instead of silently ignored, as in assign.
 * - allowofflineattempts is a real column provided by quizaccess_offlineattempts
 *   through access_manager::add_settings_form_fields()
 *   (mod/quiz/accessrule/offlineattempts/rule.php:100-107), not the form core.
 *   It still uses the regular form path, so it is a field rather than blocked.
 * - Three mode bundles (mini-check, progress-check, final-test) reuse combinations
 *   from local_coursepilot\external\create_quiz::mode_defaults(), restricted
 *   to writable catalog fields/pseudofields. grade and generic completion*
 *   fields are blocked and omitted; eight bitmask preferences become 32 checkboxes.
 * - Arrangement is outside this catalog. Questions, pages and sections
 *   (quiz_slots/quiz_sections) use the core structure API (\mod_quiz\structure),
 *   not update_quiz_settings (ADR 0016). This catalog describes instance settings.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class quiz implements module_catalog {

    /**
     * Eight review types (column name without the review prefix => short
     * English description) times four timings (during/immediately/open/closed)
     * give 32 pseudofield checkboxes (mod/quiz/mod_form.php:
     * self::$reviewfields, add_review_options_group()).
     *
     * @var array<string, string>
     */
    private const REVIEW_TYPES = [
        'attempt' => 'Whether the quiz attempt itself can be reviewed.',
        'correctness' => 'Whether each question shows the correct/incorrect indicator.',
        'maxmarks' => 'Whether the maximum marks for each question are visible.',
        'marks' => 'Whether the earned marks for each question are visible. Ineffective without "maxmarks" for the same '
            . 'review time.',
        'specificfeedback' => 'Whether answer-specific feedback is visible.',
        'generalfeedback' => 'Whether general question feedback is visible.',
        'rightanswer' => 'Whether the correct answer is visible.',
        'overallfeedback' => 'Whether overall quiz feedback is visible.',
    ];

    /**
     * Four timing suffixes in form order, each with its English meaning.
     *
     * @var array<string, string>
     */
    private const REVIEW_TIMINGS = [
        'during' => 'during the attempt',
        'immediately' => 'immediately after submission',
        'open' => 'later, while the quiz is still open',
        'closed' => 'after the quiz has closed',
    ];

    public static function modname(): string {
        return 'quiz';
    }

    public static function fields(): array {
        global $CFG;

        $fields = [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the quiz.',
                true,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:76-83 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description (intro) of the quiz.',
                true,
                null,
                null,
                null,
                'mod/quiz/db/install.xml (quiz.intro, NOTNULL without DB default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/quiz/db/install.xml (quiz.introformat)'
            ),
            new field(
                'timeopen',
                'PARAM_INT',
                'Unix timestamp: quiz opens. 0 = no opening time. Creates a calendar event '
                    . '(see side effects).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:92-94 (date_time_selector, optional); column '
                    . 'mod/quiz/db/install.xml (quiz.timeopen)'
            ),
            new field(
                'timeclose',
                'PARAM_INT',
                'Unix timestamp: quiz closes. 0 = no closing time. Creates a calendar event '
                    . '(see side effects).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:96-97 (date_time_selector, optional); column '
                    . 'mod/quiz/db/install.xml (quiz.timeclose)'
            ),
            new field(
                'timelimit',
                'PARAM_INT',
                'Time limit in seconds from attempt start. 0 = no time limit. Same field as for '
                    . 'an assignment (assign::timelimit).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:100-102 (duration, optional); column '
                    . 'mod/quiz/db/install.xml (quiz.timelimit)'
            ),
            new field(
                'overduehandling',
                'PARAM_ALPHA',
                'Overdue attempt handling: automatically submit, allow a grace period, or '
                    . 'abandon the attempt.',
                false,
                'autoabandon',
                ['autosubmit', 'graceperiod', 'autoabandon'],
                'quiz_get_overdue_handling_options()',
                'mod/quiz/locallib.php:939 (quiz_get_overdue_handling_options()); column '
                    . 'mod/quiz/db/install.xml (quiz.overduehandling, DEFAULT=autoabandon)'
            ),
            new field(
                'graceperiod',
                'PARAM_INT',
                'Grace period in seconds after the time limit, during which submission is still accepted. '
                    . 'Only effective for "overduehandling"="graceperiod"; must exceed a '
                    . 'server-wide minimum duration (see combination rules).',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:113-116 (duration, optional, hideIf overduehandling neq graceperiod); '
                    . 'column mod/quiz/db/install.xml (quiz.graceperiod)'
            ),
            new field(
                'preferredbehaviour',
                'PARAM_ALPHA',
                'Question behaviour: how and when an answer is graded and feedback given (e.g. '
                    . 'immediate feedback, deferred feedback, deferred feedback with certainty-based marking).',
                true,
                null,
                null,
                '\\question_engine::get_behaviour_options()',
                'question/engine/lib.php (question_engine::get_behaviour_options()); '
                    . 'mod/quiz/mod_form.php:202-205; column mod/quiz/db/install.xml '
                    . '(quiz.preferredbehaviour, NOTNULL without DB default)'
            ),
            new field(
                'canredoquestions',
                'PARAM_BOOL',
                'Learners may redo a question already completed within the attempt. '
                    . 'Only effective for behaviours that allow a question to finish during the attempt.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:208-215 (select); column mod/quiz/db/install.xml (quiz.canredoquestions)'
            ),
            new field(
                'attempts',
                'PARAM_INT',
                'Maximum number of allowed attempts. 0 = unlimited.',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:151-156 (select, 0-QUIZ_MAX_ATTEMPT_OPTION); column '
                    . 'mod/quiz/db/install.xml (quiz.attempts)'
            ),
            new field(
                'attemptonlast',
                'PARAM_BOOL',
                'A new attempt carries forward the last attempt\'s answers (1) instead of starting empty '
                    . '(0). Only visible when more than one attempt is allowed.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:218-223 (selectyesno, hideIf attempts eq 1); column '
                    . 'mod/quiz/db/install.xml (quiz.attemptonlast)'
            ),
            new field(
                'grademethod',
                'PARAM_INT',
                'How the quiz grade is calculated across attempts: highest grade, average, first '
                    . 'or last attempt.',
                false,
                1,
                [1, 2, 3, 4],
                'quiz_get_grading_options()',
                'mod/quiz/lib.php:61-64 (QUIZ_GRADEHIGHEST/AVERAGE/ATTEMPTFIRST/ATTEMPTLAST); '
                    . 'mod/quiz/locallib.php:916 (quiz_get_grading_options()); column '
                    . 'mod/quiz/db/install.xml (quiz.grademethod, DEFAULT=1)'
            ),
            new field(
                'decimalpoints',
                'PARAM_INT',
                'Decimal places displayed for the overall grade.',
                false,
                2,
                null,
                null,
                'mod/quiz/mod_form.php:264-269 (select, 0-QUIZ_MAX_DECIMAL_OPTION); column '
                    . 'mod/quiz/db/install.xml (quiz.decimalpoints, DEFAULT=2)'
            ),
            new field(
                'questiondecimalpoints',
                'PARAM_INT',
                'Decimal places displayed for individual question grades. -1 = same as "decimalpoints".',
                false,
                -1,
                null,
                null,
                'mod/quiz/mod_form.php:272-278 (select, -1 to QUIZ_MAX_Q_DECIMAL_OPTION); column '
                    . 'mod/quiz/db/install.xml (quiz.questiondecimalpoints, DEFAULT=-1)'
            ),
            new field(
                'questionsperpage',
                'PARAM_INT',
                'Number of questions before a new page starts when editing/shuffling. 0 = all questions on '
                    . 'one page.',
                false,
                0,
                null,
                'quiz_questions_per_page_options()',
                'mod/quiz/locallib.php:1001 (quiz_questions_per_page_options()); column '
                    . 'mod/quiz/db/install.xml (quiz.questionsperpage)'
            ),
            new field(
                'navmethod',
                'PARAM_ALPHA',
                'Quiz navigation: jump freely between questions ("free") or proceed in order only '
                    . '("sequential").',
                false,
                'free',
                ['free', 'sequential'],
                'quiz_get_navigation_options()',
                'mod/quiz/lib.php:76-77,1849 (QUIZ_NAVMETHOD_FREE/SEQ, quiz_get_navigation_options()); column '
                    . 'mod/quiz/db/install.xml (quiz.navmethod, DEFAULT=free)'
            ),
            new field(
                'shuffleanswers',
                'PARAM_BOOL',
                'Shuffle answer parts within each question if supported by its type.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:192-194 (selectyesno); column mod/quiz/db/install.xml (quiz.shuffleanswers)'
            ),
            new field(
                'subnet',
                'PARAM_RAW',
                'Allowed IP addresses/ranges from which a quiz attempt may be started (format as in '
                    . 'address_in_subnet()). Empty = unrestricted.',
                true,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:294-296 (text); column mod/quiz/db/install.xml '
                    . '(quiz.subnet, NOTNULL without DB default)'
            ),
            new field(
                'browsersecurity',
                'PARAM_ALPHANUMEXT',
                'Browser restriction during the attempt, e.g. secure fullscreen mode. '
                    . '"-" = unrestricted.',
                true,
                null,
                null,
                '\\mod_quiz\\access_manager::get_browser_security_choices()',
                'mod/quiz/classes/access_manager.php:128 (get_browser_security_choices()); '
                    . 'mod/quiz/mod_form.php:315-317; column mod/quiz/db/install.xml '
                    . '(quiz.browsersecurity, NOTNULL without DB default)'
            ),
            new field(
                'delay1',
                'PARAM_INT',
                'Enforced delay in seconds between the first and second attempts.',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:299-304 (duration, optional, hideIf attempts eq 1); column '
                    . 'mod/quiz/db/install.xml (quiz.delay1)'
            ),
            new field(
                'delay2',
                'PARAM_INT',
                'Enforced delay in seconds between the second and subsequent attempts.',
                false,
                0,
                null,
                null,
                'mod/quiz/mod_form.php:306-312 (duration, optional, hideIf attempts eq 1 or eq 2); column '
                    . 'mod/quiz/db/install.xml (quiz.delay2)'
            ),
            new field(
                'showuserpicture',
                'PARAM_INT',
                'Show the user picture during the attempt and on the review page: none, small or large.',
                false,
                0,
                [0, 1, 2],
                'quiz_get_user_image_options()',
                'mod/quiz/locallib.php:69-79 (QUIZ_SHOWIMAGE_NONE/SMALL/LARGE), :951 '
                    . '(quiz_get_user_image_options()); column mod/quiz/db/install.xml (quiz.showuserpicture)'
            ),
            new field(
                'showblocks',
                'PARAM_BOOL',
                'Show blocks during the quiz attempt.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:282-283 (selectyesno); column mod/quiz/db/install.xml (quiz.showblocks)'
            ),
            new field(
                'allowofflineattempts',
                'PARAM_BOOL',
                'Allow offline quiz attempts in the Moodle app. Provided by access rule plugin '
                    . 'quizaccess_offlineattempts, not the form core itself.',
                false,
                0,
                [0, 1],
                null,
                'mod/quiz/accessrule/offlineattempts/rule.php:100-107 (add_settings_form_fields()); column '
                    . 'mod/quiz/db/install.xml (quiz.allowofflineattempts)'
            ),
            new field(
                'precreateattempts',
                'PARAM_BOOL',
                'Precreate attempts for learners. Only visible when administration has configured a '
                    . 'precreation time window AND "timeopen" is set.',
                false,
                null,
                [0, 1],
                null,
                'mod/quiz/mod_form.php:118-135 (select, conditionally visible); column '
                    . 'mod/quiz/db/install.xml (quiz.precreateattempts, NULLable)'
            ),
        ];
        if ((int) $CFG->branch >= 503) {
            $fields[] = new field('duedate', 'PARAM_INT',
                'Unix timestamp: expected completion date, informational only. 0 = no due date.',
                false, 0, null, null, 'mod/quiz/mod_form.php (duedate); mod/quiz/db/install.xml (quiz.duedate)');
        }
        return $fields;
    }

    /**
     * Quiz remains the ADR 0016 exception: grading and question arrangement
     * sit outside the generic form path and are therefore read here.
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        global $DB;

        $details = module_state::empty($fullcontent);
        $quiz = $DB->get_record('quiz', ['id' => $instanceid], 'id, name, intro, preferredbehaviour, attempts, grademethod, timelimit, grade', IGNORE_MISSING);
        if (!$quiz) {
            return $details;
        }
        $gradesettings = self::grade_settings((int) $quiz->id);
        $details['name'] = (string) $quiz->name;
        $details['content'] = module_state::content_field((string) $quiz->intro, $fullcontent);
        $details['settings'] = module_state::settings([
            'preferredbehaviour' => (string) $quiz->preferredbehaviour, 'attempts' => (string) ((int) $quiz->attempts),
            'grademethod' => (string) ((int) $quiz->grademethod), 'timelimit' => (string) ((int) $quiz->timelimit),
            'grade' => (string) ((float) $quiz->grade), 'gradepass' => (string) $gradesettings['gradepass'],
            'grademax' => (string) $gradesettings['grademax'],
        ]);
        $details['quizslots'] = self::quiz_slots((int) $quiz->id);
        return $details;
    }

    /**
     * Quiz question arrangement in catalog vocabulary: read-only.
     * Arrangement changes use the core structure API (ADR 0016).
     *
     * @param int $quizid
     * @return array
     */
    private static function quiz_slots(int $quizid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT qs.id AS slotid, qs.slot, qr.questionbankentryid, qbe.questioncategoryid FROM {quiz_slots} qs LEFT JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = :component AND qr.questionarea = :area LEFT JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid WHERE qs.quizid = :quizid ORDER BY qs.slot', ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]);
        $slots = [];
        foreach ($rows as $row) {
            $latest = empty($row->questionbankentryid) ? null : $DB->get_record_sql('SELECT qv.questionid, qv.version, q.name, q.qtype FROM {question_versions} qv JOIN {question} q ON q.id = qv.questionid WHERE qv.questionbankentryid = ? ORDER BY qv.version DESC', [$row->questionbankentryid], IGNORE_MULTIPLE);
            $slots[] = ['slot' => (int) $row->slot, 'categoryid' => empty($row->questioncategoryid) ? 0 : (int) $row->questioncategoryid, 'questionbankentryid' => empty($row->questionbankentryid) ? 0 : (int) $row->questionbankentryid, 'questionid' => $latest ? (int) $latest->questionid : 0, 'version' => $latest ? (int) $latest->version : 0, 'questionname' => $latest ? (string) $latest->name : '', 'qtype' => $latest ? (string) $latest->qtype : ''];
        }
        return $slots;
    }

    /**
     * Full quiz state in catalog vocabulary. Grading and question arrangement
     * remain the quiz exception justified in ADR 0016.
     *
     * @param \stdClass $cm
     * @param \stdClass $instance
     * @return array
     */
    public static function effective_state(\stdClass $cm, \stdClass $instance): array {
        return array_merge(
            (array) $instance,
            self::grade_settings((int) $instance->id),
            [
                'quizpassword' => (string) $instance->password,
                'visible' => (int) $cm->visible,
                'visibleoncoursepage' => (int) $cm->visibleoncoursepage,
                'groupmode' => (int) groups_get_activity_groupmode($cm),
                'groupingid' => (int) $cm->groupingid,
                'idnumber' => (string) $cm->idnumber,
            ],
            quiz_write_bridge::decompose_review_bitmasks($instance),
            quiz_write_bridge::read_feedback((int) $instance->id)
        );
    }

    /**
     * Read the primary quiz grade item without display rounding or locale formatting.
     *
     * @param int $quizid
     * @return array{gradepass: ?float, grademax: ?float}
     */
    public static function grade_settings(int $quizid): array {
        global $DB;
        $item = $DB->get_record('grade_items', [
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quizid, 'itemnumber' => 0,
        ], 'gradepass, grademax');
        return [
            'gradepass' => $item ? (float) $item->gradepass : null,
            'grademax' => $item ? (float) $item->grademax : null,
        ];
    }

    public static function write_options(): array {
        return [
            'restores_arrangement' => true,
            'date_order_rules' => [['reference' => 'timeopen', 'field' => 'timeclose', 'mode' => 'not_before']],
            // Learner-lock evaluation on current settings (#583): the form field
            // "quizpassword" maps to the "password" column.
            'settings_aliases' => ['quizpassword' => 'password'],
        ];
    }

    public static function common_field_names(): array {
        return [
            'name',
            'intro',
            'timeopen',
            'timeclose',
            'timelimit',
            'attempts',
            'grademethod',
            'preferredbehaviour',
            'navmethod',
            'shuffleanswers',
        ];
    }

    public static function pseudofields(): array {
        $fields = [
            new field(
                'gradepass',
                'PARAM_FLOAT',
                get_string('quizgradepassmeaning', 'local_coursepilot'),
                false,
                0,
                null,
                null,
                'course/modlib.php: edit_module_post_actions() (grade_items.gradepass, itemnumber 0)'
            ),
            new field(
                'quizpassword',
                'PARAM_TEXT',
                'Password required before starting/resuming a quiz attempt. Empty = no '
                    . 'password. Form name for the "password" column (see blocklist) - both refer to the same '
                    . 'field, but only "quizpassword" is writable through the form path.',
                false,
                '',
                null,
                null,
                'mod/quiz/mod_form.php:289-291 (passwordunmask "quizpassword"); data_preprocessing()/'
                    . 'data_postprocessing() mirror to the "password" column'
            ),
            new field(
                'feedbacktext',
                'array',
                'Overall feedback texts by grade band, sorted descending - ONE field, not a field sequence: '
                    . 'feedbacktext[0] is feedback for 100% down to the first boundary. Only effective when '
                    . '"grade" (blocklist) is greater than 0.',
                false,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:339-368 (repeat_elements() of the feedbacktext/feedbackboundaries group)'
            ),
            new field(
                'feedbackboundaries',
                'float[]',
                'Grade boundaries for feedback bands, linked by the same key as "feedbacktext" - '
                    . 'one fewer than feedbacktext entries. Absolute or percentage (e.g. "50%"), must be '
                    . 'sorted descending and between 0 and "grade" (see combination rules).',
                false,
                null,
                null,
                null,
                'mod/quiz/mod_form.php:344-347,570-611 (repeat_elements(); validation())'
            ),
        ];

        foreach (self::REVIEW_TYPES as $type => $typemeaning) {
            foreach (self::REVIEW_TIMINGS as $timing => $timingmeaning) {
                $fields[] = new field(
                    $type . $timing,
                    'PARAM_BOOL',
                    $typemeaning . ' Timing: ' . $timingmeaning . '. One of 32 individual checkboxes from '
                        . 'which Moodle computes the "review' . $type . '" bitmask (blocklist) - the same '
                        . 'vocabulary as the eight blocked columns, broken down by review time.',
                    false,
                    0,
                    [0, 1],
                    null,
                    'mod/quiz/mod_form.php (self::$reviewfields, add_review_options_group()); '
                        . 'mod/quiz/lib.php: quiz_process_options() (bitmask assembly)'
                );
            }
        }

        return $fields;
    }

    public static function blocklist(): array {
        return [
            'grade',
            'sumgrades',
            'password',
            'reviewattempt',
            'reviewcorrectness',
            'reviewmaxmarks',
            'reviewmarks',
            'reviewspecificfeedback',
            'reviewgeneralfeedback',
            'reviewrightanswer',
            'reviewoverallfeedback',
            'completionattemptsexhausted',
            'completionminattempts',
        ];
    }

    public static function combination_rules(): array {
        return [
            '"timeclose" must not precede "timeopen" when both are set (mod/quiz/mod_form.php: '
                . 'validation()).',
            '"graceperiod" must exceed a server-wide minimum duration (admin setting '
                . '"graceperiodmin") when "overduehandling"="graceperiod" (validation()).',
            '"feedbackboundaries[]" must be sorted descending and each value must be between 0 and "grade" '
                . '; the count must be exactly one fewer than "feedbacktext[]" (validation()).',
            get_string('quizgradepassrule', 'local_coursepilot'),
        ];
    }

    public static function side_effects(): array {
        return [
            '"timeopen"/"timeclose" each create or update a calendar event '
                . '(mod/quiz/lib.php: quiz_update_events()).',
        ];
    }

    public static function bundles(): array {
        return [
            'mini-check' => array_merge([
                'preferredbehaviour' => 'immediatefeedback',
                'attempts' => 0,
                'grademethod' => 1, // QUIZ_GRADEHIGHEST.
                'timelimit' => 0,
                'questionsperpage' => 1,
                'navmethod' => 'free',
                'shuffleanswers' => 1,
                'attemptonlast' => 0,
                'delay1' => 0,
                'delay2' => 0,
                'decimalpoints' => 2,
            ], self::review_bundle_fields(
                // attempt, correctness, maxmarks, marks, specificfeedback, generalfeedback: immer sichtbar.
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback'],
                // overallfeedback: after the attempt, not during. rightanswer stays 0 in all three modes.
                ['overallfeedback']
            )),
            'progress-check' => array_merge([
                'preferredbehaviour' => 'deferredcbm',
                'attempts' => 0,
                'grademethod' => 1, // QUIZ_GRADEHIGHEST.
                'timelimit' => 0,
                'questionsperpage' => 0,
                'navmethod' => 'free',
                'shuffleanswers' => 1,
                'attemptonlast' => 0,
                'delay1' => 300,
                'delay2' => 300,
                'decimalpoints' => 2,
            ], self::review_bundle_fields(
                ['maxmarks', 'marks'],
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'overallfeedback']
            )),
            'final-test' => array_merge([
                'preferredbehaviour' => 'deferredfeedback',
                'attempts' => 2,
                'grademethod' => 2, // QUIZ_GRADEAVERAGE.
                'timelimit' => 0,
                'questionsperpage' => 0,
                'navmethod' => 'free',
                'shuffleanswers' => 1,
                'attemptonlast' => 0,
                'delay1' => 900,
                'delay2' => 900,
                'decimalpoints' => 2,
            ], self::review_bundle_fields(
                [],
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'overallfeedback']
            )),
        ];
    }

    /**
     * Builds 32 review* pseudofield checkboxes for a mode bundle by translating
     * legacy bitmask combinations (local_coursepilot\external\create_quiz::mode_defaults())
     * to individually writable pseudofields. The blocklist contains only the
     * eight bitmask columns themselves; see class documentation.
     *
     * @param string[] $duringtypes Review types that also have "during"=1.
     * @param string[] $afterattempttypes Review types with "immediately"/"open"/"closed"=1. Unlisted
     *        types ("rightanswer" in all three modes) stay 0 at every timing.
     * @return array<string, int>
     */
    private static function review_bundle_fields(array $duringtypes, array $afterattempttypes): array {
        $result = [];
        foreach (array_keys(self::REVIEW_TYPES) as $type) {
            $result[$type . 'during'] = in_array($type, $duringtypes, true) ? 1 : 0;
            $afterattempt = in_array($type, $afterattempttypes, true) ? 1 : 0;
            $result[$type . 'immediately'] = $afterattempt;
            $result[$type . 'open'] = $afterattempt;
            $result[$type . 'closed'] = $afterattempt;
        }
        return $result;
    }

    public static function write_route(): ?string {
        return 'update_quiz_settings';
    }

    public static function checked_constants(): array {
        return [];
    }

    public static function learner_locks(): array {
        return [
            'attempts' => ['op' => 'greater', 'value' => 0,
                'reason' => 'Once all attempts are used up, another try needs a user override by the teacher.'],
            'navmethod' => ['op' => 'equals', 'value' => 'sequential',
                'reason' => 'Learners cannot return to earlier questions to correct them.'],
            'timeclose' => ['op' => 'nonzero',
                'reason' => 'After the close date no attempt is possible unless the teacher grants an override.'],
            'timelimit' => ['op' => 'nonzero',
                'reason' => 'When the time runs out the attempt is submitted; another try needs a free attempt or an override.'],
            'quizpassword' => ['op' => 'not_equals', 'value' => '',
                'reason' => 'Learners need the password from the teacher to start an attempt.'],
            'subnet' => ['op' => 'not_equals', 'value' => '',
                'reason' => 'Attempts only work from the listed network; anywhere else the teacher has to step in.'],
            'browsersecurity' => ['op' => 'not_equals', 'value' => '-',
                'reason' => 'Attempts need a special browser setup the teacher has to provide.'],
        ];
    }

    /**
     * Automatically graded unless an instance contains a manually graded
     * question (e.g. essay); then the teacher determines the grade (#583).
     */
    public static function grade_origin(int $instanceid = 0): string {
        global $CFG;

        if ($instanceid > 0) {
            require_once($CFG->dirroot . '/question/engine/bank.php');
            foreach (self::quiz_slots($instanceid) as $slot) {
                if ($slot['qtype'] !== '' && \question_bank::get_qtype($slot['qtype'], false)->is_manual_graded()) {
                    return learner_locks::GRADE_TEACHER;
                }
            }
        }
        return learner_locks::GRADE_AUTOMATIC;
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
