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
 * Die MC-Fassage ueber dem XML-Kern (Spec 0017 §7.1, Ticket #418).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(create_mc_question::class)]
final class create_mc_question_test extends \advanced_testcase {

    /**
     * Anlegen aus schlichten Feldern: generierte idnumber, Version 1,
     * Read-back ueber get_question liefert dieselben Kernfelder.
     */
    public function test_creates_question_with_generated_idnumber_and_version_one(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $result = create_mc_question::execute(
            $categoryid,
            'Additionsfrage',
            'Was ist 2+2?',
            'single',
            [
                ['answer' => '4', 'fraction' => 1.0, 'feedback' => 'Richtig'],
                ['answer' => '5', 'fraction' => 0.0, 'feedback' => 'Falsch'],
            ],
            1.0,
            'Allgemeines Feedback'
        );
        $result = external_api::clean_returnvalue(create_mc_question::execute_returns(), $result);

        $this->assertSame('erstimport', $result['status']);
        $this->assertSame('Additionsfrage', $result['name']);
        $this->assertSame(1, $result['version']);
        $this->assertGreaterThan(0, $result['questionbankentryid']);
        $this->assertGreaterThan(0, $result['questionid']);
        $this->assertStringContainsString((string) $result['questionbankentryid'], $result['meldung']);
        $this->assertStringContainsString('1', $result['meldung']);

        global $DB;
        $entry = $DB->get_record('question_bank_entries', ['id' => $result['questionbankentryid']], '*', MUST_EXIST);
        $this->assertNotEmpty($entry->idnumber, 'Eine idnumber wurde generiert.');

        $readback = get_question::execute($categoryid, 'Additionsfrage');
        $readback = external_api::clean_returnvalue(get_question::execute_returns(), $readback);

        $this->assertSame('Additionsfrage', $readback['name']);
        $this->assertSame('Was ist 2+2?', $readback['questiontext']);
        $this->assertSame('Allgemeines Feedback', $readback['generalfeedback']);
        $this->assertSame('single', $readback['selectionmode']);
        $this->assertCount(2, $readback['answers']);
        $this->assertSame($result['questionbankentryid'], $readback['questionbankentryid']);
        $this->assertSame(1, $readback['version']);
    }

    /**
     * Verdachtsfall: ein gleichnamiger Eintrag existiert bereits in der
     * Zielkategorie - eine Neuanlage bringt nie eine idnumber mit, gegen die
     * gematcht werden koennte, deshalb zaehlt hier schon der Name. Ohne
     * Bestaetigung wird nichts angelegt.
     */
    public function test_suspect_case_for_existing_name_writes_nothing_without_confirmation(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $answers = [
            ['answer' => '4', 'fraction' => 1.0, 'feedback' => ''],
            ['answer' => '5', 'fraction' => 0.0, 'feedback' => ''],
        ];

        $first = create_mc_question::execute($categoryid, 'Dopplung', 'Erste Fassung', 'single', $answers);
        $first = external_api::clean_returnvalue(create_mc_question::execute_returns(), $first);
        $this->assertSame('erstimport', $first['status']);

        global $DB;
        $countbefore = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);

        $second = create_mc_question::execute($categoryid, 'Dopplung', 'Zweite Fassung', 'single', $answers);
        $second = external_api::clean_returnvalue(create_mc_question::execute_returns(), $second);

        $this->assertSame('verdachtsfall', $second['status']);
        $this->assertSame(0, $second['questionbankentryid']);
        $this->assertSame(0, $second['questionid']);
        $this->assertSame($categoryid, $second['categoryid']);
        $this->assertCount(1, $second['candidates']);
        $this->assertSame('Dopplung', $second['candidates'][0]['name']);

        $countafter = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore, $countafter, 'Nichts wurde angelegt.');

        // Bestaetigter Zweitaufruf legt trotzdem einen neuen, eigenen
        // Bank-Eintrag an (eigene idnumber, kein Zusammenfuehren).
        $confirmed = create_mc_question::execute(
            $categoryid, 'Dopplung', 'Zweite Fassung', 'single', $answers, 1.0, '', true);
        $confirmed = external_api::clean_returnvalue(create_mc_question::execute_returns(), $confirmed);

        $this->assertSame('erstimport', $confirmed['status']);
        $this->assertGreaterThan(0, $confirmed['questionbankentryid']);
        $this->assertNotSame($first['questionbankentryid'], $confirmed['questionbankentryid']);

        $countfinal = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        $this->assertSame($countbefore + 1, $countfinal);
    }

    /**
     * Ein "]]>" im Text schliesst den CDATA-Abschnitt der gebauten XML nicht
     * vorzeitig.
     *
     * Fragetext, Allgemein-Feedback, Antworttext und Antwort-Feedback gehen
     * als PARAM_RAW in eine CDATA-Vorlage. Ohne Maskierung zerbricht jede
     * Frage, die die Zeichenfolge enthaelt - im Informatikunterricht (XML,
     * HTML, CDATA selbst) kein Randfall, sondern Unterrichtsstoff.
     */
    public function test_cdata_terminator_in_text_does_not_break_the_xml(): void {
        $this->resetAfterTest();

        [, $categoryid] = $this->setup_course_and_category();

        $questiontext = '<p>Was beendet einen CDATA-Abschnitt? Die Zeichenfolge ]]> beendet ihn.</p>';
        $result = create_mc_question::execute(
            $categoryid,
            'CDATA-Frage',
            $questiontext,
            'single',
            [
                ['answer' => 'Die Zeichenfolge ]]>', 'fraction' => 1.0, 'feedback' => 'Richtig, ]]> beendet ihn.'],
                ['answer' => 'Nichts', 'fraction' => 0.0, 'feedback' => 'Falsch'],
            ],
            1.0,
            'Merke: ]]> ist das Ende.'
        );
        $result = external_api::clean_returnvalue(create_mc_question::execute_returns(), $result);

        $this->assertSame('erstimport', $result['status']);

        $readback = get_question::execute($categoryid, 'CDATA-Frage');
        $readback = external_api::clean_returnvalue(get_question::execute_returns(), $readback);

        $this->assertSame($questiontext, $readback['questiontext']);
        $this->assertSame('Merke: ]]> ist das Ende.', $readback['generalfeedback']);
        $this->assertSame('Die Zeichenfolge ]]>', $readback['answers'][0]['answer']);
        $this->assertSame('Richtig, ]]> beendet ihn.', $readback['answers'][0]['feedback']);
    }

    /**
     * Baut Kurs + Lehrkraft + Fragensammlung + Kategorie auf und liefert
     * [$course, $categoryid].
     *
     * @return array{0: \stdClass, 1: int}
     */
    private function setup_course_and_category(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $bank = ensure_question_bank::execute($course->id, 'MC-Fassade-Test');
        $bank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $bank);

        $category = ensure_question_category::execute('Kategorie', (int) $bank['topcategoryid']);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        return [$course, (int) $category['id']];
    }
}
