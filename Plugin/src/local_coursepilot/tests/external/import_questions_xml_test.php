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
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

/**
 * Der XML-Kern (Spec 0017 §7.1, Ticket #415).
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
     * Erstimport ohne idnumber: legt einen neuen Bank-Eintrag mit
     * generierter idnumber an (Version 1).
     */
    public function test_first_import_creates_new_entry_with_generated_idnumber(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $xml = self::multichoice_xml('Erstimport-Frage', 'Was ist 2+2?', 'Allgemeines Feedback');

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertCount(1, $result['questions']);
        $question = $result['questions'][0];
        $this->assertSame('erstimport', $question['status']);
        $this->assertSame('Erstimport-Frage', $question['name']);
        $this->assertSame(1, $question['version']);
        $this->assertGreaterThan(0, $question['questionbankentryid']);

        global $DB;
        $entry = $DB->get_record('question_bank_entries', ['id' => $question['questionbankentryid']], '*', MUST_EXIST);
        $this->assertNotEmpty($entry->idnumber, 'Eine idnumber wurde generiert.');
    }

    /**
     * Reimport mit passender idnumber: neue Version desselben Bank-Eintrags,
     * kein zweiter Eintrag.
     */
    public function test_reimport_with_matching_idnumber_creates_new_version(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        // Erstimport ohne idnumber im XML - genau wie ein echter Erstimport,
        // eine idnumber wird generiert (siehe erster Test).
        $xml1 = self::multichoice_xml('Reimport-Frage', 'Alte Fassung', 'Feedback');

        $first = import_questions_xml::execute($categoryid, $xml1);
        $first = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $first);
        $this->assertSame('erstimport', $first['questions'][0]['status']);
        $entryid = $first['questions'][0]['questionbankentryid'];

        global $DB;
        $generatedidnumber = $DB->get_field('question_bank_entries', 'idnumber', ['id' => $entryid], MUST_EXIST);

        // Reimport traegt dieselbe (generierte) idnumber mit - genau wie ein
        // Export/Korrektur-Zyklus derselben Frage.
        $xml2 = self::multichoice_xml('Reimport-Frage', 'Korrigierte Fassung', 'Feedback', $generatedidnumber);
        $second = import_questions_xml::execute($categoryid, $xml2);
        $second = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $second);

        $this->assertSame('reimport', $second['questions'][0]['status']);
        $this->assertSame($entryid, $second['questions'][0]['questionbankentryid']);
        $this->assertSame(2, $second['questions'][0]['version']);

        global $DB;
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entryid]);
        $this->assertCount(2, $versions, 'Genau eine neue Version, kein neuer Bank-Eintrag.');
    }

    /**
     * Ein Parse-Fehler bricht den gesamten Aufruf ab - kein Teilergebnis,
     * nichts geschrieben.
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
            $this->assertSame($countbefore, $countafter, 'Nichts wurde geschrieben.');
        }
    }

    /**
     * Verdachtsfall: mitgebrachte idnumber ohne Treffer in der Zielkategorie
     * schreibt ohne Bestaetigung nichts.
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
        $this->assertSame('verdachtsfall', $question['status']);
        $this->assertSame(0, $question['questionbankentryid']);
        $this->assertSame('q-415-unbekannt', $question['idnumber']);
        $this->assertSame($categoryid, $question['categoryid']);

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nichts wurde geschrieben.');
    }

    /**
     * Bestaetigter Zweitaufruf legt den Verdachtsfall als neuen Eintrag an.
     */
    public function test_confirmed_call_creates_entry_despite_suspect_case(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $xml = self::multichoice_xml('Bestaetigte Frage', 'Fragetext', 'Feedback', 'q-415-bestaetigt');
        $unconfirmed = import_questions_xml::execute($categoryid, $xml);
        $unconfirmed = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $unconfirmed);
        $this->assertSame('verdachtsfall', $unconfirmed['questions'][0]['status']);

        $confirmed = import_questions_xml::execute($categoryid, $xml, true);
        $confirmed = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $confirmed);

        $this->assertSame('erstimport', $confirmed['questions'][0]['status']);
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
     * Eine fehlgeschlagene Round-Trip-Pruefung rollt alles zurueck - weder
     * Bank-Eintrag noch Version bleiben zurueck. Ausgeloest durch ein XML
     * ohne Fragenamen: question_type::save_question() generiert in diesem
     * Fall selbst einen Namen aus dem Fragetext - genau die Art stiller
     * Abweichung, die die Round-Trip-Pruefung fangen soll.
     */
    public function test_failed_roundtrip_check_leaves_nothing_behind(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $xml = self::multichoice_xml('', 'Fragetext ohne Namen im XML', 'Feedback');

        $this->expectException(\moodle_exception::class);
        try {
            import_questions_xml::execute($categoryid, $xml);
        } finally {
            $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
            $this->assertSame($countbefore, $countafter, 'Nichts wurde geschrieben.');
        }
    }

    /**
     * Textuer (Spec 0018 §7.1): ein <file>-Block mit material="..."-Attribut
     * wird serverseitig zu echtem Base64 aufgeloest und importiert - die
     * Abweisung eingebetteter Dateien aus Spec 0017 §6 ist entfallen.
     */
    public function test_text_door_resolves_material_reference_and_imports(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $this->place_material_file('diagramm.png', self::PNG_BYTES);

        $xml = self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback');

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('erstimport', $result['questions'][0]['status']);
        // Die Antwort enthaelt kein Bildbyte - das aufgeloeste Base64 bleibt
        // serverseitig, unabhaengig davon, wie viele Bilder das XML traegt.
        $this->assertStringNotContainsString(base64_encode(self::PNG_BYTES), json_encode($result));
    }

    /**
     * Textuer: material="..." mit einfachen Anfuehrungszeichen wird genauso
     * aufgeloest wie mit doppelten - kein stiller Fehlparse, der den
     * Pfadstring als Base64-Inhalt in die Frage schreiben wuerde.
     */
    public function test_text_door_resolves_material_reference_with_single_quotes(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $this->place_material_file('diagramm.png', self::PNG_BYTES);

        $xml = str_replace('material="diagramm.png"', "material='diagramm.png'",
            self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback'));

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('erstimport', $result['questions'][0]['status']);
    }

    /**
     * Textuer: ein material-Verweis ins Leere bricht VOR jedem Schreiben mit
     * klarer Meldung ab - kein Teilimport (Spec 0018 §7.1).
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
        $this->assertSame($countbefore, $countafter, 'Nichts wurde geschrieben.');
    }

    /**
     * Verweistuer (Spec 0018 §7.1): eine im Materialordner liegende
     * XML-Datei mit echtem Base64 in ihren <file>-Bloecken wird
     * serverseitig gelesen und importiert.
     */
    public function test_xmlpath_door_imports_from_material_file(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $this->place_material_file('export.xml', self::multichoice_xml_with_embedded_base64(
            'Frage aus Verweistuer', 'Fragetext', 'Feedback'
        ));

        $result = import_questions_xml::execute($categoryid, '', false, 'export.xml');
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('erstimport', $result['questions'][0]['status']);
        $this->assertSame('Frage aus Verweistuer', $result['questions'][0]['name']);
    }

    /**
     * Einbettung direkt aus dem externen Materialbestand (Issue #496, Spec
     * #486 §7, Default "ort" = "bestand") - Textuer: ein material="..."-
     * Attribut liest ueber den WebDAV-Transport-Fake, ohne Umweg ueber die
     * Werkbank.
     */
    public function test_text_door_resolves_material_reference_from_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/diagramm.png', self::PNG_BYTES);

        $xml = self::multichoice_xml_with_material_file('Frage mit Bild', 'Fragetext', 'Feedback');

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('erstimport', $result['questions'][0]['status']);
    }

    /**
     * Einbettung direkt aus dem externen Materialbestand (Issue #496) -
     * Verweistuer: xmlpath liest ueber den WebDAV-Transport-Fake.
     */
    public function test_xmlpath_door_imports_from_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/export.xml', self::multichoice_xml_with_embedded_base64(
            'Frage aus Verweistuer (Bestand)', 'Fragetext', 'Feedback'
        ));

        $result = import_questions_xml::execute($categoryid, '', false, 'export.xml');
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('erstimport', $result['questions'][0]['status']);
        $this->assertSame('Frage aus Verweistuer (Bestand)', $result['questions'][0]['name']);
    }

    /**
     * Explizit "ort" = "werkbank" greift weiterhin auf die Werkbank zu, auch
     * wenn der Materialbestand extern liegt (Issue #496).
     */
    public function test_xmlpath_door_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/export.xml', 'nicht das, was gelesen werden soll');
        $this->place_material_file('export.xml', self::multichoice_xml_with_embedded_base64(
            'Frage aus der Werkbank', 'Fragetext', 'Feedback'
        ));

        $result = import_questions_xml::execute(
            $categoryid, '', false, 'export.xml', material_files::ORT_WERKBANK);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);

        $this->assertSame('erstimport', $result['questions'][0]['status']);
        $this->assertSame('Frage aus der Werkbank', $result['questions'][0]['name']);
    }

    /**
     * Die Sperre unterhalb eines Eintrags vom Typ "kontextbereich" (Issue
     * #495, Spec #486 §2/§7) greift auch an der Verweistuer der Einbettung
     * (Issue #496) - kein zweiter Zugang zu Kontextdateien.
     */
    public function test_xmlpath_door_under_kontextbereich_is_rejected(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        \local_coursepilot\storage_anchor::write_pointer_document([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material'],
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
            $this->fail('Ein Pfad unter dem Kontextbereich haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiskontext', $e->errorcode);
        }
    }

    /**
     * Verweistuer: ein Verweis auf eine fehlende Materialdatei bricht mit
     * klarer Meldung ab, kein Teilimport.
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
        $this->assertSame($countbefore, $countafter, 'Nichts wurde geschrieben.');
    }

    /**
     * Beide Tueren im selben Aufruf ⇒ Fehler, keine stille Bevorzugung
     * (Spec 0018 §7.1).
     */
    public function test_both_doors_at_once_is_rejected(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();
        $xml = self::multichoice_xml('Egal', 'Egal', 'Egal');

        $this->expectException(\invalid_parameter_exception::class);
        import_questions_xml::execute($categoryid, $xml, false, 'export.xml');
    }

    /**
     * Weder Text noch Verweis angegeben ⇒ Fehler.
     */
    public function test_neither_door_given_is_rejected(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $this->expectException(\invalid_parameter_exception::class);
        import_questions_xml::execute($categoryid);
    }

    /**
     * Legt eine Datei direkt im Materialordner der angemeldeten Person an -
     * wie {@see \local_coursepilot\material_files::filerecord()}, ohne den
     * Umweg ueber upload_material_file (dessen Endungs-Whitelist z.B. .xml
     * nicht fuehrt, siehe material_files::resolve_file() "lesend, ohne
     * Endungspruefung").
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
     * Eine zu grosse XML wird mit Ist-Groesse und Grenze abgewiesen und
     * nennt einen Ausweg (Ticket #416).
     *
     * Die Schwelle wird ueber den testbaren internen Kern
     * (guard_size_against_limit) per Reflection injiziert, statt eine
     * Mehr-MB-Zeichenkette aufzubauen.
     */
    public function test_oversized_xml_reports_size_and_limit(): void {
        $method = new \ReflectionMethod(import_questions_xml::class, 'guard_size_against_limit');
        $method->setAccessible(true);

        try {
            $method->invoke(null, 2048, 1024);
            $this->fail('Erwartete invalid_parameter_exception wegen Ueberschreitung der Groessengrenze.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString(display_size(2048), $e->getMessage());
            $this->assertStringContainsString(display_size(1024), $e->getMessage());
            $this->assertStringContainsString('aufteilen', $e->getMessage());
        }

        // Unterhalb der Grenze wirft die Methode nicht.
        $method->invoke(null, 100, 1024);
        $this->addToAssertionCount(1);
    }

    /**
     * Die wirksame Grenze ist die Fachgrenze MAX_XML_BYTES, nicht
     * get_max_upload_file_size() - gegen die Uploadgrenze (200 MB neben
     * post_max_size 206 MB) feuerte die Schranke praktisch nie (#424
     * Nachlauf 2).
     */
    public function test_effective_limit_is_the_plugin_constant(): void {
        $method = new \ReflectionMethod(import_questions_xml::class, 'guard_server_size_limit');
        $method->setAccessible(true);

        $this->assertGreaterThan(
            import_questions_xml::MAX_XML_BYTES,
            get_max_upload_file_size(),
            'Testannahme: die Serveruploadgrenze liegt ueber der Fachgrenze.'
        );

        try {
            $method->invoke(null, str_repeat('x', import_questions_xml::MAX_XML_BYTES + 1));
            $this->fail('Erwartete invalid_parameter_exception wegen Ueberschreitung von MAX_XML_BYTES.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString(display_size(import_questions_xml::MAX_XML_BYTES), $e->getMessage());
        }
    }

    /**
     * Ein nacktes <question> ohne <quiz>-Rahmen (der haeufigste Fall: ein
     * von Hand gekuerztes Beispiel, #425 F2) wird mit genau dieser Ursache
     * abgewiesen - nicht mit dem PHP-Innenleben von qformat_xml
     * ("Undefined array key \"quiz\"", #424 Nachlauf 1).
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
     * Ein PHP-Innenleben-Fehler aus dem XML-Kern wird nicht durchgereicht,
     * sondern durch einen fuer die Lehrkraft handlungsleitenden Text ersetzt
     * (#424 Nachlauf 1). Eine echte moodle_exception (z.B. der
     * Formatfehler von xmlize) behaelt dagegen ihren Text - sie ist bereits
     * eine Aussage ueber die Datei, kein Interna-Leck.
     */
    public function test_php_internal_parse_errors_are_replaced(): void {
        $method = new \ReflectionMethod(import_questions_xml::class, 'parse_failure_message');
        $method->setAccessible(true);

        $internal = $method->invoke(null, new \Error('Cannot access offset of type string on string'));
        $this->assertStringNotContainsString('offset', $internal);
        $this->assertStringContainsString('Moodle-XML', $internal);

        $formaterror = new \moodle_exception('errorreadingfile', 'error', '', 'fragen.xml');
        $this->assertSame($formaterror->getMessage(), $method->invoke(null, $formaterror));
    }

    /**
     * "calculated" mit Dataset-Definitionen (#440): question_type::save_question()
     * erwartet fuer diesen Fragetyp nicht die von readquestions() gelieferte
     * Rohform, sondern eine typspezifisch aufbereitete Struktur (dataset als
     * Array zusammengesetzter String-Schluessel). Der generische save()-Pfad
     * bereitet das nicht auf - vor dem Fix ein blanker TypeError, nach dem
     * Fix eine sprechende moodle_exception, nichts wird geschrieben.
     */
    public function test_calculated_with_dataset_definitions_reports_speaking_message(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $xml = self::calculated_xml_with_dataset_definitions();

        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('Erwartete moodle_exception statt eines stillen Erfolgs.');
        } catch (\TypeError $e) {
            $this->fail('TypeError durchgesickert statt sprechender moodle_exception: ' . $e->getMessage());
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('stdClass', $e->getMessage());
            $this->assertStringContainsString('calculated', $e->getMessage());
        }

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nichts wurde geschrieben.');
    }

    /**
     * Baut Kurs + Lehrkraft + Fragensammlung + Kategorie auf und liefert
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
     * Richtet den externen Materialbestand (WebDAV-Fake) fuer eine bereits
     * angemeldete Lehrkraft ein (Issue #496) - siehe
     * {@see \local_coursepilot\external\update_module_settings_test::set_up_external_material_for()}.
     *
     * @param \stdClass $teacher
     * @return \local_coursepilot\tests\webdav\fake_webdav_transport
     */
    private function set_up_external_material_for(\stdClass $teacher): \local_coursepilot\tests\webdav\fake_webdav_transport {
        $this->grant_webdav_capability($teacher);
        $instanceid = $this->create_webdav_instance($teacher);
        $this->write_v2_pointer($teacher, 'materialbestand', $instanceid, 'Material');

        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        return $fake;
    }

    /**
     * Baut ein minimales Moodle-XML mit einer einzelnen multichoice-Frage.
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

    /** @var string Minimaler PNG-Bytestrom (Signatur reicht, Inhalt wird nie dekodiert). */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n";

    /**
     * Wie {@see self::multichoice_xml()}, aber mit einem <file>-Block, der
     * per material="..."-Attribut auf eine Materialordner-Datei verweist
     * (Textuer, Spec 0018 §7.1) statt echtem Base64 zu tragen.
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
     * "calculated"-Frage mit zwei Dataset-Definitionen - Reproduktion aus
     * Issue #440.
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
     * Wie {@see self::multichoice_xml()}, aber mit einem <file>-Block, der
     * bereits echtes Base64 traegt - wie ein fremder Moodle-Export
     * (Verweistuer, Spec 0018 §7.1).
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
