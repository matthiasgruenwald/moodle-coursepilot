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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\material_files;
use local_coursepilot\question_suspect_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Read-modify-write for a multiple-choice question (Spec 0017 §7.1, ticket
 * #419): "fields_json" is a PATCH, not a full record (same vocabulary as
 * {@see update_module_settings}/{@see update_quiz_settings}) - fields that
 * are not sent must NOT get lost.
 *
 * Unlike a simple text-based XML template (like
 * {@see create_mc_question::build_xml()}, which deliberately sets fixed
 * values for penalty/shuffleanswers/answernumbering/combined feedback for a
 * NEW question), this endpoint reads the question via
 * {@see export_questions_xml::resolve_native_question()} as a NATIVE
 * object - exactly the form {@see \qformat_xml::writequestion()} also uses
 * for the real export. Only the properties named in the patch are
 * overwritten (name, questiontext, generalfeedback, defaultmark,
 * options->single, options->answers); everything else (penalty, hidden,
 * shuffleanswers, answernumbering, combined feedback, tags, hints, ...) stays
 * untouched because it is never touched - no reconstruction or guessing
 * risk. The FULL record is then written back through the same XML core as
 * {@see import_questions_xml} (including round-trip check and rollback).
 *
 * idnumber backfill (exactly ONE question, no bulk run): if the question
 * found carries no idnumber yet - e.g. from foreign content -, one is
 * generated exactly for THIS bank entry on the first write and written
 * directly to question_bank_entries BEFORE the XML is built. Only this way
 * does import_questions_xml recognise the written XML as a new version of
 * the SAME entry (match via idnumber in the category, ADR 0015) instead of
 * as a new entry. Backfill + write run in a shared transaction (same
 * pattern as import_questions_xml itself) - if the round trip fails, the
 * freshly assigned idnumber is rolled back as well (see the comment at
 * $transaction below).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_mc_question extends external_api {
    /** @var string[] Allowed patch fields - everything else is an error (trust boundary). */
    private const PATCHABLE_FIELDS = [
        'name', 'questiontext', 'selectionmode', 'answers', 'defaultmark', 'generalfeedback',
        // Spec 0018 §4/§7, issue #435: material folder paths for images that are
        // referenced in the "questiontext" patch via @@PLUGINFILE@@ - see
        // {@see self::embed_material_images()}. No moduleinfo equivalent,
        // this field never lands on the native question object.
        'questiontext_images',
    ];

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'questionid of any version of the question to change'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => new value - only the fields to change (a patch, not a full record). '
                    . 'Allowed: name, questiontext, selectionmode, answers, defaultmark, generalfeedback, '
                    . 'questiontext_images. Fields not named stay unchanged. An image from the material folder is '
                    . 'embedded by having questiontext (or an answer\'s feedback in answers) contain an '
                    . '"<img src=\"@@PLUGINFILE@@/<filename>\" alt=\"...\">" AND the filename additionally named in '
                    . 'questiontext_images (or, in the answers entry, under "feedback_images") as a list of '
                    . 'material folder paths.'
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms a previously reported suspect case from the XML core. Omit or false on '
                    . 'the first call.',
                VALUE_DEFAULT,
                false
            ),
            'location' => material_files::location_parameter(),
        ]);
    }

    /**
     * Runs the update mc question tool.
     *
     * @param int $questionid
     * @param string $fieldsjson
     * @param bool $confirmed
     * @param string $location
     * @return array
     */
    public static function execute(
        int $questionid,
        string $fieldsjson,
        bool $confirmed = false,
        string $location = material_files::LOCATION_STORE
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'fields_json' => $fieldsjson,
            'confirmed' => $confirmed,
            'location' => $location,
        ]);

        [$question, $category, $context] = self::resolve_and_authorise($params['questionid']);
        [$patch, $questiontextdraftitemid, $answerfeedbackdraftitemids] =
            self::apply_field_patch($question, $context, $params);

        $categoryid = (int) $category->id;
        $entry = get_question_bank_entry((int) $question->id);
        $write = self::persist_new_version($question, $category, $context, $categoryid, $entry, $params['confirmed']);
        $result = $write['result'];

        if ($result['status'] === 'suspect') {
            return self::build_suspect_response($result);
        }

        return self::build_success_response(
            $entry,
            $context,
            $write,
            $questiontextdraftitemid,
            $answerfeedbackdraftitemids,
            $question
        );
    }

    /**
     * Response for the suspect-case branch (issue #523: extracted from
     * execute()) - can only occur with a concurrent foreign change to the
     * same bank entry (the idnumber just assigned matches by construction) -
     * same response format as create_mc_question.
     *
     * @param array $result
     * @return array
     */
    private static function build_suspect_response(array $result): array {
        return [
            'name' => $result['name'],
            'questionid' => 0,
            'questionbankentryid' => 0,
            'version' => 0,
            'status' => 'suspect',
            'idnumber_added' => false,
            'message' => $result['message'],
            'idnumber' => $result['idnumber'],
            'categoryid' => $result['categoryid'],
            'candidates' => $result['candidates'],
            'questiontext_old' => $result['questiontext_old'],
            'questiontext_new' => $result['questiontext_new'],
        ];
    }

    /**
     * Resolves the native question and checks context/capabilities/qtype
     * (issue #523: extracted from execute() to keep the function under the
     * 50-line limit).
     *
     * @param int $questionid
     * @return array{0: \stdClass, 1: \stdClass, 2: \context}
     */
    private static function resolve_and_authorise(int $questionid): array {
        [$question, $category, $context] = export_questions_xml::resolve_native_question($questionid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Same capability as for a new version in import_questions_xml/
        // create_mc_question - writing a new version is the same write
        // operation, not a separate Coursepilot "edit" permission.
        require_capability('moodle/question:add', $context);

        if ($question->qtype !== 'multichoice') {
            throw new \invalid_parameter_exception(
                'update_mc_question only works for multiple-choice questions (qtype "multichoice"); '
                . 'this question is "' . $question->qtype . '".'
            );
        }

        return [$question, $category, $context];
    }

    /**
     * Decodes the patch, resolves embedded images into drafts and applies the
     * patch to the native question object (issue #523: extracted from
     * execute()).
     *
     * @param \stdClass $question
     * @param \context $context
     * @param array $params Validated parameters of execute().
     * @return array{0: array, 1: ?int, 2: array<int, int>}
     */
    private static function apply_field_patch(\stdClass $question, \context $context, array $params): array {
        $patch = self::decode_patch($params['fields_json']);
        // Split off before apply_patch() (issue #435): questiontext_images is
        // not a field of the native question object, and a per-answer
        // feedback_images would be discarded by build_answer_objects() anyway
        // (it only reads answer/fraction/feedback). Both lists are applied
        // only AFTER writing (see below) because they need the question/
        // answer id of the NEW version - which only comes into existence in
        // the import_questions_xml call further down.
        $questiontextimages = is_array($patch['questiontext_images'] ?? null) ? $patch['questiontext_images'] : [];
        $answerfeedbackimages = self::extract_answer_feedback_images($patch);
        // All-or-nothing (same rule as update_module_settings::validate_patch()):
        // permission, extension whitelist AND material file existence are
        // checked/resolved BEFORE the write transaction - a new version is
        // not created only to then fail on a trivial embed validation.
        // resolve_into_draft() already throws materialfilenotfound here if a
        // referenced file is missing.
        [$questiontextdraftitemid, $answerfeedbackdraftitemids] =
            self::prepare_image_drafts($context, $questiontextimages, $answerfeedbackimages, $params['location']);
        self::apply_patch($question, $patch);

        return [$patch, $questiontextdraftitemid, $answerfeedbackdraftitemids];
    }

    /**
     * Writes the new question version in a transaction, with optional
     * idnumber backfill (issue #523: extracted from execute()).
     * @param \stdClass $question
     * @param \stdClass $category
     * @param \context $context
     * @param int $categoryid
     * @param \stdClass $entry
     * @param bool $confirmed
     * @return array{result: array, backfilled: bool, idnumber: string, missingfiles: string[]}
     */
    private static function persist_new_version(
        \stdClass $question,
        \stdClass $category,
        \context $context,
        int $categoryid,
        \stdClass $entry,
        bool $confirmed
    ): array {
        global $DB;

        // No try/catch+rollback of our own here: import_questions_xml::execute()
        // already rolls back its OWN (nested) transaction itself on a
        // round-trip failure and rethrows the exception - a second rollback()
        // call on this (by then already ended) transaction would itself throw
        // a dml_transaction_exception. If THIS transaction is left without
        // allow_commit() (because the exception is passed through unhandled),
        // Moodle rolls it back automatically (pattern documented in
        // import_questions_xml) - including the backfill just assigned.
        $transaction = $DB->start_delegated_transaction();
        $backfilled = false;

        $idnumber = trim((string) ($entry->idnumber ?? ''));
        if ($idnumber === '') {
            // Backfill ONLY for this one bank entry (ticket #419) -
            // no bulk run over the category/question bank.
            $idnumber = self::generate_idnumber();
            $DB->set_field('question_bank_entries', 'idnumber', $idnumber, ['id' => $entry->id]);
            $backfilled = true;
        }
        $question->idnumber = $idnumber;

        [$xml, $missingfiles] = export_questions_xml::question_to_xml($question, $category, $context);
        $wrapped = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . $xml . "\n</quiz>\n";

        $imported = import_questions_xml::execute($categoryid, $wrapped, $confirmed);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);

        $transaction->allow_commit();

        return [
            'result' => $imported['questions'][0],
            'backfilled' => $backfilled,
            'idnumber' => $idnumber,
            'missingfiles' => $missingfiles,
        ];
    }

    /**
     * Embeds the (already resolved) image drafts into the new version and
     * builds the success response (issue #523: extracted from execute()).
     * @param \stdClass $entry
     * @param \context $context
     * @param array $write Type: array{result:array,backfilled:bool,idnumber:string,missingfiles:string[]}.
     * @param ?int $questiontextdraftitemid
     * @param int[] $answerfeedbackdraftitemids
     * @param \stdClass $question
     * @return array
     */
    private static function build_success_response(
        \stdClass $entry,
        \context $context,
        array $write,
        ?int $questiontextdraftitemid,
        array $answerfeedbackdraftitemids,
        \stdClass $question
    ): array {
        $result = $write['result'];
        $latest = question_suspect_gate::latest_version_question((int) $entry->id);

        if ($questiontextdraftitemid !== null || !empty($answerfeedbackdraftitemids)) {
            self::embed_images($context, (int) $latest->id, $questiontextdraftitemid, $answerfeedbackdraftitemids);
        }

        $message = 'MC question "' . $question->name . '" updated (bank entry ' . $result['questionbankentryid']
            . ', new version ' . $result['version'] . ').';
        if ($write['backfilled']) {
            $message .= ' idnumber "' . $write['idnumber'] . '" was assigned retroactively (the question had none before).';
        }
        if (!empty($write['missingfiles'])) {
            // Same transparency duty as export_questions_xml: embedded
            // files are NOT silently lost, the message names them
            // explicitly.
            $message .= ' WARNING: The question contained embedded files that were removed in the process: '
                . implode(', ', $write['missingfiles']) . '.';
        }

        return array_merge(
            [
                'name' => $result['name'],
                'questionid' => (int) $latest->id,
                'questionbankentryid' => (int) $result['questionbankentryid'],
                'version' => (int) $result['version'],
                'status' => 'updated',
                'idnumber_added' => $write['backfilled'],
                'message' => $message,
            ],
            question_suspect_gate::empty_result()
        );
    }

    /**
     * Decodes and validates fields_json: must be a JSON object whose keys
     * are a subset of {@see self::PATCHABLE_FIELDS} - unknown fields abort
     * the call (trust boundary) instead of being silently ignored.
     * @param string $fieldsjson
     * @return array<string, mixed>
     */
    private static function decode_patch(string $fieldsjson): array {
        $patch = json_decode($fieldsjson, true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE || ($patch !== [] && array_is_list($patch))) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        foreach (array_keys($patch) as $fieldname) {
            if (!in_array($fieldname, self::PATCHABLE_FIELDS, true)) {
                throw new \invalid_parameter_exception(
                    'Unknown field "' . $fieldname . '" in fields_json. Allowed: '
                    . implode(', ', self::PATCHABLE_FIELDS) . '.'
                );
            }
        }

        return $patch;
    }

    /**
     * Overwrites only the properties named in the patch on the NATIVE
     * question object (see {@see export_questions_xml::resolve_native_question()})
     * - everything else stays exactly preserved (core test of this ticket)
     * because it is never touched. Afterwards validates the (patched or
     * unchanged) answers/selection mode state with the same rules as a new
     * creation ({@see create_mc_question::validate_answers()}).
     * @param \stdClass $question Is modified in place.
     * @param mixed[] $patch
     * @return void
     */
    private static function apply_patch(\stdClass $question, array $patch): void {
        if (array_key_exists('name', $patch)) {
            $question->name = (string) $patch['name'];
        }
        if (array_key_exists('questiontext', $patch)) {
            $question->questiontext = (string) $patch['questiontext'];
        }
        if (array_key_exists('generalfeedback', $patch)) {
            $question->generalfeedback = (string) $patch['generalfeedback'];
        }
        if (array_key_exists('defaultmark', $patch)) {
            $question->defaultmark = (float) $patch['defaultmark'];
        }
        if (array_key_exists('selectionmode', $patch)) {
            $mode = (string) $patch['selectionmode'];
            if (!in_array($mode, ['single', 'multiple'], true)) {
                throw new \invalid_parameter_exception('selectionmode must be single or multiple.');
            }
            $question->options->single = $mode === 'single' ? 1 : 0;
        }
        if (array_key_exists('answers', $patch)) {
            $question->options->answers = self::build_answer_objects($patch['answers']);
        }

        // Validate only if answers/selectionmode ACTUALLY appear in the patch:
        // a foreign question found whose answers do not meet Coursepilot's
        // stricter creation rules (validate_answers) may still be patched in
        // other fields - otherwise a pure questiontext patch would fail on
        // exactly the untouched answer structure this ticket is meant to
        // preserve.
        if (array_key_exists('answers', $patch) || array_key_exists('selectionmode', $patch)) {
            $answersforvalidation = array_map(static fn(\stdClass $a): array => [
                'answer' => $a->answer,
                'fraction' => $a->fraction,
                'feedback' => $a->feedback,
            ], array_values((array) $question->options->answers));
            $selectionmode = empty($question->options->single) ? 'multiple' : 'single';
            create_mc_question::validate_answers($answersforvalidation, $selectionmode);
        }
    }

    /**
     * Builds the object form per answer expected by {@see \qformat_xml::write_answer()}
     * (answer/answerformat/fraction/feedback/feedbackformat/id -
     * id only used for file lookups, negative placeholder ids never
     * collide with real DB ids) from the raw patch data.
     *
     * @param mixed $answers
     * @return \stdClass[]
     */
    private static function build_answer_objects($answers): array {
        if (!is_array($answers) || $answers === []) {
            throw new \invalid_parameter_exception('"answers" must be a non-empty list of answer options.');
        }
        $objects = [];
        foreach (array_values($answers) as $i => $answer) {
            if (!is_array($answer) || !array_key_exists('answer', $answer) || !array_key_exists('fraction', $answer)) {
                throw new \invalid_parameter_exception(
                    'Every answer option in "answers" needs "answer" and "fraction".'
                );
            }
            $object = new \stdClass();
            $object->id = -($i + 1);
            $object->answer = (string) $answer['answer'];
            $object->answerformat = FORMAT_HTML;
            $object->fraction = (float) $answer['fraction'];
            $object->feedback = isset($answer['feedback']) ? (string) $answer['feedback'] : '';
            $object->feedbackformat = FORMAT_HTML;
            $objects[] = $object;
        }
        return $objects;
    }

    /**
     * Reads "feedback_images" per answer option from the raw (not yet
     * converted to native objects) "answers" patch (issue #435) - the index
     * in the returned array corresponds to the position in the answers list,
     * which {@see self::build_answer_objects()} writes onto the native
     * question object in the same order and which therefore also yields the
     * newly written question_answers rows in this order (see
     * {@see self::embed_images()}).
     *
     * @param mixed[] $patch
     * @return array<int, string[]> Index => list of material folder paths
     */
    private static function extract_answer_feedback_images(array $patch): array {
        $result = [];
        foreach (array_values($patch['answers'] ?? []) as $i => $answer) {
            if (is_array($answer) && !empty($answer['feedback_images'])) {
                if (!is_array($answer['feedback_images'])) {
                    throw new \invalid_parameter_exception('"feedback_images" must be a list of material folder paths.');
                }
                $result[$i] = $answer['feedback_images'];
            }
        }
        return $result;
    }

    /**
     * Checks permission + embed whitelist and resolves each requested image
     * list into a file manager draft BEFORE the write transaction (issue
     * #435) - all-or-nothing like every other patch of this plugin (cf.
     * update_module_settings::validate_patch()): a wrong extension or a
     * missing material image must not create a new question version that is
     * then only partially embedded.
     * material_files::resolve_into_draft() throws materialfilenotfound right
     * here if a referenced file does not exist - the 4th parameter (target
     * itemid) is irrelevant at this point because the target row
     * (question/answer) does not exist yet; it only serves to pre-populate
     * files ALREADY attached to this itemid, which is empty anyway for a
     * future question/answer id.
     *
     * @param \context $context Category context (target of the file storage).
     * @param string[] $questiontextimages Material folder paths for questiontext.
     * @param array $answerfeedbackimages Answer index => material folder paths. Type: array<int,string[]>.
     * @param string $location {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH} -
     *        source of the paths (issue #496).
     * @return array{0: int|null, 1: array<int, int>} [draft itemid for questiontext (null without request),
     *         answer index => draft itemid for answerfeedback]
     * @throws moodle_exception materialfiledisallowedtype / materialfilenotfound / invalidmaterialpath /
     *         invalidmateriallocation / materialpathiscontext / materialembedtoolarge
     * @throws \required_capability_exception without moodle/user:manageownfiles
     */
    private static function prepare_image_drafts(
        \context $context,
        array $questiontextimages,
        array $answerfeedbackimages,
        string $location
    ): array {
        if (empty($questiontextimages) && empty($answerfeedbackimages)) {
            return [null, []];
        }

        material_files::require_manage_own_files();
        self::assert_allowed_embed_extensions($questiontextimages);
        foreach ($answerfeedbackimages as $images) {
            self::assert_allowed_embed_extensions($images);
        }

        $questiontextdraftitemid = empty($questiontextimages)
            ? null
            : material_files::resolve_into_draft($context->id, 'question', 'questiontext', 0, $questiontextimages, $location);

        $answerfeedbackdraftitemids = [];
        foreach ($answerfeedbackimages as $index => $images) {
            $answerfeedbackdraftitemids[$index] =
                material_files::resolve_into_draft($context->id, 'question', 'answerfeedback', 0, $images, $location);
        }

        return [$questiontextdraftitemid, $answerfeedbackdraftitemids];
    }

    /**
     * Asserts allowed embed extensions.
     *
     * @param string[] $paths
     * @return void
     * @throws moodle_exception materialfiledisallowedtype
     */
    private static function assert_allowed_embed_extensions(array $paths): void {
        foreach ($paths as $path) {
            if (!is_string($path) || !material_files::is_allowed_embed_image_extension($path)) {
                throw new moodle_exception('materialfiledisallowedtype', 'local_coursepilot', '', (object) [
                    'filename' => (string) $path,
                    'allowed' => implode(', ', material_files::allowed_embed_image_extensions()),
                ]);
            }
        }
    }

    /**
     * Writes the file drafts already validated and resolved in
     * {@see self::prepare_image_drafts()} into the target file areas of the
     * new version JUST written (issue #435, Spec 0018 §4/§7) - only possible
     * AFTER writing, because question/questiontext and
     * question/answerfeedback are addressed by Moodle convention via the
     * question/answer id, which does not exist before
     * import_questions_xml::execute(). The caller has already sent the text
     * (with "@@PLUGINFILE@@/<filename>" plus alt text) in the
     * questiontext/feedback patch - file_save_draft_area_files() only
     * resolves the placeholder against the real pluginfile URL, exactly the
     * mechanism question_type::save_question() uses for
     * $form->questiontext['itemid'] (question/type/questiontypebase.php).
     * @param \context $context
     * @param int $questionid New question.id of the written version.
     * @param int|null $questiontextdraftitemid
     * @param int[] $answerfeedbackdraftitemids Answer index => draft itemid.
     * @return void
     */
    private static function embed_images(
        \context $context,
        int $questionid,
        ?int $questiontextdraftitemid,
        array $answerfeedbackdraftitemids
    ): void {
        global $DB;

        $fileoptions = ['subdirs' => true, 'maxfiles' => -1, 'maxbytes' => 0];

        if ($questiontextdraftitemid !== null) {
            $current = $DB->get_field('question', 'questiontext', ['id' => $questionid], MUST_EXIST);
            $new = file_save_draft_area_files(
                $questiontextdraftitemid,
                $context->id,
                'question',
                'questiontext',
                $questionid,
                $fileoptions,
                $current
            );
            file_clear_draft_area($questiontextdraftitemid);
            $DB->set_field('question', 'questiontext', $new, ['id' => $questionid]);
        }

        if (!empty($answerfeedbackdraftitemids)) {
            $answers = array_values($DB->get_records('question_answers', ['question' => $questionid], 'id ASC'));
            foreach ($answerfeedbackdraftitemids as $index => $draftitemid) {
                if (!isset($answers[$index])) {
                    throw new \invalid_parameter_exception(
                        'feedback_images refers to answer option ' . $index . ', but "answers" has only '
                        . count($answers) . ' entries.'
                    );
                }
                $answer = $answers[$index];
                $new = file_save_draft_area_files(
                    $draftitemid,
                    $context->id,
                    'question',
                    'answerfeedback',
                    (int) $answer->id,
                    $fileoptions,
                    (string) $answer->feedback
                );
                file_clear_draft_area($draftitemid);
                $DB->set_field('question_answers', 'feedback', $new, ['id' => $answer->id]);
            }
        }
    }

    /**
     * Generates a new, unique idnumber (same scheme as import_questions_xml).
     */
    private static function generate_idnumber(): string {
        return 'kp-' . bin2hex(random_bytes(8));
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge(
            [
                'name' => new external_value(PARAM_TEXT, 'Name of the question'),
                'questionid' => new external_value(PARAM_INT, 'ID of the new question row (0 for "suspect")'),
                'questionbankentryid' => new external_value(
                    PARAM_INT,
                    'ID of the question_bank_entries row (question identity, unchanged; 0 for "suspect")'
                ),
                'version' => new external_value(PARAM_INT, 'New version number (0 for "suspect")'),
                'status' => new external_value(PARAM_ALPHA, '"updated" (updated) | "suspect" (suspect case)'),
                'idnumber_added' => new external_value(
                    PARAM_BOOL,
                    'true if this question previously had no idnumber and was assigned exactly one on write'
                ),
                'message' => new external_value(PARAM_RAW, 'Teacher-facing message with bank entry and version'),
            ],
            question_suspect_gate::response_fields()
        ));
    }
}
