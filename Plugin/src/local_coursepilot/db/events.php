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

/**
 * History (#385/#386/#387, Spec 0015 §10): course_module_updated captures
 * a full snapshot regardless of forms, course pages, bulk/manual edits or
 * Coursepilot writes through the same path. course_module_created records
 * version 1 immediately (#386); older activities missing that event get
 * a retroactive discovered snapshot on update. Module/course deletion
 * also removes history (#387 cascade).
 *
 * Sixteen quiz structure events (#396, Spec 0015 §10) change arrangement
 * (quiz_slots/question_references, quiz_sections, quiz_feedback): ordering,
 * pages, sections, question-reference versions and grade-item assignment.
 * These are the events emitted by mod/quiz/classes/structure.php (search
 * ::create([). Deliberately exclude slot_created (new question),
 * quiz_repaginated and quiz_grade_items_reordered (not emitted there,
 * or anywhere in Moodle 5.0.8). New questions change content, not
 * arrangement; see catalog\quiz documentation and the version_history
 * gap notice about quiz content beyond the recorded arrangement.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$quizstructureevents = [
    '\mod_quiz\event\slot_moved',
    '\mod_quiz\event\slot_deleted',
    '\mod_quiz\event\slot_mark_updated',
    '\mod_quiz\event\slot_version_updated',
    '\mod_quiz\event\slot_grade_item_updated',
    '\mod_quiz\event\slot_requireprevious_updated',
    '\mod_quiz\event\slot_displaynumber_updated',
    '\mod_quiz\event\page_break_created',
    '\mod_quiz\event\page_break_deleted',
    '\mod_quiz\event\section_break_created',
    '\mod_quiz\event\section_break_deleted',
    '\mod_quiz\event\section_title_updated',
    '\mod_quiz\event\section_shuffle_updated',
    '\mod_quiz\event\quiz_grade_item_created',
    '\mod_quiz\event\quiz_grade_item_updated',
    '\mod_quiz\event\quiz_grade_item_deleted',
];

$observers = [
    [
        'eventname' => '\core\event\course_module_created',
        'callback' => '\local_coursepilot\observer::course_module_created',
    ],
    [
        'eventname' => '\core\event\course_module_updated',
        'callback' => '\local_coursepilot\observer::course_module_updated',
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\local_coursepilot\observer::course_module_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_coursepilot\observer::course_deleted',
    ],
];

foreach ($quizstructureevents as $quizstructureevent) {
    $observers[] = [
        'eventname' => $quizstructureevent,
        'callback' => '\local_coursepilot\observer::quiz_structure_changed',
    ];
}
