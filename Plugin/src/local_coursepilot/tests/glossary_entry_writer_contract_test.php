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
use local_coursepilot\external\upload_material_file;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact events, stored values, file limits and cache hygiene of the glossary entry writer (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(glossary_entry_writer::class)]
final class glossary_entry_writer_contract_test extends \advanced_testcase {
    /**
     * Writes one entry through the writer with parameters normalised as the tool does, then overridden.
     *
     * @param \stdClass $glossary Glossary module as returned by the generator.
     * @param mixed[] $entry Entry fields.
     * @param mixed[] $override Raw values applied after normalisation.
     * @return mixed[] Writer result.
     */
    private function write_entry(\stdClass $glossary, array $entry, array $override = []): array {
        global $DB;
        $cm = get_coursemodule_from_id('glossary', $glossary->cmid, 0, false, MUST_EXIST);
        $params = external_api::validate_parameters(add_glossary_entries::execute_parameters(), [
            'cmid' => $cm->id, 'entries' => [$entry],
        ]);
        return glossary_entry_writer::add(
            array_merge($params['entries'][0], $override),
            get_course($cm->course),
            $cm,
            $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST),
            \context_module::instance($cm->id)
        );
    }

    /**
     * Creates a glossary in a new course.
     *
     * @param mixed[] $record Extra glossary settings.
     * @param mixed[] $course Extra course settings.
     * @return \stdClass Glossary module.
     */
    private function glossary(array $record = [], array $course = []): \stdClass {
        return $this->getDataGenerator()->create_module('glossary', [
            'course' => $this->getDataGenerator()->create_course($course)->id,
        ] + $record);
    }

    /**
     * Filters sink events by class.
     *
     * @param \phpunit_event_sink $sink Sink.
     * @param string $class Event class.
     * @return \core\event\base[]
     */
    private function events(\phpunit_event_sink $sink, string $class): array {
        return array_values(array_filter($sink->get_events(), static fn($e) => $e instanceof $class));
    }

    public function test_an_unchanged_approval_state_raises_no_approval_event(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();
        $sink = $this->redirectEvents();

        $asbool = $this->write_entry($glossary, ['concept' => 'Bool', 'definition' => 'Text', 'approved' => true]);
        $asint = $this->write_entry($glossary, ['concept' => 'Int', 'definition' => 'Text'], ['approved' => 1]);

        $this->assertTrue($asbool['approved']);
        $this->assertTrue($asint['approved']);
        $this->assertSame([], $this->events($sink, \mod_glossary\event\entry_approved::class));
        $this->assertSame([], $this->events($sink, \mod_glossary\event\entry_disapproved::class));
    }

    public function test_a_withdrawn_approval_stores_typed_state_and_event_snapshot(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();
        $sink = $this->redirectEvents();

        $result = $this->write_entry($glossary, ['concept' => 'Withdrawn', 'definition' => 'Text', 'approved' => false]);

        $row = $DB->get_record('glossary_entries', ['id' => $result['entryid']], '*', MUST_EXIST);
        $this->assertSame('0', $row->approved);
        $events = $this->events($sink, \mod_glossary\event\entry_disapproved::class);
        $this->assertCount(1, $events);
        $DB->set_field('glossary_entries', 'concept', 'Changed afterwards', ['id' => $row->id]);
        $snapshot = $events[0]->get_record_snapshot('glossary_entries', $row->id);
        $this->assertSame(0, $snapshot->approved);
        $this->assertSame('Withdrawn', $snapshot->concept);
        $this->assertSame((int) $row->timemodified, (int) $snapshot->timemodified);
    }

    public function test_withdrawing_an_approval_stamps_the_time_of_the_withdrawal(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();
        // Let the entry age one second between creation and withdrawal (no sink: observers must run).
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\mod_glossary\event\entry_created',
            'callback' => [self::class, 'pause'],
            'internal' => true,
            'priority' => 0,
            'includefile' => null,
        ]]);

        $result = $this->write_entry($glossary, ['concept' => 'Withdrawn', 'definition' => 'Text', 'approved' => false]);

        $row = $DB->get_record('glossary_entries', ['id' => $result['entryid']], '*', MUST_EXIST);
        $this->assertGreaterThan((int) $row->timecreated, (int) $row->timemodified);
    }

    /**
     * Observer that delays the creation of an entry by one second.
     *
     * @param \core\event\base $event The event.
     */
    public static function pause(\core\event\base $event): void {
        sleep(1);
    }

    public function test_a_new_category_raises_one_complete_creation_event_and_stores_its_defaults(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();
        $sink = $this->redirectEvents();

        $result = $this->write_entry($glossary, ['concept' => 'Atom', 'definition' => 'Text', 'categories' => ['Science']]);

        $this->assertTrue($result['success']);
        $category = $DB->get_record('glossary_categories', ['glossaryid' => $glossary->id], '*', MUST_EXIST);
        $this->assertSame('0', $category->usedynalink);
        $events = $this->events($sink, \mod_glossary\event\category_created::class);
        $this->assertCount(1, $events);
        $this->assertEquals($category->id, $events[0]->objectid);
        $this->assertEquals(\context_module::instance($glossary->cmid)->id, $events[0]->contextid);
        $DB->set_field('glossary_categories', 'name', 'Changed afterwards', ['id' => $category->id]);
        $DB->set_field('glossary', 'name', 'Renamed afterwards', ['id' => $glossary->id]);
        $this->assertSame('Science', $events[0]->get_record_snapshot('glossary_categories', $category->id)->name);
        $this->assertSame($glossary->name, $events[0]->get_record_snapshot('glossary', $glossary->id)->name);
    }

    public function test_a_new_category_resets_the_concept_cache_of_a_linking_glossary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary(['usedynalink' => 1]);
        $cache = \cache::make('mod_glossary', 'concepts');
        $cache->set((int) $glossary->course, ['planted']);

        $this->write_entry($glossary, [
            'concept' => 'Atom', 'definition' => 'Text', 'categories' => ['Science'], 'usedynalink' => false,
        ]);

        $this->assertFalse($cache->get((int) $glossary->course));
    }

    public function test_a_blank_category_name_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();

        $result = $this->write_entry($glossary, ['concept' => 'Atom', 'definition' => 'Text'], ['categories' => ['  ']]);

        $this->assertFalse($result['success']);
        $this->assertSame('glossaryentrycategoryrequired', $result['errorcode']);
    }

    public function test_material_files_start_from_an_empty_draft(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();
        $context = \context_module::instance($glossary->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_glossary', 'filearea' => 'attachment',
            'itemid' => 1, 'filepath' => '/', 'filename' => 'stray.txt',
        ], 'stray');
        upload_material_file::execute('worksheet.pdf', base64_encode('payload'));

        $result = $this->write_entry($glossary, [
            'concept' => 'Atom', 'definition' => 'Text', 'attachment_files' => ['worksheet.pdf'],
        ]);

        $this->assertTrue($result['success'], json_encode($result));
        $names = array_map(
            static fn($f) => $f->get_filename(),
            get_file_storage()->get_area_files($context->id, 'mod_glossary', 'attachment', $result['entryid'], 'id', false)
        );
        $this->assertSame(['worksheet.pdf'], array_values($names));
    }

    /**
     * Stores the given number of material files and returns their names.
     *
     * @param int $count Number of files.
     * @return string[]
     */
    private function upload_many(int $count): array {
        $names = [];
        for ($i = 1; $i <= $count; $i++) {
            $names[] = "f{$i}.txt";
            upload_material_file::execute("f{$i}.txt", base64_encode('x'));
        }
        return $names;
    }

    public function test_ninety_nine_files_are_accepted_and_one_hundred_are_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary();
        $names = $this->upload_many(100);

        $accepted = $this->write_entry($glossary, [
            'concept' => 'Many', 'definition' => 'Text', 'attachment_files' => array_slice($names, 0, 99),
        ]);
        $refused = $this->write_entry($glossary, [
            'concept' => 'Too many', 'definition' => 'Text', 'attachment_files' => $names,
        ]);

        $this->assertTrue($accepted['success'], json_encode($accepted));
        $this->assertFalse($refused['success']);
        $this->assertSame('glossaryentryfilelimit', $refused['errorcode']);
    }

    public function test_a_file_exactly_as_large_as_the_course_limit_is_accepted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $glossary = $this->glossary([], ['maxbytes' => 10]);
        upload_material_file::execute('exact.txt', base64_encode('0123456789'));
        upload_material_file::execute('over.txt', base64_encode('0123456789A'));

        $exact = $this->write_entry($glossary, [
            'concept' => 'Exact', 'definition' => 'Text', 'attachment_files' => ['exact.txt'],
        ]);
        $over = $this->write_entry($glossary, [
            'concept' => 'Over', 'definition' => 'Text', 'attachment_files' => ['over.txt'],
        ]);

        $this->assertTrue($exact['success'], json_encode($exact));
        $this->assertSame('glossaryentryfilelimit', $over['errorcode']);
    }
}
