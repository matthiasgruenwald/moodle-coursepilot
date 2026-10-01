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
 * Umzug einer Frage samt aller Versionen, mit Verdachtsfall-Gate vor der
 * idnumber-Kollision (Spec 0017 §7.1, Ticket #414).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(move_question::class)]
final class move_question_test extends \advanced_testcase {

    /**
     * Alle Versionen der Frage kommen mit dem Umzug mit, questionbankentryid
     * bleibt unveraendert.
     */
    public function test_moves_question_with_all_versions(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();
        $sourcecategory = ensure_question_category::execute('Quelle', $topcategoryid);
        $sourcecategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $sourcecategory);
        $targetcategory = ensure_question_category::execute('Ziel', $topcategoryid);
        $targetcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $targetcategory);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question(
            'truefalse',
            null,
            ['category' => $sourcecategory['id'], 'idnumber' => 'q-414-move']
        );
        // Zweite Version desselben Bank-Eintrags anlegen.
        $questiongenerator->update_question($question, null, ['category' => $sourcecategory['id']]);

        global $DB;
        $entrybefore = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);
        $allversionsbefore = $DB->get_records(
            'question_versions',
            ['questionbankentryid' => $entrybefore->questionbankentryid]
        );
        $this->assertCount(2, $allversionsbefore, 'Vorbedingung: zwei Versionen vor dem Umzug.');

        $result = move_question::execute($question->id, $targetcategory['id']);
        $result = external_api::clean_returnvalue(move_question::execute_returns(), $result);

        $this->assertSame('verschoben', $result['status']);
        $this->assertSame((int) $entrybefore->questionbankentryid, $result['questionbankentryid']);
        $this->assertCount(2, $result['versionids']);

        $entryafter = $DB->get_record('question_bank_entries', ['id' => $entrybefore->questionbankentryid], '*', MUST_EXIST);
        $this->assertSame($targetcategory['id'], (int) $entryafter->questioncategoryid);
        $this->assertSame('q-414-move', $entryafter->idnumber);
    }

    /**
     * idnumber-Kollision in der Zielkategorie wird VOR dem Umzug gegatet -
     * nichts wird geschrieben.
     */
    public function test_gates_idnumber_collision_without_writing(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();
        $sourcecategory = ensure_question_category::execute('Quelle', $topcategoryid);
        $sourcecategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $sourcecategory);
        $targetcategory = ensure_question_category::execute('Ziel', $topcategoryid);
        $targetcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $targetcategory);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $movingquestion = $questiongenerator->create_question(
            'truefalse',
            null,
            ['category' => $sourcecategory['id'], 'idnumber' => 'q-414-collision']
        );
        $existingquestion = $questiongenerator->create_question(
            'truefalse',
            null,
            ['category' => $targetcategory['id'], 'idnumber' => 'q-414-collision', 'name' => 'Bestehende Frage']
        );

        global $DB;
        $entrybefore = $DB->get_record('question_versions', ['questionid' => $movingquestion->id], '*', MUST_EXIST);
        $categorybefore = $DB->get_field(
            'question_bank_entries',
            'questioncategoryid',
            ['id' => $entrybefore->questionbankentryid],
            MUST_EXIST
        );

        $result = move_question::execute($movingquestion->id, $targetcategory['id']);
        $result = external_api::clean_returnvalue(move_question::execute_returns(), $result);

        $this->assertSame('verdachtsfall', $result['status']);
        $this->assertSame([], $result['versionids']);
        $this->assertSame('q-414-collision', $result['idnumber']);
        $this->assertSame($targetcategory['id'], $result['categoryid']);
        $this->assertCount(1, $result['candidates']);
        $this->assertSame((int) $existingquestion->id, $result['candidates'][0]['questionid']);
        $this->assertSame('Bestehende Frage', $result['candidates'][0]['name']);

        // Nichts geschrieben: Quellkategorie unveraendert.
        $categoryafter = $DB->get_field(
            'question_bank_entries',
            'questioncategoryid',
            ['id' => $entrybefore->questionbankentryid],
            MUST_EXIST
        );
        $this->assertSame((int) $categorybefore, (int) $categoryafter);
    }

    /**
     * Bestaetigter Zweitaufruf fuehrt den Umzug trotz Kollision aus.
     */
    public function test_confirmed_call_moves_despite_collision(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();
        $sourcecategory = ensure_question_category::execute('Quelle', $topcategoryid);
        $sourcecategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $sourcecategory);
        $targetcategory = ensure_question_category::execute('Ziel', $topcategoryid);
        $targetcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $targetcategory);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $movingquestion = $questiongenerator->create_question(
            'truefalse',
            null,
            ['category' => $sourcecategory['id'], 'idnumber' => 'q-414-confirm']
        );
        $questiongenerator->create_question(
            'truefalse',
            null,
            ['category' => $targetcategory['id'], 'idnumber' => 'q-414-confirm']
        );

        $result = move_question::execute($movingquestion->id, $targetcategory['id'], true);
        $result = external_api::clean_returnvalue(move_question::execute_returns(), $result);

        $this->assertSame('verschoben', $result['status']);
        $this->assertCount(1, $result['versionids']);
        $this->assertTrue($result['idnumber_disambiguated']);

        global $DB;
        $version = $DB->get_record('question_versions', ['questionid' => $movingquestion->id], '*', MUST_EXIST);
        $entry = $DB->get_record('question_bank_entries', ['id' => $version->questionbankentryid], '*', MUST_EXIST);
        $this->assertSame($targetcategory['id'], (int) $entry->questioncategoryid);
        $this->assertNotSame('q-414-confirm', $entry->idnumber);
        $this->assertStringStartsWith('q-414-confirm_', $entry->idnumber);
    }

    /**
     * Fehlende moodle/question:add-Berechtigung im Zielkontext wird
     * abgelehnt.
     */
    public function test_rejects_user_without_add_capability_in_target(): void {
        $this->resetAfterTest();

        [$course, $topcategoryid] = $this->setup_course_and_bank();
        $sourcecategory = ensure_question_category::execute('Quelle', $topcategoryid);
        $sourcecategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $sourcecategory);
        $targetcategory = ensure_question_category::execute('Ziel', $topcategoryid);
        $targetcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $targetcategory);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $sourcecategory['id']]);

        $secondteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($secondteacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'moodle/question:add',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($secondteacher);

        $this->expectException(\required_capability_exception::class);

        move_question::execute($question->id, $targetcategory['id']);
    }

    /**
     * Baut Kurs + Lehrkraft + frische Fragensammlung auf und liefert
     * [$course, $topcategoryid].
     *
     * @return array{0: \stdClass, 1: int}
     */
    private function setup_course_and_bank(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $bank = ensure_question_bank::execute($course->id, 'Biologie 9a - Immunsystem');
        $bank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $bank);

        return [$course, (int) $bank['topcategoryid']];
    }

    /**
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
