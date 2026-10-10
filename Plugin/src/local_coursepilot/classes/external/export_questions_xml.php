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

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\material_files;
use qformat_xml;
use question_bank;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Export counterpart of the XML core (Spec 0017 §7.1, ticket #417; full
 * file in Spec 0018 §7.2, ticket #437): reads one or more questions as Moodle
 * XML through qformat_xml, the same server-side formatter used for the
 * round-trip check in {@see import_questions_xml}, exposed as its own tool.
 *
 * Standard mode (placeholder=false, default): writes COMPLETE, standard
 * XML with actual base64 in <file> blocks to the material store (Spec 0018
 * §2). The response names only the path; no image bytes enter AI context.
 * The file can be imported into any other Moodle for sharing or read again
 * through the reference input of {@see import_questions_xml} for a round trip.
 *
 * Placeholder mode (placeholder=true): the original export from Spec 0017
 * §4.2 replaces <file> blocks with named XML comment placeholders and returns
 * XML directly. Suitable for templates (learning structure rather than a
 * 400 KB image), NOT for sharing; the message states this explicitly.
 * The placeholder deliberately does NOT start with "<file", so reimporting
 * it cannot mistake it for an embedded file.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class export_questions_xml extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'questionid of any version of the question to export'),
                'List of questionids (at least one)'
            ),
            'targetpath' => new external_value(
                PARAM_PATH,
                'Material folder path of the XML file to write, e.g. "export.xml" - required in the standard mode '
                    . '(placeholder=false), ignored in the placeholder mode.',
                VALUE_DEFAULT,
                ''
            ),
            'placeholder' => new external_value(
                PARAM_BOOL,
                'Switch (Spec 0018 §7.2), default false: the complete, standard-conformant XML with real base64 is '
                    . 'written to the material folder, the response only names the path. true instead returns the '
                    . 'XML directly in the response as before, with named placeholders instead of embedded files - '
                    . 'only for the template purpose (learning the structure), NOT suitable for sharing.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Runs the export questions xml tool.
     *
     * @param int[] $questionids
     * @param string $targetpath
     * @param bool $placeholder
     * @return array
     */
    public static function execute(array $questionids, string $targetpath = '', bool $placeholder = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'questionids' => $questionids,
            'targetpath' => $targetpath,
            'placeholder' => $placeholder,
        ]);
        $ids = $params['questionids'];

        if (empty($ids)) {
            throw new \invalid_parameter_exception('Specify at least one questionid.');
        }
        if (!$params['placeholder'] && trim($params['targetpath']) === '') {
            throw new \invalid_parameter_exception(
                'targetpath is required in standard mode (material store path of the XML file to write), '
                    . 'e.g. "export.xml". For placeholder mode, set placeholder=true instead.'
            );
        }

        $parts = [];
        $missing = [];
        foreach ($ids as $questionid) {
            [$xml, $name, $filenames] = self::export_one((int) $questionid, $params['placeholder']);
            $parts[] = $xml;
            if ($params['placeholder'] && !empty($filenames)) {
                $missing[] = ['name' => $name, 'files' => $filenames];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . implode("\n", $parts) . "\n</quiz>\n";

        if ($params['placeholder']) {
            return [
                'xml' => $xml,
                'path' => '',
                'count' => count($ids),
                'message' => self::build_message(count($ids), $missing, true),
            ];
        }

        return self::write_and_report($params['targetpath'], $xml, count($ids));
    }

    /**
     * Writes export XML to the material store and builds the standard-mode
     * response (issue #523: extracted from execute() to keep the function
     * below the line limit).
     *
     * @param string $targetpath
     * @param string $xml
     * @param int $count
     * @return array{xml: string, path: string, count: int, message: string}
     */
    private static function write_and_report(string $targetpath, string $xml, int $count): array {
        [$path, $warning] = self::write_material_file($targetpath, $xml);
        $message = self::build_message($count, [], false) . ' ' . get_string('questionexportpath', 'local_coursepilot', $path);
        if ($warning !== null) {
            $message .= ' ' . $warning;
        }

        return [
            'xml' => '',
            'path' => $path,
            'count' => $count,
            'message' => $message,
        ];
    }

    /**
     * Resolves a questionid (any version) to the latest version of its bank
     * entry, checks native read permission in the category context, and exports
     * through qformat_xml. Same approach as import_questions_xml::verify_roundtrip(),
     * for an existing question instead of a newly written one.
     *
     * @param int $questionid
     * @param bool $placeholder true: replace embedded files with placeholders (Spec 0017 §4.2)
     * @return array{0: string, 1: string, 2: string[]} [XML fragment, question name, removed filenames (placeholder mode only)]
     */
    private static function export_one(int $questionid, bool $placeholder): array {
        [$question, $category, $context] = self::resolve_native_question($questionid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Note: moodle/question:view no longer exists; Moodle only knows
        // viewmine/viewall (see get_question.php). viewall matches the
        // read permission here: export is a read operation.
        require_capability('moodle/question:viewall', $context);

        [$xml, $filenames] = self::question_to_xml($question, $category, $context, $placeholder);

        return [$xml, (string) $question->name, $filenames];
    }

    /**
     * Writes complete export XML (actual base64, standard mode) to the calling
     * teacher's material store, without its extension allowlist: ".xml" is
     * intentionally absent from the upload allowlist (Spec 0018 §6, see
     * {@see material_files::resolve_writable_file()}). This uses
     * {@see material_files::resolve_file()}, the same path read by the reference
     * input of {@see import_questions_xml}. Size, quota and write handling are
     * shared with {@see \local_coursepilot\external\upload_material_file};
     * see {@see material_files::write()}.
     *
     * @param string $targetpath
     * @param string $content
     * @return array{0: string, 1: string|null} [material store path, quota warning or null]
     */
    private static function write_material_file(string $targetpath, string $content): array {
        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        [$directory, $filename] = material_files::resolve_file($targetpath);

        $existing = material_files::read_content($directory, $filename);
        $oldsize = $existing !== null ? $existing['size'] : 0;

        $warning = material_files::write($directory, $filename, $content, $oldsize);

        return [material_files::relative_file($directory, $filename), $warning];
    }

    /**
     * Loads the latest question version in the native object shape expected
     * by {@see \qformat_xml::writequestion()}: the question row plus qtype options
     * from {@see \question_type::get_question_options()}. No capability check;
     * the caller checks read permission for export or write permission for
     * {@see \local_coursepilot\external\update_mc_question}.
     *
     * Reused by update_mc_question (ticket #419): instead of patching XML, it
     * overrides selected native properties (name/questiontext/generalfeedback/
     * defaultmark/options->single/options->answers). Other values (penalty,
     * shuffleanswers, answernumbering, combined feedback, tags, hints, etc.)
     * automatically remain untouched because they are never modified.
     *
     * @param int $questionid
     * @return array{0: \stdClass, 1: \stdClass, 2: \context} [native question object, category, context]
     */
    public static function resolve_native_question(int $questionid): array {
        global $DB;

        $version = $DB->get_record('question_versions', ['questionid' => $questionid]);
        if (!$version) {
            throw new \moodle_exception(
                'notfound',
                'error',
                '',
                null,
                'No question with questionid ' . $questionid . ' found.'
            );
        }

        $latest = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$version->questionbankentryid],
            IGNORE_MULTIPLE
        );

        $entry = $DB->get_record('question_bank_entries', ['id' => $latest->questionbankentryid], '*', MUST_EXIST);
        $category = $DB->get_record('question_categories', ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $context = context::instance_by_id((int) $category->contextid);

        $question = $DB->get_record('question', ['id' => $latest->questionid], '*', MUST_EXIST);
        $question->export_process = true;
        $question->categoryobject = $category;

        $qtype = question_bank::get_qtype($question->qtype);
        $qtype->get_question_options($question);

        $question->contextid = (int) $context->id;
        $question->idnumber = (string) $entry->idnumber;

        return [$question, $category, $context];
    }

    /**
     * Writes a native question object (see {@see self::resolve_native_question()})
     * as XML through qformat_xml. Public for reuse outside this class.
     *
     * @param \stdClass $question
     * @param \stdClass $category
     * @param \context $context
     * @param bool $stripfiles true (default, backward-compatible for existing callers such as
     *        update_mc_question): replace embedded files with placeholders (see class documentation).
     *        false: retain actual base64 unchanged (standard export mode, Spec 0018 §7.2).
     * @return array{0: string, 1: string[]} [XML fragment, removed filenames (empty when not stripped)]
     */
    public static function question_to_xml(
        \stdClass $question,
        \stdClass $category,
        \context $context,
        bool $stripfiles = true
    ): array {
        $qformat = new qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);

        $xml = $qformat->writequestion($question);
        if (!$stripfiles) {
            return [$xml, []];
        }
        return self::strip_embedded_files($xml);
    }

    /**
     * Replaces each <file> block (base64 file content) with an XML comment
     * placeholder naming the original file. Never strip without a trace:
     * the teacher/AI must see which file is missing (Spec 0017, image and size rules).
     *
     * @param string $xml
     * @return array{0: string, 1: string[]} [cleaned XML, removed filenames]
     */
    private static function strip_embedded_files(string $xml): array {
        $filenames = [];
        $cleaned = preg_replace_callback(
            '/<file\b[^>]*\bname="([^"]*)"[^>]*>.*?<\/file>\n?/s',
            static function (array $m) use (&$filenames): string {
                $filename = $m[1];
                $filenames[] = $filename;
                // Note: "--" would prematurely close the XML comment.
                $safe = str_replace('--', '- -', $filename);
                return '<!-- File removed (no binary transport in export): ' . $safe . " -->\n";
            },
            $xml
        );

        return [$cleaned ?? $xml, $filenames];
    }

    /**
     * Builds the teacher-facing message. For missing files in placeholder
     * mode it explicitly names the affected questions and states that files
     * were not exported. It also states that placeholder output is incomplete
     * and unsuitable for sharing (Spec 0018 §7.2, ticket #437).
     *
     * @param int $count
     * @param array $missing Type: array<int,array{name:string,files:string[]}>.
     * @param bool $placeholder
     * @return string
     */
    private static function build_message(int $count, array $missing, bool $placeholder): string {
        $base = $count === 1 ? get_string('questionexportone', 'local_coursepilot') : get_string('questionexportmany', 'local_coursepilot', $count);

        if ($placeholder) {
            $base .= ' ' . get_string('questionexportplaceholder', 'local_coursepilot');
        }

        if (empty($missing)) {
            return $base;
        }

        $details = [];
        foreach ($missing as $entry) {
            $details[] = get_string('questionexportmissingdetail', 'local_coursepilot', (object) ['name' => $entry['name'], 'files' => implode(', ', $entry['files'])]);
        }

        return $base . ' ' . get_string('questionexportmissing', 'local_coursepilot', implode('; ', $details));
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'xml' => new external_value(
                PARAM_RAW,
                'ONLY filled in the placeholder mode: Moodle question XML export (a <quiz> root element with one '
                    . '<question> per requested question), embedded files replaced by named comment placeholders. '
                    . 'Empty in the standard mode - the complete XML is in "path".',
                VALUE_DEFAULT,
                ''
            ),
            'path' => new external_value(
                PARAM_TEXT,
                'ONLY filled in the standard mode: material folder path of the written, complete XML file (real '
                    . 'base64 in <file> blocks). Empty in the placeholder mode.',
                VALUE_DEFAULT,
                ''
            ),
            'count' => new external_value(PARAM_INT, 'Number of exported questions'),
            'message' => new external_value(
                PARAM_RAW,
                'Teacher-facing message; explicitly names in the placeholder mode that the output is '
                    . 'incomplete and not suitable for sharing, and which question is affected for missing files'
            ),
        ]);
    }
}
