<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/

namespace local_coursepilot\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Quiz-local categories and their reuse through the question tools.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(ensure_quiz_question_categories::class)]
final class ensure_quiz_question_categories_test extends \advanced_testcase {
    /**
     * A fresh quiz gets its native default category without visiting the editing page.
     */
    public function test_resolves_default_category_idempotently(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        // Moodle's test generator eagerly creates categories unlike an untouched quiz context.
        $DB->delete_records('question_categories', ['contextid' => \context_module::instance($quiz->cmid)->id]);
        $sink = $this->redirectEvents();

        $result = external_api::clean_returnvalue(
            ensure_quiz_question_categories::execute_returns(),
            ensure_quiz_question_categories::execute($course->id, $quiz->cmid)
        );
        $this->assertSame(\context_module::instance($quiz->cmid)->id, $result['contextid']);
        $this->assertContains($result['defaultcategoryid'], array_column($result['categories'], 'id'));
        $this->assertGreaterThan(0, $result['defaultcategoryid']);
        $created = array_filter($sink->get_events(), static fn($event): bool =>
            $event instanceof \core\event\question_category_created);
        $this->assertCount(2, $created);
        $sink->clear();

        $again = external_api::clean_returnvalue(
            ensure_quiz_question_categories::execute_returns(),
            ensure_quiz_question_categories::execute($course->id, $quiz->cmid)
        );
        $this->assertSame($result, $again);
        $this->assertSame([], $sink->get_events(), 'Reusing categories must not emit creation events.');
        $child = self::call(ensure_question_category::class, 'Subtopic', $result['defaultcategoryid']);
        $withchild = self::call(ensure_quiz_question_categories::class, $course->id, $quiz->cmid);
        $this->assertContains($child['id'], array_column($withchild['categories'], 'id'));
        $sink->close();
    }

    /**
     * The existing writer, XML transfer and core move keep their identity contracts in a quiz context.
     */
    public function test_question_can_be_transferred_and_moved_without_losing_quiz_reference(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $quiz = self::call(create_quiz::class, $course->id, 0, json_encode([
            'name' => 'Local questions', 'intro' => '', 'subnet' => '', 'browsersecurity' => '-',
        ]), 'mini-check');
        $local = self::call(ensure_quiz_question_categories::class, $course->id, $quiz['cmid']);
        $category = self::call(ensure_question_category::class, 'Arithmetic', $local['defaultcategoryid']);
        $this->assertSame($local['contextid'], $category['contextid']);
        $question = self::call(create_mc_question::class, $category['id'], 'Addition', 'What is 2+2?', 'single', [
            ['answer' => '4', 'fraction' => 1.0, 'feedback' => 'Correct'],
            ['answer' => '5', 'fraction' => 0.0, 'feedback' => 'Try again'],
        ], 1.0, 'Add two pairs.');
        $entryid = $question['questionbankentryid'];
        $slots = self::call(add_questions_to_quiz::class, $quiz['cmid'], [$question['questionid']]);
        $this->assertSame($entryid, $slots['slots'][0]['questionbankentryid']);

        $updated = self::call(update_mc_question::class, $question['questionid'], '{"questiontext":"What is two plus two?"}');
        $this->assertSame(2, $updated['version']);
        $this->assertSame($entryid, $updated['questionbankentryid']);
        $source = self::call(get_question::class, $category['id'], '', $question['questionid']);
        $this->assertSame($category['id'], $source['categoryid']);
        $this->assertSame($entryid, $source['questionbankentryid']);

        $bank = self::call(ensure_question_bank::class, $course->id, 'Course arithmetic');
        $target = self::call(ensure_question_category::class, 'Transferred', $bank['topcategoryid']);
        $export = self::call(export_questions_xml::class, [$updated['questionid']], 'local-questions.xml');
        $import = self::call(import_questions_xml::class, $target['id'], '', false, $export['path']);
        $this->assertSame('suspect', $import['questions'][0]['status']);
        $import = self::call(import_questions_xml::class, $target['id'], '', true, $export['path']);
        $copy = $import['questions'][0];
        $this->assertSame('first_import', $copy['status']);
        $this->assertSame(1, $copy['version']);
        $this->assertNotSame($entryid, $copy['questionbankentryid']);
        $readback = self::call(get_question::class, $target['id'], 'Addition');
        foreach (['name', 'questiontext', 'generalfeedback', 'selectionmode', 'defaultmark'] as $field) {
            $this->assertSame($source[$field], $readback[$field]);
        }
        $this->assertSame(array_column($source['answers'], 'answer'), array_column($readback['answers'], 'answer'));
        $this->assertSame(array_column($source['answers'], 'fraction'), array_column($readback['answers'], 'fraction'));
        $this->assertSame(array_column($source['answers'], 'feedback'), array_column($readback['answers'], 'feedback'));
        $reimport = self::call(import_questions_xml::class, $target['id'], '', false, $export['path']);
        $this->assertSame($copy['questionbankentryid'], $reimport['questions'][0]['questionbankentryid']);
        $this->assertSame(2, $reimport['questions'][0]['version']);
        $this->assertSame('reimport', $reimport['questions'][0]['status']);

        // Separate destination avoids the intentional lineage-collision gate after XML copying.
        $movetarget = self::call(ensure_question_category::class, 'Moved', $bank['topcategoryid']);
        $moved = self::call(move_question::class, $question['questionid'], $movetarget['id']);
        $this->assertSame('moved', $moved['status']);
        $this->assertSame($entryid, $moved['questionbankentryid']);
        $this->assertSame([$question['questionid'], $updated['questionid']], $moved['versionids']);
        $after = self::call(get_question::class, $movetarget['id'], '', $question['questionid']);
        $this->assertSame($movetarget['id'], $after['categoryid']);
        $this->assertSame($source['questiontext'], $after['questiontext']);
        $slots = self::call(add_questions_to_quiz::class, $quiz['cmid'], [$question['questionid']]);
        $this->assertFalse($slots['appended'][0]['added']);
        $this->assertCount(1, $slots['slots']);
        $this->assertSame($entryid, $slots['slots'][0]['questionbankentryid']);
        $this->assertSame($updated['questionid'], $slots['slots'][0]['questionid']);
        $this->assertSame(2, $slots['slots'][0]['version']);
    }

    /**
     * Reject a non-quiz activity or a quiz outside the requested course.
     *
     * @param string $modulename Activity type
     * @param bool $othercourse Whether the module is in another course
     */
    #[DataProvider('invalid_module_provider')]
    public function test_rejects_invalid_module(string $modulename, bool $othercourse): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $targetcourse = $othercourse ? $this->getDataGenerator()->create_course() : $course;
        $module = $this->getDataGenerator()->create_module($modulename, ['course' => $targetcourse->id]);
        $this->expectException(\invalid_parameter_exception::class);
        ensure_quiz_question_categories::execute($course->id, $module->cmid);
    }

    /** @return array<string, array{string, bool}> */
    public static function invalid_module_provider(): array {
        return ['page' => ['page', false], 'question bank' => ['qbank', false], 'foreign quiz' => ['quiz', true]];
    }

    /**
     * Both context levels and native category permissions are checked before initialization.
     *
     * @param string $capability Capability to prohibit
     * @param bool $atmodule Whether to prohibit in the quiz context
     */
    #[DataProvider('missing_capability_provider')]
    public function test_rejects_missing_capability(string $capability, bool $atmodule): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = $atmodule ? \context_module::instance($quiz->cmid) : \context_course::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, $context->id, true);
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        ensure_quiz_question_categories::execute($course->id, $quiz->cmid);
    }

    /** @return array<string, array{string, bool}> */
    public static function missing_capability_provider(): array {
        return [
            'course access' => ['local/coursepilot:use', false],
            'quiz access' => ['local/coursepilot:use', true],
            'category management' => ['moodle/question:managecategory', true],
            'question viewing' => ['moodle/question:viewall', true],
        ];
    }

    /**
     * Unenrolled users cannot discover quiz-local categories.
     */
    public function test_rejects_unenrolled_user(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        ensure_quiz_question_categories::execute($course->id, $quiz->cmid);
    }

    /**
     * Execute the same parameter and return validation as an external client.
     *
     * @param class-string<external_api> $tool External tool class
     * @param mixed ...$args Tool arguments
     * @return array
     */
    private static function call(string $tool, mixed ...$args): array {
        return external_api::clean_returnvalue($tool::execute_returns(), $tool::execute(...$args));
    }
}
