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

namespace local_coursepilot\quiz;

/**
 * Quiz arrangement state (#396, Spec 0015 §10, ADR 0016): slots and question
 * references, sections and feedback. These are outside the quiz-table field
 * catalog (Ticket #383; catalog\quiz class documentation).
 *
 * Restore only through mod_quiz\structure APIs: move_slot, update_slot_version,
 * update_question_dependency, update_slot_maxmark, update_slot_display_number,
 * set_section_heading and set_section_shuffle. quiz_feedback has no structure
 * API; reproduce Moodle's delete/insert pattern from quiz_after_add_or_update().
 *
 * ponytail: support rearranging existing slots. Added/removed questions are
 * content changes outside recorded arrangement history (version_history gap
 * notice). Skip mismatched slots rather than recreating content; extend only
 * when Spec 0017 requires interaction with content changes.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class arrangement {
    /**
     * Capture quiz slots with question references, sections and feedback.
     * version_writer stores this shape unchanged as arrangement_json and
     * restore() receives the same shape.
     *
     * @param int $quizid
     * @return array{slots: array, sections: array, feedback: array}
     */
    public static function capture(int $quizid): array {
        global $DB;

        $slots = $DB->get_records_sql(
            'SELECT s.id, s.page, s.displaynumber, s.requireprevious, s.maxmark,
                    qr.questionbankentryid, qr.version
               FROM {quiz_slots} s
          LEFT JOIN {question_references} qr
                 ON qr.component = ? AND qr.questionarea = ? AND qr.itemid = s.id
              WHERE s.quizid = ?
           ORDER BY s.slot',
            ['mod_quiz', 'slot', $quizid]
        );
        $sections = $DB->get_records(
            'quiz_sections',
            ['quizid' => $quizid],
            'firstslot',
            'id, firstslot, heading, shufflequestions'
        );
        $feedback = $DB->get_records(
            'quiz_feedback',
            ['quizid' => $quizid],
            'mingrade',
            'id, feedbacktext, feedbacktextformat, mingrade, maxgrade'
        );

        return [
            'slots' => array_values(array_map(static fn (\stdClass $slot): array => [
                'id' => (int) $slot->id,
                'page' => (int) $slot->page,
                'displaynumber' => (string) ($slot->displaynumber ?? ''),
                'requireprevious' => (int) $slot->requireprevious,
                'maxmark' => (float) $slot->maxmark,
                'questionbankentryid' => $slot->questionbankentryid === null ? null : (int) $slot->questionbankentryid,
                // NULL means always latest; preserve it unpinned (Spec 0015, question-reference guardrail).
                'version' => $slot->version === null ? null : (int) $slot->version,
            ], $slots)),
            'sections' => array_values(array_map(static fn (\stdClass $section): array => [
                'firstslot' => (int) $section->firstslot,
                'heading' => $section->heading,
                'shufflequestions' => (int) $section->shufflequestions,
            ], $sections)),
            'feedback' => array_values(array_map(static fn (\stdClass $row): array => [
                'feedbacktext' => $row->feedbacktext,
                'feedbacktextformat' => (int) $row->feedbacktextformat,
                'mingrade' => (float) $row->mingrade,
                'maxgrade' => (float) $row->maxgrade,
            ], $feedback)),
        ];
    }

    /**
     * Provides differs.
     *
     * @param mixed[] $current
     * @param mixed[] $target
     * @return bool
     */
    public static function differs(array $current, array $target): bool {
        return json_encode(
            $current,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) !== json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Restore a saved arrangement. Check quiz_has_attempts() before any write
     * (Spec 0015), returning a clear error rather than catching a later
     * coding_exception from structure::check_can_be_edited().
     *
     * @param int $quizid
     * @param mixed[] $target Arrangement state returned by capture().
     * @throws \moodle_exception arrangementrestoreblocked if the quiz already has attempts.
     */
    public static function restore(int $quizid, array $target): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        if (quiz_has_attempts($quizid)) {
            throw new \moodle_exception('arrangementrestoreblocked', 'local_coursepilot', '', ['quizid' => $quizid]);
        }

        $quizobj = \mod_quiz\quiz_settings::create($quizid);
        $currentids = array_flip($DB->get_fieldset_select('quiz_slots', 'id', 'quizid = ?', [$quizid]));
        $targetslots = array_values(array_filter(
            $target['slots'],
            static fn (array $slot): bool => isset($currentids[$slot['id']])
        ));

        self::restore_slot_order($quizobj, $targetslots);
        self::restore_page_breaks($quizobj, $targetslots);
        self::restore_slot_fields($quizobj, $targetslots);
        self::restore_sections($quizobj, $target['sections']);
        self::restore_feedback($quizid, $target['feedback']);
    }

    /**
     * Move each slot once into target order after the previously placed slot.
     * move_slot() invalidates the structure, so recreate it after every call.
     *
     * Use the predecessor's current page rather than the target page: move_slot()
     * validates against neighbors that may not yet be final and rejects jumps
     * to a higher page for the last slot. Then restore actual page boundaries
     * through restore_page_breaks(), using Moodle's update_page_break() API.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param mixed[] $targetslots Only slots that currently exist.
     * @return void
     */
    private static function restore_slot_order(\mod_quiz\quiz_settings $quizobj, array $targetslots): void {
        $previousid = 0;
        foreach ($targetslots as $targetslot) {
            $structure = \mod_quiz\structure::create_for_quiz($quizobj);
            $page = $previousid === 0 ? 1 : $structure->get_slot_by_id($previousid)->page;
            $structure->move_slot($targetslot['id'], $previousid, $page);
            $previousid = $targetslot['id'];
        }
    }

    /**
     * Restore boundaries between adjacent target slots with update_page_break():
     * LINK removes the break before the slot, UNLINK inserts it. Use slot
     * boundaries rather than absolute page numbers.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param mixed[] $targetslots In target order, already reordered by restore_slot_order().
     * @return void
     */
    private static function restore_page_breaks(\mod_quiz\quiz_settings $quizobj, array $targetslots): void {
        for ($i = 1; $i < count($targetslots); $i++) {
            $previousslot = $targetslots[$i - 1];
            $currentslot = $targetslots[$i];
            $type = $currentslot['page'] > $previousslot['page'] ? \mod_quiz\repaginate::UNLINK : \mod_quiz\repaginate::LINK;
            \mod_quiz\structure::create_for_quiz($quizobj)->update_page_break($currentslot['id'], $type);
        }
    }

    /**
     * Restore requireprevious, maximum mark, display number and question version
     * exactly as captured. Preserve version=null (always latest) rather than
     * pinning the version current at capture time.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param mixed[] $targetslots
     * @return void
     */
    private static function restore_slot_fields(\mod_quiz\quiz_settings $quizobj, array $targetslots): void {
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        foreach ($targetslots as $targetslot) {
            $slot = $structure->get_slot_by_id($targetslot['id']);

            if ((int) $slot->requireprevious !== $targetslot['requireprevious']) {
                $structure->update_question_dependency($slot->id, (bool) $targetslot['requireprevious']);
            }
            if (abs((float) $slot->maxmark - $targetslot['maxmark']) > 1e-7) {
                $structure->update_slot_maxmark($slot, $targetslot['maxmark']);
            }
            if ((string) ($slot->displaynumber ?? '') !== $targetslot['displaynumber']) {
                $structure->update_slot_display_number($slot->id, $targetslot['displaynumber']);
            }
            // Always call: update_slot_version() is a no-op for unchanged values
            // and preserves version=null without a separate interpretation.
            $structure->update_slot_version($slot->id, $targetslot['version']);
        }
    }

    /**
     * Restore section heading/shuffle settings by position. After slot moves,
     * firstslot values are already correct because move_slot() updates section
     * boundaries through quiz_update_section_firstslots(). Skip differing
     * section counts, which represent content changes like missing slots.
     *
     * @param \mod_quiz\quiz_settings $quizobj
     * @param mixed[] $targetsections
     * @return void
     */
    private static function restore_sections(\mod_quiz\quiz_settings $quizobj, array $targetsections): void {
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        $currentsections = array_values($structure->get_sections());

        if (count($currentsections) !== count($targetsections)) {
            return;
        }

        foreach ($currentsections as $index => $currentsection) {
            $targetsection = $targetsections[$index];
            if ((string) ($currentsection->heading ?? '') !== (string) ($targetsection['heading'] ?? '')) {
                $structure->set_section_heading($currentsection->id, $targetsection['heading']);
            }
            if ((int) $currentsection->shufflequestions !== $targetsection['shufflequestions']) {
                $structure->set_section_shuffle($currentsection->id, $targetsection['shufflequestions']);
            }
        }
    }

    /**
     * quiz_feedback has no structure API. Reproduce Moodle's delete/insert
     * pattern from mod/quiz/lib.php: quiz_after_add_or_update().
     *
     * @param int $quizid
     * @param mixed[] $targetfeedback
     * @return void
     */
    private static function restore_feedback(int $quizid, array $targetfeedback): void {
        global $DB;

        if (self::feedback_matches($quizid, $targetfeedback)) {
            return;
        }

        $DB->delete_records('quiz_feedback', ['quizid' => $quizid]);
        foreach ($targetfeedback as $row) {
            $DB->insert_record('quiz_feedback', (object) array_merge($row, ['quizid' => $quizid]));
        }
    }

    /**
     * Provides feedback matches.
     *
     * @param int $quizid
     * @param mixed[] $targetfeedback
     * @return bool
     */
    private static function feedback_matches(int $quizid, array $targetfeedback): bool {
        $current = self::capture($quizid)['feedback'];
        return json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === json_encode(
            $targetfeedback,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
