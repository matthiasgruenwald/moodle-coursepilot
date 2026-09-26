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
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

/**
 * Read-modify-write plus idnumber-Backfill fuer MC-Fragen (Spec 0017 §7.1,
 * Ticket #419).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(update_mc_question::class)]
final class update_mc_question_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Kerntest: ein Patch, der nur "questiontext" nennt, laesst Name,
     * Antwortoptionen, Feedbacktexte und Teilbewertungen unveraendert - und
     * schreibt eine neue Version DESSELBEN Bank-Eintrags, keinen neuen.
     */
    public function test_partial_patch_preserves_untouched_fields_as_new_version_of_same_entry(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $created = create_mc_question::execute(
            $categoryid,
            'Additionsfrage',
            'Was ist 2+2?',
            'single',
            [
                ['answer' => '4', 'fraction' => 1.0, 'feedback' => 'Richtig'],
                ['answer' => '5', 'fraction' => 0.0, 'feedback' => 'Falsch'],
            ],
            2.5,
            'Allgemeines Feedback'
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);
        $entryid = $created['questionbankentryid'];

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode(['questiontext' => 'Was ist 3+4?'])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('aktualisiert', $result['status']);
        $this->assertSame($entryid, $result['questionbankentryid'], 'Neue Version DESSELBEN Bank-Eintrags.');
        $this->assertSame(2, $result['version']);
        $this->assertFalse($result['idnumber_added']);

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Kein neuer Bank-Eintrag, nur eine neue Version.');

        $readback = get_question::execute($categoryid, '', $result['questionid']);
        $readback = external_api::clean_returnvalue(get_question::execute_returns(), $readback);

        // Gepatchtes Feld geaendert ...
        $this->assertSame('Was ist 3+4?', $readback['questiontext']);
        // ... alles Uebrige unangetastet: Name, Teilbewertung (defaultmark),
        // allgemeines Feedback, Antwortoptionen inkl. Feedbacktexte.
        $this->assertSame('Additionsfrage', $readback['name']);
        $this->assertEqualsWithDelta(2.5, $readback['defaultmark'], 0.0001);
        $this->assertSame('Allgemeines Feedback', $readback['generalfeedback']);
        $this->assertSame('single', $readback['selectionmode']);
        $this->assertCount(2, $readback['answers']);
        $this->assertSame('4', $readback['answers'][0]['answer']);
        $this->assertEqualsWithDelta(1.0, $readback['answers'][0]['fraction'], 0.0001);
        $this->assertSame('Richtig', $readback['answers'][0]['feedback']);
        $this->assertSame('5', $readback['answers'][1]['answer']);
        $this->assertEqualsWithDelta(0.0, $readback['answers'][1]['fraction'], 0.0001);
        $this->assertSame('Falsch', $readback['answers'][1]['feedback']);
    }

    /**
     * Kein Rekonstruktionsrisiko: Felder, die weder von felder_json NOCH von
     * create_mc_question::build_xml()'s Vorlagenwerten abgedeckt sind
     * (penalty, shuffleanswers, answernumbering - vom Lehrkraft-Vokabular
     * dieses Endpunkts gar nicht adressierbar), bleiben exakt erhalten, weil
     * der native Fragenobjekt-Weg sie nie anfasst - anders als eine erneute
     * Text-XML-Vorlage, die sie stillschweigend auf feste Werte zuruecksetzen
     * wuerde.
     */
    public function test_patch_preserves_fields_outside_the_patchable_vocabulary(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();

        $created = create_mc_question::execute(
            $categoryid,
            'Vorlagenfrage',
            'Fragetext',
            'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        // Sentinel-Werte, die von create_mc_question::build_xml() NIE gesetzt
        // werden (dort fest: penalty 0.3333333, shuffleanswers true,
        // answernumbering "abc") - direkt in der DB gesetzt, um einen
        // Fremdbestand mit abweichenden Werten zu simulieren.
        $DB->set_field('question', 'penalty', 0.5, ['id' => $created['questionid']]);
        $DB->set_field('qtype_multichoice_options', 'shuffleanswers', 0, ['questionid' => $created['questionid']]);
        $DB->set_field('qtype_multichoice_options', 'answernumbering', '123', ['questionid' => $created['questionid']]);

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode(['defaultmark' => 3.0])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);
        $this->assertSame('aktualisiert', $result['status']);

        $newpenalty = $DB->get_field('question', 'penalty', ['id' => $result['questionid']], MUST_EXIST);
        $newoptions = $DB->get_record(
            'qtype_multichoice_options', ['questionid' => $result['questionid']], '*', MUST_EXIST);

        $this->assertEqualsWithDelta(0.5, (float) $newpenalty, 0.0001, 'penalty blieb erhalten.');
        $this->assertEquals(0, $newoptions->shuffleanswers, 'shuffleanswers blieb erhalten.');
        $this->assertSame('123', $newoptions->answernumbering, 'answernumbering blieb erhalten.');
    }

    /**
     * idnumber-Backfill: eine vorgefundene Frage ohne idnumber (simulierter
     * Fremdbestand) bekommt beim ersten Schreibzugriff genau fuer sich eine
     * generiert - eine Nachbarfrage in derselben Kategorie bleibt unberuehrt.
     */
    public function test_idnumber_backfill_touches_only_the_one_question(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();

        $answers = [
            ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
            ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
        ];

        $target = create_mc_question::execute($categoryid, 'Fremdbestand-Frage', 'Frage A', 'single', $answers);
        $target = external_api::clean_returnvalue(create_mc_question::execute_returns(), $target);

        $neighbour = create_mc_question::execute($categoryid, 'Nachbarfrage', 'Frage B', 'single', $answers);
        $neighbour = external_api::clean_returnvalue(create_mc_question::execute_returns(), $neighbour);
        $neighbouridnumber = $DB->get_field(
            'question_bank_entries', 'idnumber', ['id' => $neighbour['questionbankentryid']], MUST_EXIST);

        // Simuliert einen Fremdbestand: die idnumber der Zielfrage fehlt.
        $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $target['questionbankentryid']]);
        $this->assertEmpty(
            $DB->get_field('question_bank_entries', 'idnumber', ['id' => $target['questionbankentryid']], MUST_EXIST));

        $result = update_mc_question::execute(
            $target['questionid'],
            json_encode(['name' => 'Frage A (korrigiert)'])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('aktualisiert', $result['status']);
        $this->assertTrue($result['idnumber_added']);
        $this->assertSame($target['questionbankentryid'], $result['questionbankentryid'], 'Neue Version, kein neuer Eintrag.');

        $newidnumber = $DB->get_field(
            'question_bank_entries', 'idnumber', ['id' => $target['questionbankentryid']], MUST_EXIST);
        $this->assertNotEmpty($newidnumber, 'Genau diese eine Frage hat jetzt eine idnumber.');

        // Nachbarfrage in derselben Kategorie blieb unberuehrt.
        $unchangedneighbouridnumber = $DB->get_field(
            'question_bank_entries', 'idnumber', ['id' => $neighbour['questionbankentryid']], MUST_EXIST);
        $this->assertSame($neighbouridnumber, $unchangedneighbouridnumber);
    }

    /**
     * Ein Bild aus dem Materialordner wird in den Fragetext eingebettet
     * (Spec 0018 §4/§7, Issue #435): questiontext traegt bereits das
     * @@PLUGINFILE@@-Verweis-HTML samt Alt-Text (gleiche Konvention wie
     * update_module_settings::INTRO_IMAGE_PSEUDOFIELDS/#433),
     * questiontext_images nennt den Materialordner-Pfad. Der komplette Weg
     * wird durchlaufen: Materialordner -> Verweisweg -> in der Frage
     * sichtbar (physische Datei in der question/questiontext-Filearea).
     */
    public function test_embeds_material_image_into_questiontext(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();
        $this->upload_material('diagramm.png', 'Bildinhalt-1');

        $created = create_mc_question::execute(
            $categoryid,
            'Diagrammfrage',
            'Alter Fragetext',
            'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);
        $entryid = $created['questionbankentryid'];

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode([
                'questiontext' => '<p>Werte das Diagramm aus:</p>'
                    . '<img src="@@PLUGINFILE@@/diagramm.png" alt="Saeulendiagramm der Messreihe">',
                'questiontext_images' => ['diagramm.png'],
            ])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('aktualisiert', $result['status']);
        $this->assertSame($entryid, $result['questionbankentryid'], 'Neue Version DESSELBEN Bank-Eintrags.');
        $this->assertSame(2, $result['version']);

        // Moodle speichert Text mit dem @@PLUGINFILE@@-Platzhalter in der DB
        // (gleiche Konvention wie intro/#433) - die Aufloesung zur echten
        // pluginfile.php-URL passiert erst beim Rendern (format_text());
        // hier zaehlt, dass Platzhalter UND Alt-Text unangetastet blieben
        // und die Datei physisch existiert (naechste Zeilen).
        $newquestiontext = (string) $DB->get_field('question', 'questiontext', ['id' => $result['questionid']], MUST_EXIST);
        $this->assertStringContainsString('alt="Saeulendiagramm der Messreihe"', $newquestiontext);
        $this->assertStringContainsString('@@PLUGINFILE@@/diagramm.png', $newquestiontext);

        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'diagramm.png');
        $this->assertNotFalse($stored, 'Datei liegt physisch in der question/questiontext-Filearea.');
        $this->assertSame('Bildinhalt-1', $stored->get_content());
    }

    /**
     * Dasselbe fuer das Feedback einer einzelnen Antwortoption (Issue #435):
     * "feedback_images" je answers-Eintrag statt eines globalen Feldes, weil
     * "answers" ohnehin die gesamte Liste patcht (Alles-oder-nichts,
     * bestehende Semantik dieses Endpunkts).
     */
    public function test_embeds_material_image_into_a_single_answer_feedback(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();
        $this->upload_material('kartenausschnitt.png', 'Kartenbild');

        $created = create_mc_question::execute(
            $categoryid,
            'Kartenfrage',
            'Wo liegt die Stadt?',
            'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => 'Richtig'],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => 'Falsch'],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode([
                'answers' => [
                    [
                        'answer' => 'a',
                        'fraction' => 1.0,
                        'feedback' => 'Richtig, siehe Karte: '
                            . '<img src="@@PLUGINFILE@@/kartenausschnitt.png" alt="Kartenausschnitt">',
                        'feedback_images' => ['kartenausschnitt.png'],
                    ],
                    ['answer' => 'b', 'fraction' => 0.0, 'feedback' => 'Falsch'],
                ],
            ])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);
        $this->assertSame('aktualisiert', $result['status']);

        $answers = array_values($DB->get_records('question_answers', ['question' => $result['questionid']], 'id ASC'));
        $this->assertCount(2, $answers);
        $this->assertStringContainsString('alt="Kartenausschnitt"', $answers[0]->feedback);
        $this->assertStringContainsString('@@PLUGINFILE@@/kartenausschnitt.png', $answers[0]->feedback);
        $this->assertSame('Falsch', $answers[1]->feedback, 'Zweite Antwortoption unangetastet.');

        $stored = $this->stored_question_file('question', 'answerfeedback', (int) $answers[0]->id, 'kartenausschnitt.png');
        $this->assertNotFalse($stored);
        $this->assertSame('Kartenbild', $stored->get_content());
    }

    /**
     * Ein zweites Bild mit demselben Dateinamen ersetzt das vorhandene
     * Materialbild (upload_material_file, Issue #428) statt still ein
     * zweites anzulegen - eine spaetere Einbettung nutzt automatisch den
     * neuen Inhalt, weil der Verweisweg zur Einbettzeit aufloest.
     */
    public function test_reembedding_after_material_replace_uses_new_content(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();
        $this->upload_material('diagramm.png', 'Version-1');

        $created = create_mc_question::execute(
            $categoryid, 'Frage', 'Text', 'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        // Materialbild ersetzt (gleicher Dateiname, neuer Inhalt) - Kern von #428/#432.
        $this->upload_material('diagramm.png', 'Version-2');

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode([
                'questiontext' => '<img src="@@PLUGINFILE@@/diagramm.png" alt="Diagramm">',
                'questiontext_images' => ['diagramm.png'],
            ])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'diagramm.png');
        $this->assertSame('Version-2', $stored->get_content(), 'Einbettung nutzt den aktuellen Materialinhalt.');
    }

    /**
     * Eine Endung ausserhalb der engeren Einbett-Whitelist (Spec 0018 §6)
     * scheitert mit derselben klaren Meldung wie beim Materialupload -
     * genau wie introimages (#433).
     */
    public function test_rejects_disallowed_extension_for_questiontext_embed(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();
        $this->upload_material('arbeitsblatt.pdf', 'PDF-Inhalt');

        $created = create_mc_question::execute(
            $categoryid, 'Frage', 'Text', 'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        try {
            update_mc_question::execute(
                $created['questionid'],
                json_encode([
                    'questiontext' => '<img src="@@PLUGINFILE@@/arbeitsblatt.pdf" alt="geht nicht">',
                    'questiontext_images' => ['arbeitsblatt.pdf'],
                ])
            );
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            // Alles-oder-nichts: die Endungspruefung laeuft VOR der
            // Schreib-Transaktion, es entsteht keine halb-eingebettete
            // neue Version.
            $this->assertSame(
                1,
                $DB->count_records('question_versions', ['questionbankentryid' => $created['questionbankentryid']]),
                'Keine neue Version angelegt, wenn die Einbett-Validierung vorher scheitert.'
            );
        }
    }

    /**
     * Ein referenzierter, aber nicht vorhandener Materialordner-Pfad
     * scheitert mit klarer Meldung statt stillschweigend eine kaputte
     * Referenz zu schreiben.
     */
    public function test_reference_to_missing_material_file_fails_with_clear_message(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $created = create_mc_question::execute(
            $categoryid, 'Frage', 'Text', 'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        $this->expectException(\moodle_exception::class);
        update_mc_question::execute(
            $created['questionid'],
            json_encode([
                'questiontext' => '<img src="@@PLUGINFILE@@/gibtsnicht.png" alt="fehlt">',
                'questiontext_images' => ['gibtsnicht.png'],
            ])
        );
    }

    /**
     * @param string $path
     * @param string $content
     * @return void
     */
    private function upload_material(string $path, string $content): void {
        $result = upload_material_file::execute($path, base64_encode($content));
        external_api::clean_returnvalue(upload_material_file::execute_returns(), $result);
    }

    /**
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     * @return \stored_file|false
     */
    private function stored_question_file(string $component, string $filearea, int $itemid, string $filename) {
        global $DB;
        // ponytail: direkt per SQL statt ueber get_area_files() - dafuer
        // braeuchte man den Kategoriekontext, den component/filearea/itemid/
        // filename hier eindeutig genug identifizieren.
        $record = $DB->get_record('files', [
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filename' => $filename,
        ]);
        if (!$record) {
            return false;
        }
        return get_file_storage()->get_file_by_id($record->id);
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

        $bank = ensure_question_bank::execute($course->id, 'Update-MC-Test');
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
     * Einbettung direkt aus dem externen Materialbestand (Issue #496, Spec
     * #486 §7, Default "ort" = "bestand"): kein Umweg ueber die Werkbank.
     */
    public function test_embeds_material_image_from_external_bestand_into_questiontext(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/diagramm.png', 'Bildinhalt-1');

        $created = create_mc_question::execute(
            $categoryid,
            'Diagrammfrage',
            'Alter Fragetext',
            'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode([
                'questiontext' => '<p>Werte das Diagramm aus:</p>'
                    . '<img src="@@PLUGINFILE@@/diagramm.png" alt="Saeulendiagramm der Messreihe">',
                'questiontext_images' => ['diagramm.png'],
            ])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('aktualisiert', $result['status']);
        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'diagramm.png');
        $this->assertNotFalse($stored, 'Datei liegt physisch in der question/questiontext-Filearea.');
        $this->assertSame('Bildinhalt-1', $stored->get_content());
    }

    /**
     * Explizit "ort" = "werkbank" greift weiterhin auf die Werkbank zu, auch
     * wenn der Materialbestand extern liegt (Issue #496).
     */
    public function test_embeds_material_image_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/nur-extern.png', 'extern');
        $this->upload_material('werkbank.png', 'aus der Werkbank');

        $created = create_mc_question::execute(
            $categoryid,
            'Diagrammfrage',
            'Alter Fragetext',
            'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode([
                'questiontext' => '<img src="@@PLUGINFILE@@/werkbank.png" alt="aus der Werkbank">',
                'questiontext_images' => ['werkbank.png'],
            ]),
            false,
            \local_coursepilot\material_files::ORT_WERKBANK
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('aktualisiert', $result['status']);
        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'werkbank.png');
        $this->assertNotFalse($stored);
        $this->assertSame('aus der Werkbank', $stored->get_content());
    }
}
