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
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

/**
 * Read-modify-write and idnumber backfill for multiple-choice questions
 * (Spec 0017 §7.1, issue #419).
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
     * A questiontext-only patch preserves the name, answers, feedback and
     * partial grades. It creates a new version of the same bank entry.
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

        $this->assertSame('updated', $result['status']);
        $this->assertSame($entryid, $result['questionbankentryid'], 'Neue Version DESSELBEN Bank-Eintrags.');
        $this->assertSame(2, $result['version']);
        $this->assertFalse($result['idnumber_added']);

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'No new bank entry, only a new version.');

        $readback = get_question::execute($categoryid, '', $result['questionid']);
        $readback = external_api::clean_returnvalue(get_question::execute_returns(), $readback);

        // The patched field changed ...
        $this->assertSame('Was ist 3+4?', $readback['questiontext']);
        // ... all other fields remain unchanged: name, defaultmark,
        // general feedback, answer options and their feedback.
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
     * Preserve fields outside fields_json and create_mc_question::build_xml()
     * (penalty, shuffleanswers, answernumbering). The native question-object
     * path leaves them untouched; rebuilding an XML template would silently
     * reset them to fixed defaults.
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

        // Set sentinels directly in the DB to simulate imported questions.
        // build_xml() fixes penalty=0.3333333, shuffleanswers=true and answernumbering="abc".
        $DB->set_field('question', 'penalty', 0.5, ['id' => $created['questionid']]);
        $DB->set_field('qtype_multichoice_options', 'shuffleanswers', 0, ['questionid' => $created['questionid']]);
        $DB->set_field('qtype_multichoice_options', 'answernumbering', '123', ['questionid' => $created['questionid']]);

        $result = update_mc_question::execute(
            $created['questionid'],
            json_encode(['defaultmark' => 3.0])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);
        $this->assertSame('updated', $result['status']);

        $newpenalty = $DB->get_field('question', 'penalty', ['id' => $result['questionid']], MUST_EXIST);
        $newoptions = $DB->get_record(
            'qtype_multichoice_options',
            ['questionid' => $result['questionid']],
            '*',
            MUST_EXIST
        );

        $this->assertEqualsWithDelta(0.5, (float) $newpenalty, 0.0001, 'penalty blieb erhalten.');
        $this->assertEquals(0, $newoptions->shuffleanswers, 'shuffleanswers blieb erhalten.');
        $this->assertSame('123', $newoptions->answernumbering, 'answernumbering blieb erhalten.');
    }

    /**
     * An imported question without an idnumber receives one on its first
     * write. Neighboring questions in the same category remain unchanged.
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
            'question_bank_entries',
            'idnumber',
            ['id' => $neighbour['questionbankentryid']],
            MUST_EXIST
        );

        // Simulate an imported question with no idnumber.
        $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $target['questionbankentryid']]);
        $this->assertEmpty(
            $DB->get_field('question_bank_entries', 'idnumber', ['id' => $target['questionbankentryid']], MUST_EXIST)
        );

        $result = update_mc_question::execute(
            $target['questionid'],
            json_encode(['name' => 'Frage A (korrigiert)'])
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('updated', $result['status']);
        $this->assertTrue($result['idnumber_added']);
        $this->assertSame($target['questionbankentryid'], $result['questionbankentryid'], 'New version, no new entry.');

        $newidnumber = $DB->get_field(
            'question_bank_entries',
            'idnumber',
            ['id' => $target['questionbankentryid']],
            MUST_EXIST
        );
        $this->assertNotEmpty($newidnumber, 'Exactly this one question now has an idnumber.');

        // The neighboring question remains unchanged.
        $unchangedneighbouridnumber = $DB->get_field(
            'question_bank_entries',
            'idnumber',
            ['id' => $neighbour['questionbankentryid']],
            MUST_EXIST
        );
        $this->assertSame($neighbouridnumber, $unchangedneighbouridnumber);
    }

    /**
     * Embed a material image into question text (Spec 0018 §4/§7, #435).
     * questiontext contains @@PLUGINFILE@@ HTML and alt text, following
     * update_module_settings::INTRO_IMAGE_PSEUDOFIELDS (#433).
     * questiontext_images supplies the material path. Verify the entire path
     * from material storage through the reference to a physical file in the
     * question/questiontext file area.
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

        $this->assertSame('updated', $result['status']);
        $this->assertSame($entryid, $result['questionbankentryid'], 'Neue Version DESSELBEN Bank-Eintrags.');
        $this->assertSame(2, $result['version']);

        // Moodle stores @@PLUGINFILE@@ like intro (#433). format_text() resolves
        // pluginfile.php URLs at rendering time. Verify the placeholder, alt text
        // and the physical file rather than a rendered URL.
        $newquestiontext = (string) $DB->get_field('question', 'questiontext', ['id' => $result['questionid']], MUST_EXIST);
        $this->assertStringContainsString('alt="Saeulendiagramm der Messreihe"', $newquestiontext);
        $this->assertStringContainsString('@@PLUGINFILE@@/diagramm.png', $newquestiontext);

        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'diagramm.png');
        $this->assertNotFalse($stored, 'File is physically stored in the question/questiontext filearea.');
        $this->assertSame('Bildinhalt-1', $stored->get_content());
    }

    /**
     * Embed images in individual answer feedback (#435) using feedback_images
     * per answers entry. There is no global field because answers already
     * patches the whole list atomically.
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
        $this->assertSame('updated', $result['status']);

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
     * Uploading the same material filename replaces the image (#428). Later
     * embedding uses the new content because references resolve at embedding time.
     */
    public function test_reembedding_after_material_replace_uses_new_content(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();
        $this->upload_material('diagramm.png', 'Version-1');

        $created = create_mc_question::execute(
            $categoryid,
            'Frage',
            'Text',
            'single',
            [
                ['answer' => 'a', 'fraction' => 1.0, 'feedback' => ''],
                ['answer' => 'b', 'fraction' => 0.0, 'feedback' => ''],
            ]
        );
        $created = external_api::clean_returnvalue(create_mc_question::execute_returns(), $created);

        // Replace material content under the same filename (#428/#432).
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
        $this->assertSame('Version-2', $stored->get_content(), 'Embedding uses the current material content.');
    }

    /**
     * Reject extensions outside the embedding whitelist (Spec 0018 §6) with
     * the same clear message as material uploads and introimages (#433).
     */
    public function test_rejects_disallowed_extension_for_questiontext_embed(): void {
        $this->resetAfterTest();
        global $DB;

        [, $categoryid] = $this->setup_course_and_category();
        $this->upload_material('arbeitsblatt.pdf', 'PDF-Inhalt');

        $created = create_mc_question::execute(
            $categoryid,
            'Frage',
            'Text',
            'single',
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
            // Check extensions before the write transaction; create no partially embedded version.
            $this->assertSame(
                1,
                $DB->count_records('question_versions', ['questionbankentryid' => $created['questionbankentryid']]),
                'No new version created when the embed validation fails beforehand.'
            );
        }
    }

    /**
     * Reject missing material paths clearly instead of writing broken references.
     */
    public function test_reference_to_missing_material_file_fails_with_clear_message(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $created = create_mc_question::execute(
            $categoryid,
            'Frage',
            'Text',
            'single',
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
     * Provides upload material.
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function upload_material(string $path, string $content): void {
        $result = upload_material_file::execute($path, base64_encode($content));
        external_api::clean_returnvalue(upload_material_file::execute_returns(), $result);
    }

    /**
     * Provides stored question file.
     *
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filename
     * @return \stored_file|false
     */
    private function stored_question_file(string $component, string $filearea, int $itemid, string $filename) {
        global $DB;
        // ponytail: query directly. get_area_files() would need the category
        // context; component/filearea/itemid/filename identifies the file here.
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

        $bank = ensure_question_bank::execute($course->id, 'Update-MC-Test');
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
     * Embed directly from external material storage (#496, Spec #486 §7,
     * default location = inventory), without routing through the workbench.
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

        $this->assertSame('updated', $result['status']);
        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'diagramm.png');
        $this->assertNotFalse($stored, 'File is physically stored in the question/questiontext filearea.');
        $this->assertSame('Bildinhalt-1', $stored->get_content());
    }

    /**
     * Explicit location = workbench still uses the workbench when material
     * storage is external (#496).
     */
    public function test_embeds_material_image_with_ort_werkbank_ignores_external_bestand(): void {
        $this->resetAfterTest();

        [, $categoryid, $teacher] = $this->setup_course_and_category();
        $fake = $this->set_up_external_material_for($teacher);
        $fake->seed_file('/Coursepilot/Material/nur-extern.png', 'external');
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
            \local_coursepilot\material_files::LOCATION_WORKBENCH
        );
        $result = external_api::clean_returnvalue(update_mc_question::execute_returns(), $result);

        $this->assertSame('updated', $result['status']);
        $stored = $this->stored_question_file('question', 'questiontext', $result['questionid'], 'werkbank.png');
        $this->assertNotFalse($stored);
        $this->assertSame('aus der Werkbank', $stored->get_content());
    }
}
