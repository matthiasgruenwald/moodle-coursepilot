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
use local_coursepilot\material_files;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact response, message and error contract of update_mc_question (Spec 0017 §7.1, issue #419).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_mc_question::class)]
#[CoversClass(\local_coursepilot\question_xml::class)]
#[CoversClass(\local_coursepilot\question_import::class)]
#[CoversClass(\local_coursepilot\question_suspect_gate::class)]
final class update_mc_question_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var mixed[][] Two-option answer list with exactly one correct answer. */
    private const ANSWERS = [
        ['answer' => 'A', 'fraction' => 1, 'feedback' => 'Right'],
        ['answer' => 'B', 'fraction' => 0, 'feedback' => 'Wrong'],
    ];

    /** @var \stdClass Teacher who owns the question bank. */
    private \stdClass $teacher;

    /** @var int Category of the question bank. */
    private int $categoryid;

    /**
     * Drops the container created for the external storage tests of the sibling class.
     */
    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Course, teacher, question bank and one question named "Original".
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
        $this->categoryid = (int) $category['id'];
        $created = create_mc_question::execute($this->categoryid, 'Original', 'Original text', 'single', self::ANSWERS);

        return external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);
    }

    /**
     * Id of the newest question row of a bank entry.
     *
     * @param int $entryid
     * @return int
     */
    private function latest_questionid(int $entryid): int {
        global $DB;
        return (int) $DB->get_field_sql(
            'SELECT questionid FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$entryid],
            IGNORE_MULTIPLE
        );
    }

    public function test_success_response_has_the_exact_shape_and_types(): void {
        $created = $this->create_original();
        $entryid = (int) $created['questionbankentryid'];

        $result = update_mc_question::execute($created['questionid'], '{"name":"Renamed"}');

        $this->assertSame([
            'name' => 'Renamed',
            'questionid' => $this->latest_questionid($entryid),
            'questionbankentryid' => $entryid,
            'version' => 2,
            'status' => 'updated',
            'idnumber_added' => false,
            'message' => 'MC question "Renamed" updated (bank entry ' . $entryid . ', new version 2).',
            'idnumber' => '',
            'categoryid' => 0,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => '',
        ], $result);
        $this->assertGreaterThan($created['questionid'], $result['questionid']);
    }

    public function test_missing_idnumber_is_assigned_retroactively_and_named_in_the_message(): void {
        global $DB;
        $created = $this->create_original();
        $entryid = (int) $created['questionbankentryid'];
        $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $entryid]);

        $result = update_mc_question::execute($created['questionid'], '{"name":"Renamed"}');

        $idnumber = $DB->get_field('question_bank_entries', 'idnumber', ['id' => $entryid], MUST_EXIST);
        $this->assertMatchesRegularExpression('/^kp-[a-f0-9]{16}$/', $idnumber);
        $this->assertTrue($result['idnumber_added']);
        $this->assertSame($entryid, $result['questionbankentryid']);
        $this->assertSame(
            'MC question "Renamed" updated (bank entry ' . $entryid . ', new version 2). idnumber "' . $idnumber
                . '" was assigned retroactively (the question had none before).',
            $result['message']
        );
    }

    public function test_an_idnumber_of_only_blanks_counts_as_missing(): void {
        global $DB;
        $created = $this->create_original();
        $entryid = (int) $created['questionbankentryid'];
        $DB->set_field('question_bank_entries', 'idnumber', '   ', ['id' => $entryid]);

        $result = update_mc_question::execute($created['questionid'], '{"name":"Renamed"}');

        $this->assertTrue($result['idnumber_added']);
        $this->assertSame($entryid, $result['questionbankentryid'], 'A new version, not a new entry.');
        $this->assertSame(2, $result['version']);
    }

    public function test_an_embedded_file_is_named_in_the_message_when_it_is_dropped(): void {
        $created = $this->create_original();
        $entryid = (int) $created['questionbankentryid'];
        $context = $this->category_context();
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'question', 'filearea' => 'questiontext',
            'itemid' => $created['questionid'], 'filepath' => '/', 'filename' => 'old.png',
        ], 'bytes');

        $result = update_mc_question::execute($created['questionid'], '{"name":"Renamed"}');

        $this->assertSame(
            'MC question "Renamed" updated (bank entry ' . $entryid . ', new version 2).'
                . ' WARNING: The question contained embedded files that were removed in the process: old.png.',
            $result['message']
        );
    }

    public function test_an_unmatched_idnumber_is_held_back_until_confirmed(): void {
        global $DB;
        $created = $this->create_original();
        $entryid = (int) $created['questionbankentryid'];
        // A leading blank is part of the stored value but not of the idnumber the question is written back with.
        $DB->set_field('question_bank_entries', 'idnumber', ' kp-held', ['id' => $entryid]);

        $held = update_mc_question::execute($created['questionid'], '{"name":"Renamed"}');

        $this->assertSame([
            'name' => 'Renamed',
            'questionid' => 0,
            'questionbankentryid' => 0,
            'version' => 0,
            'status' => 'suspect',
            'idnumber_added' => false,
            'message' => get_string('questionimportsuspect', 'local_coursepilot', 'kp-held'),
            'idnumber' => 'kp-held',
            'categoryid' => $this->categoryid,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => 'Original text',
        ], $held);
        $this->assertSame(1, $DB->count_records('question_versions', ['questionbankentryid' => $entryid]));

        $confirmed = update_mc_question::execute($created['questionid'], '{"name":"Renamed"}', true);

        $this->assertSame('updated', $confirmed['status']);
        $this->assertNotSame($entryid, $confirmed['questionbankentryid'], 'The confirmed write creates the entry.');
    }

    public function test_questions_of_other_types_are_refused_with_their_type_named(): void {
        $this->create_original();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $generator->create_question('truefalse', null, ['category' => $this->categoryid]);

        $e = $this->caught(fn() => update_mc_question::execute((int) $question->id, '{"name":"Refused"}'));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame(
            'update_mc_question only works for multiple-choice questions (qtype "multichoice"); '
                . 'this question is "truefalse".',
            $e->debuginfo
        );
    }

    public function test_unknown_field_lists_the_allowed_fields(): void {
        $created = $this->create_original();

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], '{"penalty":0.5}'));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame(
            'Unknown field "penalty" in fields_json. Allowed: name, questiontext, selectionmode, answers, '
                . 'defaultmark, generalfeedback, questiontext_images.',
            $e->debuginfo
        );
    }

    /**
     * Provides documents that are not a JSON object.
     *
     * @return array<string, array{string}>
     */
    public static function non_object_documents(): array {
        return ['number' => ['5'], 'string' => ['"text"'], 'null' => ['null'], 'boolean' => ['true']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('non_object_documents')]
    public function test_json_that_is_not_an_object_is_refused_as_invalid_patch(string $json): void {
        $created = $this->create_original();

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], $json));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpatchjson', $e->errorcode);
    }

    public function test_selection_mode_is_stored_as_one_for_single_and_zero_for_multiple(): void {
        global $DB;
        $created = $this->create_original();

        $multiple = update_mc_question::execute($created['questionid'], json_encode([
            'selectionmode' => 'multiple',
            'answers' => [['answer' => 'A', 'fraction' => 0.5], ['answer' => 'B', 'fraction' => 0.5]],
        ]));
        $this->assertSame(
            '0',
            (string) $DB->get_field('qtype_multichoice_options', 'single', ['questionid' => $multiple['questionid']])
        );

        $single = $this->succeeded(fn() => update_mc_question::execute($multiple['questionid'], json_encode([
            'selectionmode' => 'single',
            'answers' => self::ANSWERS,
        ])));
        $this->assertSame('updated', $single['status']);
        $this->assertSame(
            '1',
            (string) $DB->get_field('qtype_multichoice_options', 'single', ['questionid' => $single['questionid']])
        );
    }

    public function test_selection_mode_alone_is_checked_against_the_stored_answers(): void {
        $created = $this->create_original();
        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], '{"selectionmode":"multiple"}'));

        $this->assertNull($e, 'One correct answer is valid in multiple mode as well.');

        $second = update_mc_question::execute($created['questionid'], json_encode([
            'answers' => [['answer' => 'A', 'fraction' => 0.5], ['answer' => 'B', 'fraction' => 0.5]],
        ]));
        $e = $this->caught(fn() => update_mc_question::execute($second['questionid'], '{"selectionmode":"single"}'));
        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('Single selection needs exactly one correct answer.', $e->debuginfo);
    }

    public function test_answers_given_as_an_object_keep_their_order(): void {
        global $DB;
        $created = $this->create_original();

        $result = $this->succeeded(fn() => update_mc_question::execute($created['questionid'], json_encode(['answers' => [
            'first' => ['answer' => 'One', 'fraction' => 1],
            'second' => ['answer' => 'Two', 'fraction' => 0],
        ]])));

        $answers = array_values($DB->get_records('question_answers', ['question' => $result['questionid']], 'id ASC'));
        $this->assertSame(['One', 'Two'], array_map(static fn($a) => $a->answer, $answers));
    }

    public function test_non_numeric_numbers_are_converted_like_floats(): void {
        global $DB;
        $created = $this->create_original();

        $result = $this->succeeded(fn() => update_mc_question::execute($created['questionid'], json_encode([
            'defaultmark' => 'abc',
            'answers' => [['answer' => 'A', 'fraction' => 1], ['answer' => 'B', 'fraction' => 'abc']],
        ])));

        $this->assertSame('updated', $result['status']);
        $this->assertEquals(0, $DB->get_field('question', 'defaultmark', ['id' => $result['questionid']]));
        $fractions = $DB->get_fieldset_select('question_answers', 'fraction', 'question = ? ORDER BY id', [$result['questionid']]);
        $this->assertEquals([1, 0], $fractions);
    }

    public function test_the_context_restriction_of_the_web_service_is_enforced_before_anything_is_prepared(): void {
        $created = $this->create_original();
        $other = $this->getDataGenerator()->create_course();
        $this->upload_material_directly('image.png');
        $drafts = $this->draft_file_count();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], $this->image_patch()));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
        $this->assertSame($drafts, $this->draft_file_count(), 'No image draft was prepared for a refused call.');
    }

    /**
     * Patch with an embedded image of the uploaded file "image.png".
     *
     * @return string
     */
    private function image_patch(): string {
        return json_encode(['questiontext' => '<img src="@@PLUGINFILE@@/image.png">', 'questiontext_images' => ['image.png']]);
    }

    /**
     * Provides the capabilities the tool requires.
     *
     * @return array<string, array{string}>
     */
    public static function required_capabilities(): array {
        return ['plugin use' => ['local/coursepilot:use'], 'question add' => ['moodle/question:add']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('required_capabilities')]
    public function test_each_required_capability_is_checked_before_anything_is_prepared(string $capability): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material_directly('image.png');
        $drafts = $this->draft_file_count();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], $this->image_patch()));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
        $this->assertSame($drafts, $this->draft_file_count(), 'No image draft was prepared for a refused call.');
    }

    public function test_a_patch_without_images_needs_no_file_permission(): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material_directly('image.png');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($this->teacher->id)->id,
            true
        );

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], '{"name":"Renamed"}'));
        $this->assertNull($e);

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], json_encode([
            'questiontext' => '<img src="@@PLUGINFILE@@/image.png">', 'questiontext_images' => ['image.png'],
        ])));
        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_the_extension_of_every_feedback_image_is_checked(): void {
        $created = $this->create_original();
        $answers = self::ANSWERS;
        $answers[1]['feedback_images'] = ['payload.exe'];

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], json_encode(['answers' => $answers])));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialfiledisallowedtype', $e->errorcode);
        $this->assertEquals(
            (object) [
                'filename' => 'payload.exe',
                'allowed' => implode(', ', material_files::allowed_embed_image_extensions()),
            ],
            $e->a
        );
        $this->assertSame(
            get_string('materialfiledisallowedtype', 'local_coursepilot', $e->a),
            $e->getMessage()
        );
    }

    public function test_a_non_text_image_entry_is_named_as_text(): void {
        $created = $this->create_original();
        $answers = self::ANSWERS;
        $answers[0]['feedback_images'] = [5];

        $e = $this->caught(fn() => update_mc_question::execute($created['questionid'], json_encode(['answers' => $answers])));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialfiledisallowedtype', $e->errorcode);
        $this->assertSame('5', $e->a->filename);
    }

    public function test_images_of_question_text_and_several_answers_are_embedded_together(): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material_directly('stem.png', 'stem bytes');
        $this->upload_material_directly('first.png', 'first bytes');
        $this->upload_material_directly('second.png', 'second bytes');
        $drafts = $this->draft_file_count();

        $result = update_mc_question::execute($created['questionid'], json_encode([
            'questiontext' => '<img src="@@PLUGINFILE@@/stem.png" alt="stem">',
            'questiontext_images' => ['stem.png'],
            'answers' => [
                ['answer' => 'A', 'fraction' => 1, 'feedback' => '<img src="@@PLUGINFILE@@/first.png" alt="first">',
                    'feedback_images' => ['first.png']],
                ['answer' => 'B', 'fraction' => 0, 'feedback' => '<img src="@@PLUGINFILE@@/second.png" alt="second">',
                    'feedback_images' => ['second.png']],
            ],
        ]));

        $answers = array_values($DB->get_records('question_answers', ['question' => $result['questionid']], 'id ASC'));
        $this->assertSame('stem bytes', $this->stored('questiontext', $result['questionid'], 'stem.png'));
        $this->assertSame('first bytes', $this->stored('answerfeedback', (int) $answers[0]->id, 'first.png'));
        $this->assertSame('second bytes', $this->stored('answerfeedback', (int) $answers[1]->id, 'second.png'));
        $this->assertSame($drafts, $this->draft_file_count(), 'No draft area is left behind.');
        $this->assertSame(
            '<img src="@@PLUGINFILE@@/stem.png" alt="stem">',
            $DB->get_field('question', 'questiontext', ['id' => $result['questionid']])
        );
        $this->assertSame('Original text', $DB->get_field('question', 'questiontext', ['id' => $created['questionid']]));
    }

    public function test_embedding_starts_from_empty_file_areas_and_uses_exact_placeholder_ids(): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material_directly('stem.png', 'stem bytes');
        $this->upload_material_directly('first.png', 'first bytes');
        $context = $this->category_context();
        $fs = get_file_storage();
        // Files at ids that a miscounted preload or placeholder id would pick up: the preload id of an
        // unwritten question or answer must stay empty, and the placeholder ids of the exported answers
        // must match no stored file (file storage has no negative item ids).
        foreach (['questiontext', 'answerfeedback'] as $area) {
            $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => 'question', 'filearea' => $area,
                'itemid' => 1, 'filepath' => '/', 'filename' => "planted-{$area}.png",
            ], 'planted');
        }
        foreach ([0, 1] as $itemid) {
            $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => 'question', 'filearea' => 'answer',
                'itemid' => $itemid, 'filepath' => '/', 'filename' => 'planted-answer.png',
            ], 'planted');
        }

        $result = update_mc_question::execute($created['questionid'], json_encode([
            'questiontext' => '<img src="@@PLUGINFILE@@/stem.png">',
            'questiontext_images' => ['stem.png'],
            'answers' => [
                ['answer' => 'A', 'fraction' => 1, 'feedback' => '<img src="@@PLUGINFILE@@/first.png">',
                    'feedback_images' => ['first.png']],
                ['answer' => 'B', 'fraction' => 0],
            ],
        ]));

        $this->assertStringNotContainsString('WARNING', $result['message']);
        $answers = array_values($DB->get_records('question_answers', ['question' => $result['questionid']], 'id ASC'));
        foreach (['questiontext' => $result['questionid'], 'answerfeedback' => (int) $answers[0]->id] as $area => $itemid) {
            $this->assertFalse(
                $DB->record_exists('files', ['component' => 'question', 'filearea' => $area, 'itemid' => $itemid,
                    'filename' => "planted-{$area}.png"]),
                "Nothing planted at an unrelated id ends up in {$area} of the new version."
            );
        }
    }

    public function test_embedding_changes_only_the_written_question_row(): void {
        global $DB;
        $created = $this->create_original();
        $neighbour = create_mc_question::execute($this->categoryid, 'Neighbour', 'Neighbour text', 'single', self::ANSWERS);
        $this->upload_material_directly('stem.png', 'stem bytes');

        $result = update_mc_question::execute($created['questionid'], json_encode([
            'questiontext' => '<img src="@@PLUGINFILE@@/stem.png">', 'questiontext_images' => ['stem.png'],
        ]));

        $this->assertSame(
            'Neighbour text',
            $DB->get_field('question', 'questiontext', ['id' => $neighbour['questionid']])
        );
        $this->assertSame(
            '<img src="@@PLUGINFILE@@/stem.png">',
            $DB->get_field('question', 'questiontext', ['id' => $result['questionid']])
        );
    }

    public function test_a_successful_write_leaves_no_transaction_open(): void {
        global $DB;
        $created = $this->create_original();

        update_mc_question::execute($created['questionid'], '{"name":"Renamed"}');

        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_a_patch_of_other_fields_does_not_revalidate_foreign_answers(): void {
        global $DB;
        $this->create_original();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $foreign = $generator->create_question(
            'multichoice',
            'one_of_four',
            ['category' => $this->categoryid, 'name' => 'Foreign']
        );
        // Moodle accepts partial credit next to the full answer in single mode, Coursepilot's creation rule does not.
        $answerids = array_keys($DB->get_records('question_answers', ['question' => $foreign->id], 'id'));
        $DB->set_field('question_answers', 'fraction', 0.5, ['id' => $answerids[1]]);

        $result = $this->succeeded(fn() => update_mc_question::execute((int) $foreign->id, '{"name":"Foreign renamed"}'));

        $this->assertSame('updated', $result['status']);
        $e = $this->caught(fn() => update_mc_question::execute($result['questionid'], '{"selectionmode":"single"}'));
        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('Single selection needs exactly one correct answer.', $e->debuginfo);
    }

    public function test_feedback_images_of_answers_given_as_an_object_belong_to_their_position(): void {
        global $DB;
        $created = $this->create_original();
        $this->upload_material_directly('second.png', 'second bytes');

        $result = $this->succeeded(fn() => update_mc_question::execute($created['questionid'], json_encode(['answers' => [
            'first' => ['answer' => 'One', 'fraction' => 1],
            'second' => ['answer' => 'Two', 'fraction' => 0, 'feedback' => '<img src="@@PLUGINFILE@@/second.png">',
                'feedback_images' => ['second.png']],
        ]])));

        $answers = array_values($DB->get_records('question_answers', ['question' => $result['questionid']], 'id ASC'));
        $this->assertSame('second bytes', $this->stored('answerfeedback', (int) $answers[1]->id, 'second.png'));
    }

    /**
     * Context of the question bank holding the questions.
     *
     * @return \context
     */
    private function category_context(): \context {
        global $DB;
        $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $this->categoryid], MUST_EXIST);
        return \context::instance_by_id($contextid);
    }

    /**
     * Number of file records in the teacher's draft areas.
     *
     * @return int
     */
    private function draft_file_count(): int {
        global $DB;
        return $DB->count_records('files', [
            'contextid' => \context_user::instance($this->teacher->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
        ]);
    }

    /**
     * Content of a stored question file, or its absence as a failed assertion.
     *
     * @param string $area
     * @param int $itemid
     * @param string $filename
     * @return string
     */
    private function stored(string $area, int $itemid, string $filename): string {
        global $DB;
        $id = $DB->get_field('files', 'id', ['component' => 'question', 'filearea' => $area, 'itemid' => $itemid,
            'filename' => $filename]);
        $this->assertNotFalse($id, "{$filename} is stored in {$area} of item {$itemid}");
        return get_file_storage()->get_file_by_id($id)->get_content();
    }

    /**
     * Puts an image into the teacher's material store.
     *
     * @param string $path
     * @param string $content
     */
    private function upload_material_directly(string $path, string $content = 'bytes'): void {
        external_api::clean_returnvalue(
            upload_material_file::execute_returns(),
            upload_material_file::execute($path, base64_encode($content))
        );
    }
}
