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

use core_external\external_api;
use local_coursepilot\material_files;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

/**
 * XML import core (Spec 0017 §7.1, issue #415).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(import_questions_xml::class)]
final class import_questions_xml_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Importing without an idnumber creates a new bank entry with
     * a generated idnumber and version 1.
     */
    public function test_first_import_creates_new_entry_with_generated_idnumber(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $xml = self::multichoice_xml('Erstimport-Frage', 'Was ist 2+2?', 'Allgemeines Feedback');

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertCount(1, $result['questions']);
        $question = $result['questions'][0];
        $this->assertSame('first_import', $question['status']);
        $this->assertSame('Erstimport-Frage', $question['name']);
        $this->assertSame(1, $question['version']);
        $this->assertGreaterThan(0, $question['questionbankentryid']);

        global $DB;
        $entry = $DB->get_record('question_bank_entries', ['id' => $question['questionbankentryid']], '*', MUST_EXIST);
        $this->assertNotEmpty($entry->idnumber, 'An idnumber was generated.');
    }

    /**
     * Reimporting a matching idnumber creates a new version of the same
     * bank entry, not another entry.
     */
    public function test_reimport_with_matching_idnumber_creates_new_version(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        // First import has no idnumber; generate one as in the first test.
        $xml1 = self::multichoice_xml('Reimport-Frage', 'Alte Fassung', 'Feedback');

        $first = import_questions_xml::execute($categoryid, $xml1);
        $first = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $first);
        $this->assertSame('first_import', $first['questions'][0]['status']);
        $entryid = $first['questions'][0]['questionbankentryid'];

        global $DB;
        $generatedidnumber = $DB->get_field('question_bank_entries', 'idnumber', ['id' => $entryid], MUST_EXIST);

        // Reimport carries the generated idnumber, as in an export/edit cycle.
        $xml2 = self::multichoice_xml('Reimport-Frage', 'Korrigierte Fassung', 'Feedback', $generatedidnumber);
        $second = import_questions_xml::execute($categoryid, $xml2);
        $second = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $second);

        $this->assertSame('reimport', $second['questions'][0]['status']);
        $this->assertSame($entryid, $second['questions'][0]['questionbankentryid']);
        $this->assertSame(2, $second['questions'][0]['version']);

        global $DB;
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entryid]);
        $this->assertCount(2, $versions, 'Exactly one new version, no new bank entry.');
    }

    /**
     * A parse error aborts the entire request without writes or partial results.
     */
    public function test_parse_error_aborts_whole_call(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $this->expectException(\invalid_parameter_exception::class);
        try {
            import_questions_xml::execute($categoryid, 'das ist kein XML');
        } finally {
            $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
            $this->assertSame($countbefore, $countafter, 'Nothing was written.');
        }
    }

    /**
     * An idnumber with no target-category match is a suspected duplicate;
     * write nothing without confirmation.
     */
    public function test_suspect_case_without_confirmation_writes_nothing(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $xml = self::multichoice_xml('Verdachtsfall-Frage', 'Fragetext', 'Feedback', 'q-415-unbekannt');
        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $question = $result['questions'][0];
        $this->assertSame('suspect', $question['status']);
        $this->assertSame(0, $question['questionbankentryid']);
        $this->assertSame('q-415-unbekannt', $question['idnumber']);
        $this->assertSame($categoryid, $question['categoryid']);

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nothing was written.');
    }

    /**
     * A confirmed second request creates the suspected duplicate as a new entry.
     */
    public function test_confirmed_call_creates_entry_despite_suspect_case(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $xml = self::multichoice_xml('Bestaetigte Frage', 'Fragetext', 'Feedback', 'q-415-bestaetigt');
        $unconfirmed = import_questions_xml::execute($categoryid, $xml);
        $unconfirmed = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $unconfirmed);
        $this->assertSame('suspect', $unconfirmed['questions'][0]['status']);

        $confirmed = import_questions_xml::execute($categoryid, $xml, true);
        $confirmed = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $confirmed);

        $this->assertSame('first_import', $confirmed['questions'][0]['status']);
        $this->assertGreaterThan(0, $confirmed['questions'][0]['questionbankentryid']);

        global $DB;
        $entry = $DB->get_record(
            'question_bank_entries',
            ['id' => $confirmed['questions'][0]['questionbankentryid']],
            '*',
            MUST_EXIST
        );
        $this->assertSame('q-415-bestaetigt', $entry->idnumber);
    }

    /**
     * A failed round-trip check rolls back both the bank entry and version.
     * XML without a question name triggers question_type::save_question() to
     * generate a name from question text: exactly the silent deviation that
     * the round-trip check must catch.
     */
    public function test_failed_roundtrip_check_leaves_nothing_behind(): void {
        $this->resetAfterTest();
        // Exercise the endpoint transaction outside PHPUnit's enclosing PostgreSQL transaction.
        $this->preventResetByRollback();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $xml = self::multichoice_xml('', 'Fragetext ohne Namen im XML', 'Feedback');

        $this->expectException(\moodle_exception::class);
        try {
            import_questions_xml::execute($categoryid, $xml);
        } finally {
            $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
            $this->assertSame($countbefore, $countafter, 'Nothing was written.');
        }
    }

    /**
     * Text input (Spec 0018 §7.1): resolve a file element’s material attribute
     * to real Base64 on the server and import it. Spec 0017 §6’s prohibition
     * on embedded files no longer applies.
     */
    public function test_text_door_resolves_material_reference_and_imports(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $this->place_material_file('diagramm.png', self::PNG_BYTES);

        $xml = self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback');

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('first_import', $result['questions'][0]['status']);
        // Keep resolved Base64 on the server; the response contains no image
        // bytes regardless of image count in the XML.
        $this->assertStringNotContainsString(base64_encode(self::PNG_BYTES), json_encode($result));
    }

    /**
     * Text input: resolve material attributes with single quotes just like
     * double quotes, avoiding a parse error that writes the path as Base64.
     */
    public function test_text_door_resolves_material_reference_with_single_quotes(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $this->place_material_file('diagramm.png', self::PNG_BYTES);

        $xml = str_replace(
            'material="diagramm.png"',
            "material='diagramm.png'",
            self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback')
        );

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('first_import', $result['questions'][0]['status']);
    }

    /**
     * Text input: reject missing material references before any writes
     * (Spec 0018 §7.1); no partial imports.
     */
    public function test_text_door_missing_material_reference_aborts_with_nothing_written(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $xml = self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback');

        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('Erwartete moodle_exception wegen fehlender Materialdatei.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('diagramm.png', $e->getMessage());
        }

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nothing was written.');
    }

    /**
     * File input (Spec 0018 §7.1): read a stored XML file containing real
     * Base64 in file elements on the server and import it.
     */
    public function test_xmlpath_door_imports_from_material_file(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $this->place_material_file('export.xml', self::multichoice_xml_with_embedded_base64(
            'Frage aus Verweistuer',
            'Fragetext',
            'Feedback'
        ));

        $result = import_questions_xml::execute($categoryid, '', false, 'export.xml');
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('first_import', $result['questions'][0]['status']);
        $this->assertSame('Frage aus Verweistuer', $result['questions'][0]['name']);
    }

    /**
     * Embed from external material storage (#496, Spec #486 §7, default
     * location = inventory). Text input resolves material attributes through
     * the fake WebDAV transport without using the workbench.
     */
    public function test_text_door_resolves_material_reference_from_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/diagramm.png', self::PNG_BYTES);

        $xml = self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback');

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('first_import', $result['questions'][0]['status']);
    }

    /**
     * Embed from external material storage (#496). File input reads xmlpath
     * through the fake WebDAV transport.
     */
    public function test_xmlpath_door_imports_from_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/export.xml', self::multichoice_xml_with_embedded_base64(
            'Frage aus Verweistuer (Bestand)',
            'Fragetext',
            'Feedback'
        ));

        $result = import_questions_xml::execute($categoryid, '', false, 'export.xml');
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('first_import', $result['questions'][0]['status']);
        $this->assertSame('Frage aus Verweistuer (Bestand)', $result['questions'][0]['name']);
    }

    /**
     * Explicit location = workbench uses the workbench even when material
     * storage is external (#496).
     */
    public function test_xmlpath_door_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/export.xml', 'nicht das, was gelesen werden soll');
        $this->place_material_file('export.xml', self::multichoice_xml_with_embedded_base64(
            'Frage aus der Werkbank',
            'Fragetext',
            'Feedback'
        ));

        $result = import_questions_xml::execute(
            $categoryid,
            '',
            false,
            'export.xml',
            material_files::LOCATION_WORKBENCH
        );
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('first_import', $result['questions'][0]['status']);
        $this->assertSame('Frage aus der Werkbank', $result['questions'][0]['name']);
    }

    /**
     * The context_area guard (#495, Spec #486 §2/§7) also applies to embedding
     * file input (#496); context files have no alternate access path.
     */
    public function test_xmlpath_door_under_kontextbereich_is_rejected(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        \local_coursepilot\storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot-material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/kontext/',
            'filename' => 'export.xml',
        ], self::multichoice_xml_with_embedded_base64('Frage', 'Text', 'Feedback'));

        try {
            import_questions_xml::execute($categoryid, '', false, 'kontext/export.xml');
            $this->fail('A path under the context area should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiscontext', $e->errorcode);
        }
    }

    /**
     * File input: reject missing material files clearly without partial imports.
     */
    public function test_xmlpath_door_missing_file_aborts_with_clear_message(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        try {
            import_questions_xml::execute($categoryid, '', false, 'fehlt.xml');
            $this->fail('Erwartete moodle_exception wegen fehlender Materialdatei.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('fehlt.xml', $e->getMessage());
        }

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nothing was written.');
    }

    /**
     * Reject simultaneous text and file input instead of silently choosing
     * one (Spec 0018 §7.1).
     */
    public function test_both_doors_at_once_is_rejected(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $xml = self::multichoice_xml('Egal', 'Egal', 'Egal');

        $this->expectException(\invalid_parameter_exception::class);
        import_questions_xml::execute($categoryid, $xml, false, 'export.xml');
    }

    /**
     * Reject requests supplying neither text nor a file reference.
     */
    public function test_neither_door_given_is_rejected(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $this->expectException(\invalid_parameter_exception::class);
        import_questions_xml::execute($categoryid);
    }

    /**
     * Create a file directly in the current user’s material storage, like
     * {@see \local_coursepilot\material_files::filerecord()}. Bypass
     * upload_material_file because its whitelist excludes .xml;
     * material_files::resolve_file() reads without checking extensions.
     *
     * @param string $filename
     * @param string $content
     */
    private function place_material_file(string $filename, string $content): void {
        $context = material_files::own_context();
        [$directory, $resolvedname] = material_files::resolve_file($filename);
        get_file_storage()->create_file_from_string(
            material_files::filerecord($context->id, $directory, $resolvedname),
            $content
        );
    }

    /**
     * Reject oversized XML with its actual size, limit and suggested remedy
     * (#416). Inject the threshold through guard_size_against_limit using
     * Reflection instead of constructing a multi-megabyte string.
     */
    public function test_oversized_xml_reports_size_and_limit(): void {
        $method = new \ReflectionMethod(import_questions_xml::class, 'guard_size_against_limit');
        $method->setAccessible(true);

        try {
            $method->invoke(null, 2048, 1024);
            $this->fail('Expected invalid_parameter_exception because the size limit was exceeded.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString(display_size(2048), $e->getMessage());
            $this->assertStringContainsString(display_size(1024), $e->getMessage());
            $this->assertStringContainsString('Split', $e->getMessage());
        }

        // The method does not throw below the limit.
        $method->invoke(null, 100, 1024);
        $this->addToAssertionCount(1);
    }

    /**
     * Use the domain limit MAX_XML_BYTES, not get_max_upload_file_size().
     * The upload limit (200 MB alongside post_max_size 206 MB) practically
     * never triggered this guard (#424 follow-up 2).
     */
    public function test_effective_limit_is_the_plugin_constant(): void {
        $method = new \ReflectionMethod(import_questions_xml::class, 'guard_server_size_limit');
        $method->setAccessible(true);

        $this->assertGreaterThan(
            import_questions_xml::MAX_XML_BYTES,
            get_max_upload_file_size(),
            'Test assumption: the server upload limit is above the domain limit.'
        );

        try {
            $method->invoke(null, str_repeat('x', import_questions_xml::MAX_XML_BYTES + 1));
            $this->fail('Erwartete invalid_parameter_exception wegen Ueberschreitung von MAX_XML_BYTES.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString(display_size(import_questions_xml::MAX_XML_BYTES), $e->getMessage());
        }
    }

    /**
     * Reject a bare question element without a quiz wrapper with that exact
     * cause (#425 F2), rather than leaking qformat_xml’s undefined quiz-key
     * error (#424 follow-up 1).
     */
    public function test_missing_quiz_root_names_the_actual_cause(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $xml = '<question type="multichoice"><name><text>Ohne Rahmen</text></name></question>';

        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('Erwartete invalid_parameter_exception wegen fehlendem <quiz>-Rahmen.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('<quiz>', $e->getMessage());
            $this->assertStringNotContainsString('array key', $e->getMessage());
            $this->assertStringNotContainsString('offset', $e->getMessage());
        }
    }

    /**
     * Replace internal XML-core PHP errors with actionable teacher-facing
     * text (#424 follow-up 1). Preserve actual moodle_exception messages,
     * such as xmlize format errors, because they describe the file.
     */
    public function test_php_internal_parse_errors_are_replaced(): void {
        $method = new \ReflectionMethod(import_questions_xml::class, 'parse_failure_message');
        $method->setAccessible(true);

        $internal = $method->invoke(null, new \Error('Cannot access offset of type string on string'));
        $this->assertStringNotContainsString('offset', $internal);
        $this->assertStringContainsString('Moodle XML', $internal);

        $formaterror = new \moodle_exception('errorreadingfile', 'error', '', 'fragen.xml');
        $this->assertSame($formaterror->getMessage(), $method->invoke(null, $formaterror));
    }

    /**
     * Calculated questions with datasets (#440) need a type-specific dataset
     * structure with compound string keys for question_type::save_question().
     * The generic save path does not prepare readquestions() output. Replace
     * the former TypeError with a clear moodle_exception and no writes.
     */
    public function test_calculated_with_dataset_definitions_reports_speaking_message(): void {
        $this->resetAfterTest();
        // Exercise the endpoint transaction outside PHPUnit's enclosing PostgreSQL transaction.
        $this->preventResetByRollback();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $xml = self::calculated_xml_with_dataset_definitions();

        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('Expected moodle_exception instead of a silent success.');
        } catch (\TypeError $e) {
            $this->fail('TypeError leaked instead of a descriptive moodle_exception: ' . $e->getMessage());
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('stdClass', $e->getMessage());
            $this->assertStringContainsString('calculated', $e->getMessage());
        }

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nothing was written.');
    }

    /**
     * Create a course, teacher, question bank and category; return
     * [$course, $categoryid, $teacher].
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass}
     */
    private function setup_course_and_category(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $bank = ensure_question_bank::execute($course->id, 'XML-Kern-Test');
        $bank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $bank);

        $category = ensure_question_category::execute('Kategorie', (int) $bank['topcategoryid']);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        return [$course, (int) $category['id'], $teacher];
    }

    /**
     * Set up external material storage (fake WebDAV) for a logged-in teacher
     * (#496). See
     * {@see \local_coursepilot\external\update_module_settings_test::set_up_external_material_for()}.
     *
     * @param \stdClass $teacher
     * @return \local_coursepilot\tests\webdav\fake_webdav_transport
     */
    private function set_up_external_material_for(\stdClass $teacher): \local_coursepilot\tests\webdav\fake_webdav_transport {
        $this->grant_webdav_capability($teacher);
        $instanceid = $this->create_webdav_instance($teacher);
        $this->write_v2_pointer($teacher, 'material_store', $instanceid, 'Material');

        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        return $fake;
    }

    /**
     * Build minimal Moodle XML containing one multichoice question.
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

    /** @var string Minimal PNG bytes (signature only; the content is never decoded). */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n";

    /**
     * Like {@see self::multichoice_xml()}, but with a file element referencing
     * a material file through its material attribute instead of real Base64
     * (text input, Spec 0018 §7.1).
     *
     * @param string $name
     * @param string $questiontext
     * @param string $generalfeedback
     * @return string
     */
    private static function multichoice_xml_with_material_file(
        string $name,
        string $questiontext,
        string $generalfeedback
    ): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name><text>{$name}</text></name>
    <questiontext format="html">
      <text><![CDATA[{$questiontext}]]></text>
      <file name="diagramm.png" path="/" material="diagramm.png"></file>
    </questiontext>
    <generalfeedback format="html"><text><![CDATA[{$generalfeedback}]]></text></generalfeedback>
    <defaultgrade>1.0000000</defaultgrade>
    <penalty>0.3333333</penalty>
    <hidden>0</hidden>
    <idnumber></idnumber>
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

    /**
     * Calculated question with two dataset definitions, reproducing #440.
     *
     * @return string
     */
    private static function calculated_xml_with_dataset_definitions(): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="calculated">
    <name><text>Berechnete Potenz</text></name>
    <questiontext format="html"><text><![CDATA[<p>Berechne die Potenz.</p>]]></text></questiontext>
    <generalfeedback format="html"><text><![CDATA[<p>Feedback.</p>]]></text></generalfeedback>
    <defaultgrade>1.0000000</defaultgrade><penalty>0.3333333</penalty><hidden>0</hidden><idnumber></idnumber>
    <synchronize>0</synchronize>
    <answer fraction="100" format="moodle_auto_format"><text>pow({a},{b})</text><tolerance>0.01</tolerance><tolerancetype>1</tolerancetype><correctanswerlength>2</correctanswerlength><correctanswerformat>1</correctanswerformat><feedback format="html"><text><![CDATA[<p>Richtig.</p>]]></text></feedback></answer>
    <unitgradingtype>0</unitgradingtype><unitpenalty>0.1000000</unitpenalty><showunits>3</showunits><unitsleft>0</unitsleft>
    <dataset_definitions>
      <dataset_definition>
        <status><text>private</text></status>
        <name><text>1591-a</text></name>
        <type>calculated</type>
        <distribution><text>uniform</text></distribution>
        <minimum><text>2</text></minimum>
        <maximum><text>5</text></maximum>
        <decimals><text>0</text></decimals>
        <itemcount>10</itemcount>
        <dataset_items><dataset_item><number>1</number><value>2</value></dataset_item></dataset_items>
      </dataset_definition>
      <dataset_definition>
        <status><text>private</text></status>
        <name><text>1591-b</text></name>
        <type>calculated</type>
        <distribution><text>uniform</text></distribution>
        <minimum><text>2</text></minimum>
        <maximum><text>4</text></maximum>
        <decimals><text>0</text></decimals>
        <itemcount>10</itemcount>
        <dataset_items><dataset_item><number>1</number><value>2</value></dataset_item></dataset_items>
      </dataset_definition>
    </dataset_definitions>
  </question>
</quiz>
XML;
    }

    /**
     * Like {@see self::multichoice_xml()}, but with a file element containing
     * real Base64, as in an external Moodle export (file input, Spec 0018 §7.1).
     *
     * @param string $name
     * @param string $questiontext
     * @param string $generalfeedback
     * @return string
     */
    private static function multichoice_xml_with_embedded_base64(
        string $name,
        string $questiontext,
        string $generalfeedback
    ): string {
        $base64 = base64_encode(self::PNG_BYTES);
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name><text>{$name}</text></name>
    <questiontext format="html">
      <text><![CDATA[{$questiontext}]]></text>
      <file name="diagramm.png" path="/" encoding="base64">{$base64}</file>
    </questiontext>
    <generalfeedback format="html"><text><![CDATA[{$generalfeedback}]]></text></generalfeedback>
    <defaultgrade>1.0000000</defaultgrade>
    <penalty>0.3333333</penalty>
    <hidden>0</hidden>
    <idnumber></idnumber>
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
