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

namespace local_coursepilot\catalog;

/**
 * Field catalog for mod_forum (Spec 0015 §4.1, ticket #381).
 *
 * Pitfalls from the existing code base:
 * - "type" references forum_get_forum_types() as its value range - "news"
 *   and "social" do exist as a type (forum_get_forum_types_all()), but are
 *   only set on automatically created course/site forums and cannot be
 *   chosen through the form (mod/forum/mod_form.php:
 *   definition_after_data() only shows them if already set).
 * - "assessed" references rating_manager::get_aggregate_types() (class in
 *   rating/lib.php) instead of copying the values.
 * - "forcesubscribe" references forum_get_subscriptionmode_options().
 *   forcesubscribe=2 (FORUM_INITIALSUBSCRIBE) is a side-effect note:
 *   on creation or when switching to 2, Moodle immediately subscribes ALL
 *   potential course participants (mod/forum/lib.php: forum_instance_created(),
 *   forum_update_instance()) - from then on they receive emails about new posts.
 * - "ratingtime" is a pseudo-field (checkbox, no DB column): only when it is
 *   set does forum_update_instance() take over "assesstimestart"/
 *   "assesstimefinish" - otherwise both are reset to 0. That is why
 *   "assesstimestart"/"assesstimefinish" are on the blocklist although
 *   they are real columns.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class forum implements module_catalog {
    /**
     * Provides modname.
     *
     * @return string
     */
    public static function modname(): string {
        return 'forum';
    }

    /**
     * Provides fields.
     *
     * @return array
     */
    public static function fields(): array {
        global $CFG;

        $fields = [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the forum.',
                true,
                null,
                null,
                null,
                'mod/forum/mod_form.php:42-46 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description text (intro) of the forum.',
                true,
                null,
                null,
                null,
                'mod/forum/db/install.xml (forum.intro, NOTNULL without DB default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/forum/db/install.xml (forum.introformat)'
            ),
            new field(
                'type',
                'PARAM_ALPHA',
                'Forum type. "news" and "social" cannot be chosen through this catalog - they only exist '
                    . 'on automatically created course/site forums.',
                false,
                'general',
                null,
                'forum_get_forum_types()',
                'mod/forum/lib.php:5330-5336 (forum_get_forum_types()); mod/forum/mod_form.php:53-57'
            ),
            new field(
                'duedate',
                'PARAM_INT',
                'Unix timestamp: due date, informational only (not a cut-off for posts). Creates '
                    . 'a calendar entry (see side effects).',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:61-63 (date_time_selector, optional); column '
                    . 'mod/forum/db/install.xml (forum.duedate)'
            ),
            new field(
                'cutoffdate',
                'PARAM_INT',
                'Unix timestamp: from here on Moodle accepts no more forum posts. 0 = no cut-off.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:65-67 (date_time_selector, optional); column '
                    . 'mod/forum/db/install.xml (forum.cutoffdate)'
            ),
            new field(
                'assessed',
                'PARAM_INT',
                'Aggregation type for ratings (e.g. average, sum, no rating).',
                false,
                0,
                null,
                'rating_manager::get_aggregate_types()',
                'rating/lib.php (class rating_manager, method get_aggregate_types()); '
                    . 'course/moodleform_mod.php:686,719 (add_rating_settings()); column '
                    . 'mod/forum/db/install.xml (forum.assessed)'
            ),
            new field(
                'scale',
                'PARAM_INT',
                'Rating scale: positive = maximum points, negative = ID of a custom scale. Only '
                    . 'effective when assessed != 0.',
                false,
                0,
                null,
                null,
                'course/moodleform_mod.php:743-746 (add_rating_settings(), modgrade element; scale value = grademax/scaleid); column '
                    . 'mod/forum/db/install.xml (forum.scale)'
            ),
            new field(
                'grade_forum',
                'PARAM_INT',
                'Overall grade of the forum (independent of the post rating via "assessed"): positive = '
                    . 'maximum points, negative = ID of a custom scale, 0 = no grading.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:211,225-274 (add_forum_grade_settings(), modgrade element); column '
                    . 'mod/forum/db/install.xml (forum.grade_forum)'
            ),
            new field(
                'grade_forum_notify',
                'PARAM_BOOL',
                'Notify learners about new grades by default.',
                false,
                0,
                [0, 1],
                null,
                'mod/forum/mod_form.php:305-307 (selectyesno); column '
                    . 'mod/forum/db/install.xml (forum.grade_forum_notify)'
            ),
            new field(
                'maxbytes',
                'PARAM_INT',
                'Maximum file size per attachment in bytes. The selectable values are a subset depending on '
                    . 'course and server limits, not a fixed list.',
                false,
                0,
                null,
                'get_max_upload_sizes()',
                'lib/moodlelib.php:6453 (get_max_upload_sizes()); mod/forum/mod_form.php:72-76; column '
                    . 'mod/forum/db/install.xml (forum.maxbytes)'
            ),
            new field(
                'maxattachments',
                'PARAM_INT',
                'Maximum number of attachments per post.',
                false,
                1,
                null,
                null,
                'mod/forum/mod_form.php:94-96; column mod/forum/db/install.xml (forum.maxattachments)'
            ),
            new field(
                'displaywordcount',
                'PARAM_BOOL',
                'Show word count per post.',
                false,
                0,
                [0, 1],
                null,
                'mod/forum/mod_form.php:98-100 (selectyesno); column '
                    . 'mod/forum/db/install.xml (forum.displaywordcount)'
            ),
            new field(
                'forcesubscribe',
                'PARAM_INT',
                'Subscription mode. Value 2 (auto-subscription) immediately subscribes all potential course '
                    . 'participants on creation or switching - see side effects.',
                false,
                0,
                null,
                'forum_get_subscriptionmode_options()',
                'mod/forum/lib.php:39-42 (FORUM_CHOOSESUBSCRIBE/FORCESUBSCRIBE/INITIALSUBSCRIBE/DISALLOWSUBSCRIBE), '
                    . ':4870-4876 (forum_get_subscriptionmode_options()); mod/forum/mod_form.php:105-106'
            ),
            new field(
                'trackingtype',
                'PARAM_INT',
                'Read tracking: off, optional (learners decide) or forced.',
                false,
                1,
                [0, 1, 2],
                null,
                'mod/forum/lib.php:47-58 (FORUM_TRACKING_OFF/OPTIONAL/FORCED); mod/forum/mod_form.php:118-127'
            ),
            new field(
                'rsstype',
                'PARAM_INT',
                'RSS feed content: off, discussions or posts. Only selectable if RSS is enabled server-wide.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:131-139'
            ),
            new field(
                'rssarticles',
                'PARAM_INT',
                'Number of entries in the RSS feed. Only effective when rsstype != 0.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:156-160 (hideIf rsstype eq 0)'
            ),
            new field(
                'warnafter',
                'PARAM_INT',
                'Show a warning from this number of posts within the block period. 0 = off. Only effective when '
                    . 'blockperiod != 0.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:201-206 (hideIf blockperiod eq 0); column '
                    . 'mod/forum/db/install.xml (forum.warnafter)'
            ),
            new field(
                'blockafter',
                'PARAM_INT',
                'Block further posts from this number of posts within the block period. 0 = off. Only effective when '
                    . 'blockperiod != 0.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:194-199 (hideIf blockperiod eq 0); column '
                    . 'mod/forum/db/install.xml (forum.blockafter)'
            ),
            new field(
                'blockperiod',
                'PARAM_INT',
                'Period in seconds over which warnafter/blockafter are counted. 0 = blocking disabled.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:181-192'
            ),
            new field(
                'completiondiscussions',
                'PARAM_INT',
                'Number of started discussions from which the activity counts as completed. 0 = no '
                    . 'condition.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:405-420; column mod/forum/db/install.xml (forum.completiondiscussions)'
            ),
            new field(
                'completionreplies',
                'PARAM_INT',
                'Number of replies from which the activity counts as completed. 0 = no condition.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:405-425; column mod/forum/db/install.xml (forum.completionreplies)'
            ),
            new field(
                'completionposts',
                'PARAM_INT',
                'Number of posts (discussions + replies combined) from which the activity counts as completed. '
                    . '0 = no condition.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:405-435; column mod/forum/db/install.xml (forum.completionposts)'
            ),
            new field(
                'lockdiscussionafter',
                'PARAM_INT',
                'Period in seconds after which a discussion without a new reply is locked automatically. '
                    . '0 = off. Not effective for type=single.',
                false,
                0,
                null,
                null,
                'mod/forum/mod_form.php:164-178 (disabledIf type eq single); column '
                    . 'mod/forum/db/install.xml (forum.lockdiscussionafter)'
            ),
        ];
        if ((int) $CFG->branch >= 502) {
            $fields[] = new field(
                'showimmediately',
                'PARAM_BOOL',
                'In a Q&A forum, show other answers immediately after posting instead of waiting for the editing period.',
                false,
                0,
                [0, 1],
                null,
                'mod/forum/mod_form.php (showimmediately)'
            );
        }
        return $fields;
    }

    /**
     * Provides state.
     *
     * @param int $instanceid The instanceid.
     * @param int $cmid The cmid.
     * @param bool $fullcontent The fullcontent.
     * @return array
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::unknown(self::modname(), $instanceid, $fullcontent);
    }

    /**
     * Writes options.
     *
     * @return array
     */
    public static function write_options(): array {
        return [
            'date_order_rules' => [['reference' => 'duedate', 'field' => 'cutoffdate', 'mode' => 'not_before']],
            'side_effect_triggers' => ['forcesubscribe' => [2 => 'All course participants have been subscribed to this forum.']],
        ];
    }

    /**
     * Provides common field names.
     *
     * @return array
     */
    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    /**
     * Provides pseudofields.
     *
     * @return array
     */
    public static function pseudofields(): array {
        return [
            new field(
                'ratingtime',
                'PARAM_BOOL',
                'Checkbox: restrict the rating period. Only when set does Moodle take over '
                    . '"assesstimestart"/"assesstimefinish" - otherwise forum_update_instance() resets both to 0. '
                    . 'Not a DB field.',
                false,
                0,
                [0, 1],
                null,
                'course/moodleform_mod.php:751-760 (add_rating_settings()); mod/forum/lib.php:178-181 '
                    . '(forum_update_instance(): `if (empty($forum->ratingtime) or empty($forum->assessed))`)'
            ),
        ];
    }

    /**
     * Provides blocklist.
     *
     * @return array
     */
    public static function blocklist(): array {
        return [
            'assesstimestart',
            'assesstimefinish',
        ];
    }

    /**
     * Provides combination rules.
     *
     * @return array
     */
    public static function combination_rules(): array {
        return [
            '"cutoffdate" must not be before "duedate" (mod/forum/mod_form.php: validation()).',
            '"type"="single" cannot be combined with "groupmode"=SEPARATEGROUPS (mod/forum/mod_form.php: '
                . 'validation()); "groupmode" is in the shared block.',
        ];
    }

    /**
     * Provides side effects.
     *
     * @return array
     */
    public static function side_effects(): array {
        return [
            '"forcesubscribe"=2 (auto-subscription) immediately subscribes all potential course participants '
                . 'on creation or when switching to this value - from then on they receive emails about every new post '
                . '(mod/forum/lib.php: forum_instance_created(), forum_update_instance()).',
            '"duedate" creates or updates a calendar entry (mod/forum/locallib.php: '
                . 'forum_update_calendar()); "cutoffdate" does not.',
        ];
    }

    /**
     * Provides bundles.
     *
     * @return array
     */
    public static function bundles(): array {
        return [];
    }

    /**
     * Writes route.
     *
     * @return ?string
     */
    public static function write_route(): ?string {
        return null;
    }

    /**
     * Provides checked constants.
     *
     * @return array
     */
    public static function checked_constants(): array {
        return ['FORUM_INITIALSUBSCRIBE'];
    }

    /**
     * Provides learner locks.
     *
     * @return array
     */
    public static function learner_locks(): array {
        return [
            'cutoffdate' => ['op' => 'nonzero',
                'reason' => 'After the cut-off date learners can no longer post unless the teacher moves the date.'],
            'lockdiscussionafter' => ['op' => 'nonzero',
                'reason' => 'Inactive discussions get locked; only the teacher can unlock them.'],
            'blockafter' => ['op' => 'nonzero',
                'reason' => 'Learners are blocked from posting once they reach the post threshold in the period.'],
        ];
    }

    /**
     * The teacher grades - unless an instance has neither post rating nor
     * overall grade.
     *
     * @param int $instanceid The instanceid.
     */
    public static function grade_origin(int $instanceid = 0): string {
        global $DB;

        if ($instanceid > 0) {
            $forum = $DB->get_record('forum', ['id' => $instanceid], 'assessed, grade_forum', MUST_EXIST);
            if ((int) $forum->assessed === 0 && (int) $forum->grade_forum === 0) {
                return learner_locks::GRADE_NONE;
            }
        }
        return learner_locks::GRADE_TEACHER;
    }

    /**
     * Provides reviewed up to major.
     *
     * @return int
     */
    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
