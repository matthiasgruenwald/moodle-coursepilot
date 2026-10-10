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

namespace local_coursepilot\external;

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_question\local\bank\question_version_status;
use local_coursepilot\material_files;
use local_coursepilot\question_suspect_gate;
use qformat_xml;
use question_bank;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * XML core (Spec 0017 §7.1, #415): import arbitrary Moodle XML question
 * types while preserving versions. Teachers learn from the server whether
 * it works before using the questions in class.
 *
 * Parse through qformat_xml::readquestions(), the public parse-only API.
 * A parse error aborts the whole call; no partial result. Write through
 * question_type::save_question() with question->id for a recognized bank
 * entry, creating a new version under its existing questionbankentryid
 * (ADR 0001). Do not use importprocess(): it always creates a bank entry
 * and cannot match existing entries.
 *
 * Round-trip within the same transaction: export the freshly written
 * question with qformat_xml::writequestion(), then reparse with readquestions().
 * The same importer produces the same object shape on both sides, allowing
 * generic comparison without per-type code. Compare core fields: name,
 * idnumber, question text, answer options with fractions and feedback, and
 * general feedback. Do not require byte equality; Moodle normalizes IDs,
 * ordering and paths. Every mismatch or exception rolls back the whole
 * transaction, leaving neither bank entry nor version. The transaction is
 * explicitly rolled back on exceptions; see import_all().
 *
 * Recognize identities only within the target category by idnumber (ADR 0015).
 * An incoming idnumber without a match is a suspect case, using the common
 * question_suspect_gate response from move_question/T3. Its collision is
 * reversed: move_question sees an assigned idnumber, while this tool sees
 * an unmatched one. Write nothing until a repeat call confirms it with
 * confirmed:true. An absent idnumber means a genuine first import: generate
 * a new identity without a gate.
 *
 * Two doors for embedded files (Spec 0018 §7.1, #436), replacing Spec 0017 §6's
 * rejection of embedded <file> blocks:
 * - Text door (xmlcontent): AI-written XML names material="<material-folder-path>"
 *   rather than real base64 in each <file> block. resolve_material_file_references()
 *   resolves these references to base64 server-side before parsing.
 * - Reference door (xmlpath): a material-folder XML file, e.g. a bulk foreign
 *   export with real base64. read_material_binary() reads it server-side;
 *   no bytes enter the AI context. Exactly one door is allowed per call.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class import_questions_xml extends external_api {
    /**
     * @var int Size limit per import (#424 follow-up 2), applied to resolved XML.
     *      See {@see self::guard_server_size_limit()} for the rationale.
     */
    public const MAX_XML_BYTES = 5 * 1024 * 1024;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'ID of the target question bank category'),
            'xmlcontent' => new external_value(
                PARAM_RAW,
                'Moodle question XML export as text (text door) - <file> blocks carry a '
                    . 'material="<material-folder-path>" attribute instead of real base64, the server resolves it '
                    . 'server-side. Give exactly one of xmlcontent/xmlpath.',
                VALUE_DEFAULT,
                ''
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms a previously reported suspect case (an idnumber that came with no match '
                    . 'in the target category) and creates the question anyway as a new entry. Omit or false on '
                    . 'the first call.',
                VALUE_DEFAULT,
                false
            ),
            'xmlpath' => new external_value(
                PARAM_PATH,
                'Reference to an XML file in the material folder (reference door, bulk import of a foreign export '
                    . 'with real base64 in <file> blocks) - e.g. "export.xml". Give exactly one of xmlcontent/xmlpath.',
                VALUE_DEFAULT,
                ''
            ),
            'location' => material_files::location_parameter(),
        ]);
    }

    /**
     * @param int $categoryid
     * @param string $xmlcontent
     * @param bool $confirmed
     * @param string $xmlpath
     * @param string $location
     * @return array
     */
    public static function execute(
        int $categoryid,
        string $xmlcontent = '',
        bool $confirmed = false,
        string $xmlpath = '',
        string $location = material_files::LOCATION_STORE
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'xmlcontent' => $xmlcontent,
            'confirmed' => $confirmed,
            'xmlpath' => $xmlpath,
            'location' => $location,
        ]);

        [$category, $context, $questions] = self::resolve_and_parse($params);

        return ['questions' => self::import_all($category, $context, $questions, $params['confirmed'])];
    }

    /**
     * Check context/capabilities, resolve the XML and parse questions (#523:
     * extracted from execute() to keep the function below 50 lines).
     *
     * @param array $params Validated execute() parameters.
     * @return array{0: \stdClass, 1: \context, 2: array}
     */
    private static function resolve_and_parse(array $params): array {
        global $DB;

        $category = $DB->get_record('question_categories', ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        $xml = self::resolve_door($params['xmlcontent'], $params['xmlpath'], $params['location']);

        // Size guard (Spec 0017 "Images and sizes", #416) BEFORE parsing/writing.
        // Embedded files are allowed since Spec 0018 §7.1; both doors are resolved above.
        self::guard_server_size_limit($xml);
        self::guard_quiz_root($xml);

        // Parse without database writes. Invalid XML throws here BEFORE any
        // write, so no partial result is possible.
        $questions = self::parse($category, $context, $xml);

        return [$category, $context, $questions];
    }

    /**
     * Import all parsed questions in one transaction (#523: extracted from execute()).
     *
     * @param \stdClass $category
     * @param \context $context
     * @param array $questions
     * @param bool $confirmed
     * @return array
     */
    private static function import_all(\stdClass $category, \context $context, array $questions, bool $confirmed): array {
        global $DB;

        // moodle_transaction has no destructor. Explicitly roll back here because
        // round-trip mismatches intentionally occur AFTER the write rather than
        // in its preceding validation.
        $transaction = $DB->start_delegated_transaction();

        try {
            $results = [];
            foreach ($questions as $question) {
                $results[] = self::import_one($category, $context, $question, $confirmed);
            }
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $transaction->allow_commit();

        return $results;
    }

    /**
     * Reject XML exceeding this endpoint's size limit.
     *
     * The original rationale (#416) referred to post_max_size: PHP supplies
     * empty fields rather than a clean failure when that is exceeded. But then
     * there is no content to inspect and this guard is never reached. Comparing
     * against get_max_upload_file_size() (200 MB, post_max_size 206 MB) therefore
     * practically never fired (#424 follow-up 2).
     *
     * Use a deliberate domain limit on RESOLVED XML, after resolving material
     * references for both doors (Spec 0018 §7.1). Each question needs its own
     * round-trip; beyond a few MB execution time becomes the constraint, not
     * upload size. Retain the server limit as an additional ceiling if lower.
     *
     * @param string $xmlcontent
     * @return void
     */
    private static function guard_server_size_limit(string $xmlcontent): void {
        // Moodle's get_max_upload_file_size() (lib/moodlelib.php) reads post_max_size
        // and upload_max_filesize and returns the smaller byte limit.
        $serverlimit = get_max_upload_file_size();
        $limit = $serverlimit > 0 ? min(self::MAX_XML_BYTES, $serverlimit) : self::MAX_XML_BYTES;

        self::guard_size_against_limit(strlen($xmlcontent), $limit);
    }

    /**
     * Testable core of guard_server_size_limit(). The caller supplies maxbytes
     * so tests can set a threshold without creating a 5 MB string.
     *
     * @param int $bytes
     * @param int $maxbytes
     * @return void
     */
    private static function guard_size_against_limit(int $bytes, int $maxbytes): void {
        if ($maxbytes <= 0 || $bytes <= $maxbytes) {
            return;
        }

        throw new \invalid_parameter_exception(
            'The XML is too large (' . display_size($bytes) . ', limit ' . display_size($maxbytes)
                . '). Split the import across smaller files - e.g. one file per '
                . 'question category.'
        );
    }

    /**
     * Choose one of the two doors (Spec 0018 §7.1) and return fully resolved
     * XML. Exactly one input is allowed; neither takes silent precedence.
     *
     * @param string $xmlcontent Text-door input (empty when unused)
     * @param string $xmlpath Reference-door input (empty when unused)
     * @param string $location {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH} -
     *        Source of material paths for both doors (#496).
     * @return string
     * @throws \invalid_parameter_exception neither or both inputs supplied
     */
    private static function resolve_door(string $xmlcontent, string $xmlpath, string $location): string {
        $xmlcontent = trim($xmlcontent);
        $xmlpath = trim($xmlpath);

        if ($xmlcontent !== '' && $xmlpath !== '') {
            throw new \invalid_parameter_exception(
                'xmlcontent and xmlpath cannot both be supplied - choose exactly one input: '
                    . 'XML as text (xmlcontent) or a reference to an XML file in the material store (xmlpath).'
            );
        }
        if ($xmlcontent === '' && $xmlpath === '') {
            throw new \invalid_parameter_exception(
                'Neither xmlcontent nor xmlpath supplied - choose exactly one input: XML as text (xmlcontent) or '
                    . 'a reference to an XML file in the material store (xmlpath).'
            );
        }

        if ($xmlpath !== '') {
            // Reference door: an XML file already in the material folder, e.g. a
            // foreign Moodle export with actual base64 in <file> blocks. Read only
            // server-side; no bytes enter the AI context.
            return self::read_material_binary($xmlpath, $location);
        }

        // Text door: AI-written XML uses material-folder references in <file>
        // blocks instead of real base64.
        return self::resolve_material_file_references($xmlcontent, $location);
    }

    /**
     * Resolve each <file> block with material="<material-folder-path>" to real
     * base64 server-side (text door, Spec 0018 §7.1). The AI supplies the name;
     * this endpoint builds the base64 block. Leave blocks without that attribute unchanged.
     *
     * @param string $xmlcontent
     * @param string $location {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH} -
     *        Source of referenced paths (#496).
     * @return string
     * @throws \moodle_exception materialfilenotfound, if a referenced file is missing
     */
    private static function resolve_material_file_references(string $xmlcontent, string $location): string {
        $resolved = preg_replace_callback(
            '/<file\b([^>]*)>(.*?)<\/file>/s',
            static function (array $matches) use ($location): string {
                $attributes = $matches[1];
                if (!preg_match('/\bmaterial=(["\'])(.*?)\1/', $attributes, $materialmatch)) {
                    // No material-folder reference: keep the block unchanged, e.g. it already
                    // contains real base64.
                    return $matches[0];
                }

                $materialpath = html_entity_decode($materialmatch[2], ENT_QUOTES | ENT_XML1);
                $base64 = base64_encode(self::read_material_binary($materialpath, $location));

                $cleanattributes = trim(preg_replace(
                    ['/\bmaterial=(["\']).*?\1/', '/\bencoding=(["\']).*?\1/'],
                    '',
                    $attributes
                ));

                return '<file ' . $cleanattributes . ' encoding="base64">' . $base64 . '</file>';
            },
            $xmlcontent
        );

        return $resolved ?? $xmlcontent;
    }

    /**
     * Read a signed-in user's complete material file bytes. Used by both
     * doors: the XML itself for the reference door and each referenced file
     * for the text door.
     *
     * @param string $path Material path, e.g. "export.xml" or "diagrams/sketch.png".
     * @param string $location {@see material_files::LOCATION_STORE}/{@see material_files::LOCATION_WORKBENCH} (Issue #496).
     * @return string
     * @throws \moodle_exception materialfilenotfound / invalidmateriallocation / materialpathiscontext
     */
    private static function read_material_binary(string $path, string $location = material_files::LOCATION_STORE): string {
        $stored = material_files::read_content_for_location($location, $path);
        if ($stored === null) {
            throw new \moodle_exception(
                'materialfilenotfound',
                'local_coursepilot',
                '',
                material_files::normalise_path($path)
            );
        }

        return $stored['content'];
    }

    /**
     * Reject XML missing a <quiz> root with an actionable explanation.
     *
     * qformat_xml::readquestions() accesses xml['quiz'] without checking it,
     * then fails with PHP details ("Undefined array key quiz", "Cannot access
     * offset of type string on string"). These do not help a teacher. A manually
     * shortened example often contains only a <question> block (#425 F2,
     * #424 follow-up 1), so the case is common.
     *
     * @param string $xmlcontent
     * @return void
     */
    private static function guard_quiz_root(string $xmlcontent): void {
        if (preg_match('/<quiz[\s>]/i', $xmlcontent)) {
            return;
        }

        throw new \invalid_parameter_exception(
            'The XML lacks the enclosing <quiz> element. Moodle question XML always consists of <quiz> with one '
                . 'or more <question> blocks inside it - a single <question> block cannot be '
                . 'imported. Send the complete Moodle export or wrap the questions in <quiz>...</quiz> '
                . 'instead.'
        );
    }

    /**
     * Translate caught parse exceptions into useful teacher-facing text
     * (#424 follow-up 1).
     *
     * A moodle_exception already describes the file, e.g. xmlize's format
     * error, and passes through. Other exceptions expose XML-core PHP details
     * without actionable information; replace them with the most common cause.
     *
     * @param \Throwable $e
     * @return string
     */
    private static function parse_failure_message(\Throwable $e): string {
        if ($e instanceof \moodle_exception) {
            return $e->getMessage();
        }

        return 'Invalid Moodle XML: The file could not be read as Moodle question XML. Common '
            . 'causes: the file is incomplete or truncated, an element is not closed, or '
            . 'the structure differs from a Moodle export. Send a complete, unmodified export.';
    }

    /**
     * Parse XML read-only through qformat_xml::readquestions(). Throw on every
     * parse problem, aborting the whole call.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param string $xmlcontent
     * @return \stdClass[]
     */
    private static function parse(\stdClass $category, \context $context, string $xmlcontent): array {
        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setStoponerror(true);
        $qformat->setMatchgrades('grade');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $xmlcontent));

        // qformat_xml::readquestions() does NOT throw for parse errors. It echoes
        // a message via qformat_default::error() and returns false. Capture that
        // output to avoid HTML in the web service response, then throw instead.
        ob_start();
        try {
            $questions = $qformat->readquestions($lines);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw new \invalid_parameter_exception(self::parse_failure_message($e));
        }
        $errortext = trim(strip_tags((string) ob_get_clean()));

        if ($questions === false || !is_array($questions) || $qformat->importerrors > 0) {
            throw new \invalid_parameter_exception('Invalid Moodle XML' . ($errortext !== '' ? ': ' . $errortext : '.'));
        }

        // Category directives ($CATEGORY:) are not questions. This endpoint writes
        // only to the supplied categoryid.
        $questions = array_values(array_filter((array) $questions, static function ($question) {
            return !isset($question->qtype) || $question->qtype !== 'category';
        }));

        if (empty($questions)) {
            throw new \invalid_parameter_exception('The XML contains no importable questions.');
        }

        return $questions;
    }

    /**
     * Recognize, write and round-trip-check one parsed question.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $question
     * @param bool $confirmed
     * @return array
     */
    private static function import_one(
        \stdClass $category,
        \context $context,
        \stdClass $question,
        bool $confirmed
    ): array {
        global $DB;

        $name = (string) ($question->name ?? '');
        $xmlidnumber = trim((string) ($question->idnumber ?? ''));

        if ($xmlidnumber === '') {
            // Genuine first import: no idnumber in the XML and no gate.
            $idnumber = self::generate_idnumber();
            $saved = self::save($category, $context, $question, null, $idnumber);
            self::verify_roundtrip($category, $context, $question, $saved, $idnumber);
            return self::result($saved, 'first_import', $name);
        }

        $entry = $DB->get_record('question_bank_entries', [
            'questioncategoryid' => $category->id,
            'idnumber' => $xmlidnumber,
        ]);

        if ($entry) {
            // Unique idnumber match: create a new version of the same entry.
            $latest = question_suspect_gate::latest_version_question((int) $entry->id);
            $saved = self::save($category, $context, $question, (int) $latest->id, $xmlidnumber);
            self::verify_roundtrip($category, $context, $question, $saved, $xmlidnumber);
            return self::result($saved, 'reimport', $name);
        }

        if (!$confirmed) {
            return self::unmatched_idnumber_response($category, $question, $name, $xmlidnumber);
        }

        // Confirmed suspect case: create a new entry with the supplied idnumber.
        $saved = self::save($category, $context, $question, null, $xmlidnumber);
        self::verify_roundtrip($category, $context, $question, $saved, $xmlidnumber);
        return self::result($saved, 'first_import', $name);
    }

    /**
     * Suspect response for an incoming idnumber without a target-category
     * match. Write nothing (ADR 0015, Spec 0017 §7.1). Extracted from
     * import_one() in #523 to keep the function below 50 lines.
     *
     * @param \stdClass $category
     * @param \stdClass $question
     * @param string $name
     * @param string $xmlidnumber
     * @return array
     */
    private static function unmatched_idnumber_response(
        \stdClass $category,
        \stdClass $question,
        string $name,
        string $xmlidnumber
    ): array {
        $candidates = question_suspect_gate::find_name_candidates((int) $category->id, $name);
        $newquestiontext = self::text_of($question->questiontext ?? '');

        return array_merge(
            [
                'name' => $name,
                'questionbankentryid' => 0,
                'version' => 0,
                'status' => 'suspect',
                'message' => get_string('questionimportsuspect', 'local_coursepilot', $xmlidnumber),
            ],
            [
                'idnumber' => $xmlidnumber,
                'categoryid' => (int) $category->id,
                'candidates' => $candidates,
                'questiontext_old' => '',
                'questiontext_new' => $newquestiontext,
            ]
        );
    }

    /**
     * Call question_type::save_question() with question->id for a new version
     * of an existing entry, or without it for a new entry.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $question
     * @param int|null $oldquestionid
     * @param string $idnumber
     * @return \stdClass
     */
    private static function save(
        \stdClass $category,
        \context $context,
        \stdClass $question,
        ?int $oldquestionid,
        string $idnumber
    ): \stdClass {
        $form = clone $question;
        $form->category = $category->id . ',' . $context->id;
        $form->status = question_version_status::QUESTION_STATUS_READY;
        $form->idnumber = $idnumber;
        // qformat_xml::readquestions() creates draft files for embedded <file>
        // blocks (question/format/xml/format.php: import_files_as_draft()) and
        // attaches questiontextitemid/generalfeedbackitemid separately rather than
        // inside the text fields. Pass these item IDs through; otherwise
        // save_question()->file_save_draft_area_files() is never called and images
        // from BOTH doors are silently discarded (Spec 0018 §7.1, #437).
        $form->questiontext = self::as_text_array(
            $question->questiontext ?? '',
            $question->questiontextformat ?? FORMAT_HTML,
            $question->questiontextitemid ?? 0
        );
        $form->generalfeedback = self::as_text_array(
            $question->generalfeedback ?? '',
            $question->generalfeedbackformat ?? FORMAT_HTML,
            $question->generalfeedbackitemid ?? 0
        );
        if (!isset($form->defaultmark)) {
            // Moodle XML exports historically use <defaultgrade>.
            $form->defaultmark = $question->defaultgrade ?? 1.0;
        }
        if (!isset($form->penalty)) {
            $form->penalty = 0.0;
        }

        $towrite = new \stdClass();
        $towrite->qtype = $question->qtype;
        if ($oldquestionid !== null) {
            $towrite->id = $oldquestionid;
        }

        $qtype = question_bank::get_qtype($question->qtype);
        try {
            return $qtype->save_question($towrite, $form);
        } catch (\Throwable $e) {
            throw new \invalid_parameter_exception(self::save_failure_message($question->qtype, $e));
        }
    }

    /**
     * Translate caught save exceptions into useful teacher-facing text (#440).
     *
     * For some types, question_type::save_question() expects a prepared structure,
     * not qformat_xml::readquestions()' raw result. For calculated questions,
     * form->dataset must contain composite string keys rather than parsed
     * dataset_definitions objects. This generic save() path does not prepare
     * every type separately. On failure PHP otherwise reports only a TypeError
     * without an explanation of the domain problem.
     *
     * @param string $qtype
     * @param \Throwable $e
     * @return string
     */
    private static function save_failure_message(string $qtype, \Throwable $e): string {
        if ($e instanceof \moodle_exception) {
            return $e->getMessage();
        }

        return 'Question type "' . $qtype . '" could not be saved with this XML structure. Common cause: '
            . 'a question-type-specific structure (e.g. dataset definitions for "calculated") differs from the '
            . 'internal form this question type expects when saving. Check the question type reference '
            . 'or simplify the structure.';
    }

    /**
     * Round-trip check (Spec 0017 §7.1): export the newly written question
     * through qformat_xml and reparse it with the same importer. Both sides
     * therefore have the same object shape. A mismatch throws and rolls back
     * the enclosing transaction.
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $original Parsed input question
     * @param \stdClass $saved Result of question_type::save_question()
     * @param string $expectedidnumber idnumber assigned to this write
     * @return void
     */
    private static function verify_roundtrip(
        \stdClass $category,
        \context $context,
        \stdClass $original,
        \stdClass $saved,
        string $expectedidnumber
    ): void {
        $wrapped = self::rewrite_saved_question($category, $context, $saved);
        $reparsedquestion = self::reparse($category, $context, $wrapped);

        $mismatch = self::find_mismatch($original, $reparsedquestion, $expectedidnumber);
        if ($mismatch !== null) {
            throw self::roundtrip_exception($mismatch);
        }
    }

    /**
     * Reload the saved question and export it with qformat_xml (#523:
     * extracted from verify_roundtrip() to keep the function below 50 lines).
     *
     * @param \stdClass $category
     * @param \context $context
     * @param \stdClass $saved
     * @return string XML wrapped in a <quiz> root.
     */
    private static function rewrite_saved_question(\stdClass $category, \context $context, \stdClass $saved): string {
        global $DB;

        $reloaded = $DB->get_record('question', ['id' => $saved->id], '*', MUST_EXIST);
        $reloaded->export_process = true;
        $reloaded->categoryobject = $category;

        $qtype = question_bank::get_qtype($reloaded->qtype);
        $qtype->get_question_options($reloaded);

        $reloaded->contextid = (int) $context->id;
        $entry = get_question_bank_entry((int) $reloaded->id);
        $reloaded->idnumber = $entry->idnumber;

        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);

        $xml = $qformat->writequestion($reloaded);

        // writequestion() returns only a <question> block, but readquestions()
        // expects a <quiz> root (xmlize's xml["quiz"] structure).
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . $xml . "\n</quiz>";
    }

    /**
     * Reparse the exported XML and return its one question (#523: extracted
     * from verify_roundtrip()).
     *
     * @param \stdClass $category
     * @param \context $context
     * @param string $wrapped
     * @return \stdClass
     */
    private static function reparse(\stdClass $category, \context $context, string $wrapped): \stdClass {
        $reparser = new qformat_xml();
        $reparser->setCategory($category);
        $reparser->setContexts([$context]);
        $reparser->setStoponerror(true);
        $reparser->setMatchgrades('grade');
        $reparser->setCatfromfile(false);
        $reparser->setContextfromfile(false);

        ob_start();
        try {
            $reparsed = $reparser->readquestions(explode("\n", $wrapped));
        } catch (\Throwable $e) {
            ob_end_clean();
            throw self::roundtrip_exception('parse', $e->getMessage());
        }
        $errortext = trim(strip_tags((string) ob_get_clean()));

        if ($reparsed === false || !is_array($reparsed) || $reparser->importerrors > 0) {
            throw self::roundtrip_exception('parse', $errortext !== '' ? $errortext : 'Parse error');
        }
        $reparsedquestion = reset($reparsed);
        if (!$reparsedquestion) {
            throw self::roundtrip_exception('parse', 'no question in the reparsed XML');
        }

        return $reparsedquestion;
    }

    /**
     * Build a moodle_exception for a failed round-trip check.
     *
     * @param string $field Mismatched field name ("parse" for a reparse error)
     * @param string $detail Additional detail, empty when absent
     * @return \moodle_exception
     */
    private static function roundtrip_exception(string $field, string $detail = ''): \moodle_exception {
        return new \moodle_exception(
            'roundtripmismatch',
            'local_coursepilot',
            '',
            (object) ['field' => $field, 'detail' => $detail !== '' ? ' (' . $detail . ')' : '']
        );
    }

    /**
     * Compare core fields of the input and reparsed question (Spec 0017 §7.1):
     * name, idnumber, question text, general feedback, answer options with
     * fractions and per-option feedback. Exclude byte equality: Moodle
     * normalizes IDs, ordering and file paths.
     *
     * @param \stdClass $expected
     * @param \stdClass $actual
     * @param string $expectedidnumber
     * @return string|null Mismatched field name, or null when equal
     */
    private static function find_mismatch(\stdClass $expected, \stdClass $actual, string $expectedidnumber): ?string {
        if (trim((string) ($expected->name ?? '')) !== trim((string) ($actual->name ?? ''))) {
            return 'name';
        }
        if ($expectedidnumber !== trim((string) ($actual->idnumber ?? ''))) {
            return 'idnumber';
        }
        if (self::text_of($expected->questiontext ?? '') !== self::text_of($actual->questiontext ?? '')) {
            return 'questiontext';
        }
        if (self::text_of($expected->generalfeedback ?? '') !== self::text_of($actual->generalfeedback ?? '')) {
            return 'generalfeedback';
        }

        $expectedanswers = self::extract_answer_list($expected);
        $actualanswers = self::extract_answer_list($actual);
        if (count($expectedanswers) !== count($actualanswers)) {
            return 'answers';
        }
        foreach ($expectedanswers as $i => $answer) {
            $other = $actualanswers[$i];
            if (
                $answer['text'] !== $other['text']
                || abs($answer['fraction'] - $other['fraction']) > 0.00001
                || $answer['feedback'] !== $other['feedback']
            ) {
                return 'answers';
            }
        }

        return null;
    }

    /**
     * Normalize answer options (text, fraction, feedback) regardless of
     * question type. Handles the parallel arrays used by multichoice,
     * shortanswer, numerical, etc., and the special truefalse shape. Both sides
     * of the round-trip use the same qformat_xml importer and thus the same shape.
     *
     * @param \stdClass $qo
     * @return array<int, array{text: string, fraction: float, feedback: string}>
     */
    private static function extract_answer_list(\stdClass $qo): array {
        if (isset($qo->answer) && is_array($qo->answer)) {
            $list = [];
            foreach ($qo->answer as $i => $answer) {
                $list[] = [
                    'text' => self::text_of($answer),
                    'fraction' => round((float) ($qo->fraction[$i] ?? 0), 5),
                    'feedback' => self::text_of($qo->feedback[$i] ?? ''),
                ];
            }
            return $list;
        }

        if (isset($qo->answer) && is_bool($qo->answer)) {
            // truefalse uses a single boolean (true means the correct answer is true)
            // with separate feedback for each option.
            return [
                [
                    'text' => 'true',
                    'fraction' => $qo->answer ? 1.0 : 0.0,
                    'feedback' => self::text_of($qo->feedbacktrue ?? ''),
                ],
                [
                    'text' => 'false',
                    'fraction' => $qo->answer ? 0.0 : 1.0,
                    'feedback' => self::text_of($qo->feedbackfalse ?? ''),
                ],
            ];
        }

        return [];
    }

    /**
     * @param \stdClass $saved
     * @param string $status
     * @param string $name
     * @return array
     */
    private static function result(\stdClass $saved, string $status, string $name): array {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $saved->id], '*', MUST_EXIST);
        $message = $status === 'first_import'
            ? get_string('questionimportcreated', 'local_coursepilot', (object) ['name' => $name, 'version' => $version->version])
            : get_string('questionimportversion', 'local_coursepilot', (object) ['name' => $name, 'version' => $version->version]);

        return array_merge(
            [
                'name' => $name,
                'questionbankentryid' => (int) $version->questionbankentryid,
                'version' => (int) $version->version,
                'status' => $status,
                'message' => $message,
            ],
            question_suspect_gate::empty_result()
        );
    }

    /** Extract plain text from a qformat field (string or text-keyed array). */
    private static function text_of($value): string {
        if (is_array($value)) {
            return (string) ($value['text'] ?? '');
        }
        if (is_object($value) && isset($value->text)) {
            return (string) $value->text;
        }
        return (string) $value;
    }

    /** Build the text/format/itemid structure expected by save_question(). */
    private static function as_text_array($value, $format, int $itemid = 0): array {
        if (is_array($value) && array_key_exists('text', $value)) {
            return [
                'text' => (string) $value['text'],
                'format' => $value['format'] ?? $format,
                'itemid' => $value['itemid'] ?? $itemid,
            ];
        }
        return ['text' => self::text_of($value), 'format' => $format ?? FORMAT_HTML, 'itemid' => $itemid];
    }

    /** Generate a unique idnumber, following mc_question_version. */
    private static function generate_idnumber(): string {
        return 'kp-' . bin2hex(random_bytes(8));
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => new external_multiple_structure(
                new external_single_structure(array_merge(
                    [
                        'name' => new external_value(PARAM_TEXT, 'Imported question name'),
                        'questionbankentryid' => new external_value(
                            PARAM_INT,
                            'question_bank_entries ID (0 for "suspect")'
                        ),
                        'version' => new external_value(
                            PARAM_INT,
                            'New version number (0 for "suspect")'
                        ),
                        'status' => new external_value(PARAM_ALPHAEXT, '"first_import" (first import) | "reimport" (new version of the same entry) | "suspect" (suspect case)'),
                        'message' => new external_value(PARAM_RAW, 'Teacher-facing message'),
                    ],
                    question_suspect_gate::response_fields()
                )),
                'One result entry per imported XML question'
            ),
        ]);
    }
}
