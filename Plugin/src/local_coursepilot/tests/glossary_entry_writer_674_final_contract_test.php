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

namespace local_coursepilot;

use core_external\external_api;
use local_coursepilot\external\add_glossary_entries;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Approval changes of the glossary entry writer: event data, completion and link cache (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(glossary_entry_writer::class)]
final class glossary_entry_writer_674_final_contract_test extends \advanced_testcase {
    /**
     * Writes one entry through the writer with parameters normalised as the tool does.
     *
     * @param \stdClass $glossary Glossary module as returned by the generator.
     * @param mixed[] $entry Entry fields.
     * @return mixed[] Writer result.
     */
    private function write_entry(\stdClass $glossary, array $entry): array {
        global $DB;
        $cm = get_coursemodule_from_id('glossary', $glossary->cmid, 0, false, MUST_EXIST);
        $params = external_api::validate_parameters(add_glossary_entries::execute_parameters(), [
            'cmid' => $cm->id, 'entries' => [$entry],
        ]);
        return glossary_entry_writer::add(
            $params['entries'][0],
            get_course($cm->course),
            $cm,
            $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST),
            \context_module::instance($cm->id)
        );
    }

    /**
     * Runs the approval step on a stored, still unapproved entry.
     *
     * The public writer cannot reach this state: whoever may send an approval flag holds the approve capability, and
     * Moodle then approves a new entry on its own. The step is exercised directly for the approval transition.
     *
     * @param mixed[] $glossaryrecord Extra glossary settings.
     * @return mixed[] Glossary, entry, course module and the event sink that recorded the step.
     */
    private function approve_held_entry(array $glossaryrecord = []): array {
        global $DB;
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id] + $glossaryrecord);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        /** @var \mod_glossary_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_glossary');
        $entryid = $generator->create_content($glossary, [
            'concept' => 'Held', 'userid' => $student->id, 'approved' => 0, 'usedynalink' => 1,
        ])->id;
        $cm = get_coursemodule_from_id('glossary', $glossary->cmid, 0, false, MUST_EXIST);
        $entry = $DB->get_record('glossary_entries', ['id' => $entryid], '*', MUST_EXIST);
        $sink = $this->redirectEvents();
        $step = new \ReflectionMethod(glossary_entry_writer::class, 'approval');
        $step->invoke(
            null,
            ['approved' => true],
            $entry,
            $course,
            $cm,
            $DB->get_record('glossary', ['id' => $glossary->id], '*', MUST_EXIST),
            \context_module::instance($cm->id)
        );
        return [$glossary, $entry, $cm, $sink];
    }

    /**
     * Restores the installed event observers: replacing them is not undone by the test reset.
     */
    protected function tearDown(): void {
        \core\event\manager::phpunit_reset();
        parent::tearDown();
    }

    /**
     * Approving a held-back entry raises the approval event for the module and the entry.
     */
    public function test_approving_an_entry_raises_the_event_for_its_module_and_entry(): void {
        $this->resetAfterTest();

        [$glossary, $entry, , $sink] = $this->approve_held_entry();

        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \mod_glossary\event\entry_approved
        ));
        $this->assertCount(1, $events);
        $this->assertSame((int) \context_module::instance($glossary->cmid)->id, (int) $events[0]->contextid);
        $this->assertSame((int) $entry->id, (int) $events[0]->objectid);
    }

    /**
     * Manual completion tracking is left alone even when the glossary counts entries.
     */
    public function test_approving_an_entry_does_not_complete_a_manually_tracked_activity(): void {
        $this->resetAfterTest();

        [, $entry, $cm] = $this->approve_held_entry([
            'completion' => COMPLETION_TRACKING_MANUAL, 'completionentries' => 1,
        ]);

        $completion = new \completion_info(get_course($cm->course));
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $entry->userid)->completionstate);
    }

    /**
     * Approving a linking entry resets the cached concepts of the course.
     */
    public function test_approving_an_entry_resets_the_concept_cache_of_a_linking_glossary(): void {
        $this->resetAfterTest();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_glossary\event\entry_approved',
            'callback' => [self::class, 'plant_concept_cache'],
            'internal' => true,
            'priority' => 0,
            'includefile' => null,
        ]]);

        [$glossary] = $this->approve_held_entry(['usedynalink' => 1]);

        $this->assertFalse(\cache::make('mod_glossary', 'concepts')->get((int) $glossary->course));
    }

    /**
     * Observer that fills the concept cache after the entry was stored but before the approval change completes.
     *
     * @param \core\event\base $event
     */
    public static function plant_concept_cache(\core\event\base $event): void {
        \cache::make('mod_glossary', 'concepts')->set((int) $event->courseid, ['planted']);
    }

    /**
     * Withdrawing the approval of a linking entry drops the cached concepts of the course.
     */
    public function test_withdrawing_an_approval_resets_the_concept_cache_of_a_linking_glossary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->getDataGenerator()->create_module('glossary', [
            'course' => $this->getDataGenerator()->create_course()->id, 'usedynalink' => 1,
        ]);
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_glossary\event\entry_disapproved',
            'callback' => [self::class, 'plant_concept_cache'],
            'internal' => true,
            'priority' => 0,
            'includefile' => null,
        ]]);

        $this->write_entry($glossary, [
            'concept' => 'Linked', 'definition' => 'Text', 'approved' => false, 'usedynalink' => true,
        ]);

        $this->assertFalse(\cache::make('mod_glossary', 'concepts')->get((int) $glossary->course));
    }
}
