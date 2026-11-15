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

use core_external\external_api;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Image embedding and value conversion of update_mc_question (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_mc_question::class)]
final class update_mc_question_674_final_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Teacher who owns the question bank. */
    private \stdClass $teacher;

    /**
     * Course, teacher, question bank and one question with two answers.
     *
     * @return mixed[] The raw response of create_mc_question.
     */
    private function create_original(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $course->id, 'editingteacher');
        $this->setUser($this->teacher);
        $bank = ensure_question_bank::execute($course->id, 'Contract');
        $category = ensure_question_category::execute('Category', (int) $bank['topcategoryid']);
        $answers = [
            ['answer' => 'A', 'fraction' => 1, 'feedback' => 'Right'],
            ['answer' => 'B', 'fraction' => 0, 'feedback' => 'Wrong'],
        ];
        $created = create_mc_question::execute((int) $category['id'], 'Original', 'Original text', 'single', $answers);

        return external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);
    }

    /**
     * Puts an image into the teacher's material store.
     *
     * @param string $path
     */
    private function upload_material(string $path): void {
        external_api::clean_returnvalue(
            upload_material_file::execute_returns(),
            upload_material_file::execute($path, base64_encode('bytes'))
        );
    }

    /**
     * Creates a file in a foreign draft area of the teacher and returns the URL under which it is embedded.
     *
     * @param string $filename
     * @return string Draft file URL as the editor writes it.
     */
    private function foreign_draft_url(string $filename): string {
        global $CFG;
        $usercontext = \context_user::instance($this->teacher->id);
        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $itemid, 'filepath' => '/', 'filename' => $filename,
        ], 'foreign bytes');

        return $CFG->wwwroot . '/draftfile.php/' . $usercontext->id . '/user/draft/' . $itemid . '/' . $filename;
    }

    /**
     * Runs a callback with PHP warnings swallowed.
     *
     * @param callable $call
     * @return mixed What the callback returns.
     */
    private function without_warnings(callable $call): mixed {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Draft URLs pasted into the question text are stored as pluginfile placeholders.
     */
    public function test_draft_urls_in_the_question_text_are_stored_as_placeholders(): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material('stem.png');
        $url = $this->foreign_draft_url('foreign.png');

        $result = update_mc_question::execute($created['questionid'], json_encode([
            'questiontext' => '<img src="@@PLUGINFILE@@/stem.png"><img src="' . $url . '">',
            'questiontext_images' => ['stem.png'],
        ]));

        $stored = (string) $DB->get_field('question', 'questiontext', ['id' => $result['questionid']], MUST_EXIST);
        $this->assertSame('<img src="@@PLUGINFILE@@/stem.png"><img src="@@PLUGINFILE@@/foreign.png">', $stored);
    }

    /**
     * Draft URLs pasted into an answer feedback are stored as pluginfile placeholders.
     */
    public function test_draft_urls_in_an_answer_feedback_are_stored_as_placeholders(): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material('first.png');
        $url = $this->foreign_draft_url('foreign.png');

        $result = update_mc_question::execute($created['questionid'], json_encode([
            'answers' => [
                ['answer' => 'A', 'fraction' => 1,
                    'feedback' => '<img src="@@PLUGINFILE@@/first.png"><img src="' . $url . '">',
                    'feedback_images' => ['first.png']],
                ['answer' => 'B', 'fraction' => 0, 'feedback' => 'Wrong'],
            ],
        ]));

        $feedback = $DB->get_fieldset_select(
            'question_answers',
            'feedback',
            'question = ? ORDER BY id',
            [$result['questionid']]
        );
        $this->assertSame(['<img src="@@PLUGINFILE@@/first.png"><img src="@@PLUGINFILE@@/foreign.png">', 'Wrong'], $feedback);
    }

    /**
     * A list given as an answer text is converted to text like any other scalar-less value, not passed on as a list.
     */
    public function test_a_list_as_answer_text_is_converted_to_text(): void {
        global $DB;
        $created = $this->create_original();

        $result = $this->without_warnings(fn() => $this->succeeded(fn() => update_mc_question::execute(
            $created['questionid'],
            json_encode(['answers' => [['answer' => ['x'], 'fraction' => 1], ['answer' => 'B', 'fraction' => 0]]])
        )));

        $this->assertSame('updated', $result['status']);
        $answers = $DB->get_fieldset_select('question_answers', 'answer', 'question = ? ORDER BY id', [$result['questionid']]);
        $this->assertSame(['Array', 'B'], $answers);
    }

    /**
     * A list given as an answer feedback is converted to text like any other scalar-less value, not passed on as a list.
     */
    public function test_a_list_as_answer_feedback_is_converted_to_text(): void {
        global $DB;
        $created = $this->create_original();

        $result = $this->without_warnings(fn() => $this->succeeded(fn() => update_mc_question::execute(
            $created['questionid'],
            json_encode(['answers' => [
                ['answer' => 'A', 'fraction' => 1, 'feedback' => ['x']],
                ['answer' => 'B', 'fraction' => 0],
            ]])
        )));

        $this->assertSame('updated', $result['status']);
        $feedback = $DB->get_fieldset_select('question_answers', 'feedback', 'question = ? ORDER BY id', [$result['questionid']]);
        $this->assertSame(['Array', ''], $feedback);
    }
}
