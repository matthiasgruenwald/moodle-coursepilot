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

/**
 * XML export counterpart to the import core (Spec 0017 §7.1, issue #417).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(export_questions_xml::class)]
final class export_questions_xml_test extends \advanced_testcase {
    /**
     * Standard-mode round trip (Spec 0018 §7.2, #437): export a complete XML
     * file into material storage, returning only its path, and reimport via
     * import_questions_xml file input. The same category recognizes the
     * exported idnumber and creates a new version of the same bank entry,
     * proving structural completeness and preserved identity.
     */
    public function test_export_then_import_roundtrip(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $xml = self::multichoice_xml('Rundlauf-Frage', 'Was ist 2+2?', 'Allgemeines Feedback');
        $imported = import_questions_xml::execute($categoryid, $xml);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);
        $this->assertSame('first_import', $imported['questions'][0]['status']);

        global $DB;
        $entryid = $imported['questions'][0]['questionbankentryid'];
        $version = $DB->get_record('question_versions', ['questionbankentryid' => $entryid], '*', MUST_EXIST);

        $exported = export_questions_xml::execute([(int) $version->questionid], 'export.xml');
        $exported = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $exported);

        $this->assertSame(1, $exported['count']);
        $this->assertSame('', $exported['xml'], 'Default mode: no image bytes/XML in the tool response');
        $this->assertSame('export.xml', $exported['path']);
        $this->assertStringContainsString('File: export.xml', $exported['message']);
        $this->assertStringNotContainsString('PLACEHOLDER', $exported['message']);

        $reimported = import_questions_xml::execute($categoryid, '', false, 'export.xml');
        $reimported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $reimported);

        $this->assertSame('reimport', $reimported['questions'][0]['status']);
        $this->assertSame('Rundlauf-Frage', $reimported['questions'][0]['name']);
        $this->assertSame($entryid, $reimported['questions'][0]['questionbankentryid'], 'Same bank entry, new version.');
        $this->assertSame(2, $reimported['questions'][0]['version']);
    }

    /**
     * Round trip with an image (#437): standard export writes real Base64
     * to XML, returns no image bytes and restores the image on reimport.
     * The same file is attached to the newly imported question text.
     */
    public function test_full_export_roundtrips_embedded_file_via_import_xmlpath_door(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $xml = self::multichoice_xml('Frage mit Bild', 'Siehe Diagramm', 'Feedback');
        $imported = import_questions_xml::execute($categoryid, $xml);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);

        global $DB;
        $entryid = $imported['questions'][0]['questionbankentryid'];
        $version = $DB->get_record('question_versions', ['questionbankentryid' => $entryid], '*', MUST_EXIST);
        $question = $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);

        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
        $contextid = (int) $category->contextid;
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'question',
            'filearea' => 'questiontext',
            'itemid' => $question->id,
            'filepath' => '/',
            'filename' => 'diagramm.png',
        ], 'echter-bildinhalt');

        $exported = export_questions_xml::execute([(int) $question->id], 'bild-export.xml');
        $exported = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $exported);

        $this->assertSame('', $exported['xml'], 'no image bytes in the tool response');
        $this->assertSame('bild-export.xml', $exported['path']);

        // Real Base64 in the stored file proves standards compliance.
        [$materialdirectory, $materialfilename] = \local_coursepilot\material_files::resolve_file('bild-export.xml');
        $material = get_file_storage()->get_file(
            \local_coursepilot\material_files::own_context()->id,
            \local_coursepilot\material_files::COMPONENT,
            \local_coursepilot\material_files::FILEAREA,
            \local_coursepilot\material_files::ITEMID,
            $materialdirectory,
            $materialfilename
        );
        $this->assertNotFalse($material);
        $materialcontent = $material->get_content();
        $this->assertStringContainsString('<file', $materialcontent);
        $this->assertStringContainsString(base64_encode('echter-bildinhalt'), $materialcontent);

        $reimported = import_questions_xml::execute($categoryid, '', false, 'bild-export.xml');
        $reimported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $reimported);

        $this->assertSame('reimport', $reimported['questions'][0]['status']);
        $newentryid = $reimported['questions'][0]['questionbankentryid'];
        $newversion = $DB->get_record('question_versions', ['questionbankentryid' => $newentryid, 'version' => 2], '*', MUST_EXIST);

        $reimportedfiles = get_file_storage()->get_area_files(
            $contextid,
            'question',
            'questiontext',
            (int) $newversion->questionid,
            'filename',
            false
        );
        $filenames = array_map(static fn($f) => $f->get_filename(), $reimportedfiles);
        $this->assertContains('diagramm.png', $filenames, 'Image arrived with the reimport');
    }

    /**
     * Placeholder mode (Spec 0018 §7.2): return a named placeholder instead
     * of Base64 directly in the response. Explicitly identify missing files
     * and warn that the output is incomplete and unsuitable for sharing.
     */
    public function test_platzhalter_mode_returns_xml_inline_and_names_incompleteness(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $xml = self::multichoice_xml('Frage mit Bild', 'Siehe Diagramm', 'Feedback');
        $imported = import_questions_xml::execute($categoryid, $xml);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);

        global $DB;
        $entryid = $imported['questions'][0]['questionbankentryid'];
        $version = $DB->get_record('question_versions', ['questionbankentryid' => $entryid], '*', MUST_EXIST);
        $question = $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);

        // Attach through the storage API to test export placeholders independently of import.
        $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
        $contextid = (int) $category->contextid;
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'question',
            'filearea' => 'questiontext',
            'itemid' => $question->id,
            'filepath' => '/',
            'filename' => 'diagramm.png',
        ], 'fake-bildinhalt');

        $exported = export_questions_xml::execute([(int) $question->id], '', true);
        $exported = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $exported);

        $this->assertSame('', $exported['path'], 'Placeholder mode writes no material file');
        $this->assertStringNotContainsString('<file', $exported['xml'], 'no <file> block, only the placeholder');
        $this->assertStringNotContainsString('fake-bildinhalt', $exported['xml'], 'no base64 file content');
        $this->assertStringContainsString('diagramm.png', $exported['xml'], 'Placeholder names the file name');
        $this->assertStringContainsString('Frage mit Bild', $exported['message']);
        $this->assertStringContainsString('diagramm.png', $exported['message']);
        $this->assertStringContainsString('PLACEHOLDER MODE', $exported['message']);
        $this->assertStringContainsString('NOT suitable for sharing', $exported['message']);
    }

    /**
     * Standard mode (placeholder=false, the default) requires targetpath.
     */
    public function test_standard_mode_requires_targetpath(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $xml = self::multichoice_xml('Frage', 'Fragetext', 'Feedback');
        $imported = import_questions_xml::execute($categoryid, $xml);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);

        global $DB;
        $entryid = $imported['questions'][0]['questionbankentryid'];
        $version = $DB->get_record('question_versions', ['questionbankentryid' => $entryid], '*', MUST_EXIST);

        $this->expectException(\invalid_parameter_exception::class);
        export_questions_xml::execute([(int) $version->questionid]);
    }

    /**
     * Require at least one questionid.
     */
    public function test_requires_at_least_one_questionid(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->setup_course_and_category();
        $this->getDataGenerator();

        $this->expectException(\invalid_parameter_exception::class);
        export_questions_xml::execute([]);
    }

    /**
     * Require moodle/question:viewall in the category context before exporting.
     */
    public function test_rejects_user_without_viewall_capability(): void {
        $this->resetAfterTest();

        [$course, $categoryid] = $this->setup_course_and_category();

        $xml = self::multichoice_xml('Geschuetzte Frage', 'Fragetext', 'Feedback');
        $imported = import_questions_xml::execute($categoryid, $xml);
        $imported = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $imported);

        global $DB;
        $entryid = $imported['questions'][0]['questionbankentryid'];
        $version = $DB->get_record('question_versions', ['questionbankentryid' => $entryid], '*', MUST_EXIST);

        $secondteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($secondteacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'moodle/question:viewall',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($secondteacher);

        $this->expectException(\required_capability_exception::class);
        export_questions_xml::execute([(int) $version->questionid], 'export.xml');
    }

    /**
     * Create a course, teacher, question bank and category; return
     * [$course, $categoryid, $topcategoryid].
     *
     * @return array{0: \stdClass, 1: int, 2: int}
     */
    private function setup_course_and_category(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $bank = ensure_question_bank::execute($course->id, 'Export-Test');
        $bank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $bank);

        $category = ensure_question_category::execute('Kategorie', (int) $bank['topcategoryid']);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        return [$course, (int) $category['id'], (int) $bank['topcategoryid']];
    }

    /**
     * Returns role id.
     *
     * @param string $shortname The shortname.
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Build minimal Moodle XML with one multichoice question, matching
     * the fixture in import_questions_xml_test.php.
     *
     * @param string $name
     * @param string $questiontext
     * @param string $generalfeedback
     * @param string $idnumber
     * @return string
     */
    private static function multichoice_xml(
        string $name,
        string $questiontext,
        string $generalfeedback,
        string $idnumber = ''
    ): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name><text>{$name}</text></name>
    <questiontext format="html"><text><![CDATA[{$questiontext}]]></text></questiontext>
    <generalfeedback format="html"><text><![CDATA[{$generalfeedback}]]></text></generalfeedback>
    <defaultgrade>1.0000000</defaultgrade>
    <penalty>0.3333333</penalty>
    <hidden>0</hidden>
    <idnumber>{$idnumber}</idnumber>
    <single>true</single>
    <shuffleanswers>true</shuffleanswers>
    <answernumbering>abc</answernumbering>
    <correctfeedback format="html"><text></text></correctfeedback>
    <partiallycorrectfeedback format="html"><text></text></partiallycorrectfeedback>
    <incorrectfeedback format="html"><text></text></incorrectfeedback>
    <answer fraction="100" format="html">
      <text><![CDATA[4]]></text>
      <feedback format="html"><text><![CDATA[Richtig]]></text></feedback>
    </answer>
    <answer fraction="0" format="html">
      <text><![CDATA[5]]></text>
      <feedback format="html"><text><![CDATA[Falsch]]></text></feedback>
    </answer>
  </question>
</quiz>
XML;
    }
}
