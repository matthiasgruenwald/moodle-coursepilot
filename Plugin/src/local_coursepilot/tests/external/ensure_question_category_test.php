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
 * Ensure-Finden-oder-Anlegen einer Fragenbank-Kategorie (Spec 0017 §1,
 * Ticket #412).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ensure_question_category::class)]
final class ensure_question_category_test extends \advanced_testcase {

    /**
     * Neuanlage unter der Top-Kategorie einer frischen Fragensammlung.
     */
    public function test_creates_new_category(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $result = ensure_question_category::execute('7.2 Stoffe und ihre Eigenschaften', $topcategoryid);
        $result = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $result);

        $this->assertTrue($result['angelegt']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame($topcategoryid, $result['parent']);
        $this->assertSame('7.2 Stoffe und ihre Eigenschaften', $result['name']);
        $this->assertStringContainsString('angelegt', $result['meldung']);
    }

    /**
     * Wiederverwendung: gleicher Name unter demselben Elternteil liefert die
     * bestehende Kategorie statt einer zweiten.
     */
    public function test_reuses_existing_category_with_same_name_and_parent(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $first = ensure_question_category::execute('7.2 Stoffe und ihre Eigenschaften', $topcategoryid);
        $first = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $first);

        $second = ensure_question_category::execute('7.2 Stoffe und ihre Eigenschaften', $topcategoryid);
        $second = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $second);

        $this->assertFalse($second['angelegt']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertStringContainsString('wiederverwendet', $second['meldung']);

        global $DB;
        $count = $DB->count_records('question_categories', [
            'name' => '7.2 Stoffe und ihre Eigenschaften',
            'parent' => $topcategoryid,
        ]);
        $this->assertSame(1, $count);
    }

    /**
     * Eine gleichnamige Kategorie unter einer anderen Elternkategorie ist
     * kein Treffer - es entsteht eine zweite, eigenstaendige Kategorie.
     */
    public function test_same_name_under_different_parent_is_not_a_match(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $othersubcategory = ensure_question_category::execute('Andere Unterkategorie', $topcategoryid);
        $othersubcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $othersubcategory);

        $undertop = ensure_question_category::execute('7.2 Stoffe und ihre Eigenschaften', $topcategoryid);
        $undertop = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $undertop);

        $underother = ensure_question_category::execute('7.2 Stoffe und ihre Eigenschaften', $othersubcategory['id']);
        $underother = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $underother);

        $this->assertTrue($underother['angelegt']);
        $this->assertNotSame($undertop['id'], $underother['id']);

        global $DB;
        $count = $DB->count_records('question_categories', ['name' => '7.2 Stoffe und ihre Eigenschaften']);
        $this->assertSame(2, $count);
    }

    /**
     * Fall ohne native Berechtigung: moodle/question:managecategory fehlt.
     *
     * Prohibit wird vor dem setUser() der zweiten Lehrkraft gesetzt (Muster
     * aus get_question_categories_test::test_rejects_user_without_capability()),
     * damit kein in-process Capability-Cache umgangen werden muss.
     */
    public function test_rejects_user_without_managecategory_capability(): void {
        $this->resetAfterTest();

        [$course, $topcategoryid] = $this->setup_course_and_bank();

        $secondteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($secondteacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'moodle/question:managecategory',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($secondteacher);

        $this->expectException(\required_capability_exception::class);

        ensure_question_category::execute('7.2 Stoffe und ihre Eigenschaften', $topcategoryid);
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
