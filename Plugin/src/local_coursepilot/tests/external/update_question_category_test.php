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
 * Umbenennen/Verschieben einer Fragenbank-Kategorie (Spec 0017 §1,
 * Ticket #413).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(update_question_category::class)]
final class update_question_category_test extends \advanced_testcase {

    /**
     * Reines Umbenennen: Name aendert sich, Elternkategorie bleibt.
     */
    public function test_renames_category(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $category = ensure_question_category::execute('7.2 Alt', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        $result = update_question_category::execute($category['id'], '7.2 Neu');
        $result = external_api::clean_returnvalue(update_question_category::execute_returns(), $result);

        $this->assertTrue($result['renamed']);
        $this->assertFalse($result['moved']);
        $this->assertSame('7.2 Neu', $result['name']);
        $this->assertSame($topcategoryid, $result['parent']);
    }

    /**
     * Reines Verschieben: Elternkategorie aendert sich, Name bleibt.
     */
    public function test_moves_category_under_different_parent(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $subcategory = ensure_question_category::execute('Ziel-Unterkategorie', $topcategoryid);
        $subcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $subcategory);

        $category = ensure_question_category::execute('7.2 Stoffe', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        $result = update_question_category::execute($category['id'], '', $subcategory['id']);
        $result = external_api::clean_returnvalue(update_question_category::execute_returns(), $result);

        $this->assertFalse($result['renamed']);
        $this->assertTrue($result['moved']);
        $this->assertSame('7.2 Stoffe', $result['name']);
        $this->assertSame($subcategory['id'], $result['parent']);
    }

    /**
     * Verschieben in eine andere Fragensammlung (anderer Kontext) zieht den
     * gesamten Unterbaum (inkl. Kind-Kategorien) mit.
     */
    public function test_moves_subtree_context_when_target_bank_differs(): void {
        $this->resetAfterTest();

        [$course, $topcategoryid] = $this->setup_course_and_bank();

        $otherbank = ensure_question_bank::execute($course->id, 'Andere Fragensammlung');
        $otherbank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $otherbank);
        $othertopcategoryid = (int) $otherbank['topcategoryid'];

        $category = ensure_question_category::execute('7.2 Stoffe', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        $child = ensure_question_category::execute('7.2.1 Unterthema', $category['id']);
        $child = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $child);

        $result = update_question_category::execute($category['id'], '', $othertopcategoryid);
        $result = external_api::clean_returnvalue(update_question_category::execute_returns(), $result);

        $this->assertTrue($result['moved']);

        global $DB;
        $movedcategory = $DB->get_record('question_categories', ['id' => $category['id']], '*', MUST_EXIST);
        $movedchild = $DB->get_record('question_categories', ['id' => $child['id']], '*', MUST_EXIST);
        $this->assertSame((int) $result['contextid'], (int) $movedcategory->contextid);
        $this->assertSame((int) $result['contextid'], (int) $movedchild->contextid);
    }

    /**
     * Beim Umzug in eine andere Fragensammlung wandern die Dateien der
     * Fragen mit.
     *
     * Fragebilder liegen als stored_file im Kontext der Fragensammlung. Wer
     * beim Umzug nur die contextid-Spalte der Kategorien umschreibt, laesst
     * sie im alten Kontext zurueck - die Frage zeigt danach ein totes Bild,
     * und zwar erst sichtbar, wenn jemand sie im Test aufschlaegt.
     * Moodle erledigt das in question_move_category_to_context().
     */
    public function test_question_files_move_along_to_the_target_bank(): void {
        $this->resetAfterTest();

        [$course, $topcategoryid] = $this->setup_course_and_bank();

        $otherbank = ensure_question_bank::execute($course->id, 'Zielsammlung');
        $otherbank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $otherbank);

        $category = ensure_question_category::execute('Mit Bild', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        global $DB;
        $sourcecontextid = (int) $DB->get_field('question_categories', 'contextid', ['id' => $category['id']], MUST_EXIST);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category['id']]);

        get_file_storage()->create_file_from_string([
            'contextid' => $sourcecontextid,
            'component' => 'question',
            'filearea' => 'questiontext',
            'itemid' => $question->id,
            'filepath' => '/',
            'filename' => 'zelle.png',
        ], 'nicht-wirklich-ein-png');

        $result = update_question_category::execute($category['id'], '', (int) $otherbank['topcategoryid']);
        $result = external_api::clean_returnvalue(update_question_category::execute_returns(), $result);

        $this->assertTrue($result['moved']);
        $this->assertNotSame($sourcecontextid, (int) $result['contextid']);

        $storage = get_file_storage();
        $this->assertTrue(
            $storage->file_exists((int) $result['contextid'], 'question', 'questiontext', $question->id, '/', 'zelle.png'),
            'Die Datei liegt nach dem Umzug im neuen Kontext.'
        );
        $this->assertFalse(
            $storage->file_exists($sourcecontextid, 'question', 'questiontext', $question->id, '/', 'zelle.png'),
            'Im alten Kontext bleibt nichts zurueck.'
        );
    }

    /**
     * Fragen und ihre Versionen bleiben unangetastet.
     */
    public function test_questions_and_versions_survive_rename_and_move(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $subcategory = ensure_question_category::execute('Ziel-Unterkategorie', $topcategoryid);
        $subcategory = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $subcategory);

        $category = ensure_question_category::execute('7.2 Stoffe', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category['id']]);

        global $DB;
        $entrybefore = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);

        update_question_category::execute($category['id'], '7.2 Stoffe (umbenannt)', $subcategory['id']);

        $questionafter = $DB->get_record('question', ['id' => $question->id], '*', MUST_EXIST);
        $entryafter = $DB->get_record('question_versions', ['questionid' => $question->id], '*', MUST_EXIST);
        $bankentry = $DB->get_record('question_bank_entries', ['id' => $entryafter->questionbankentryid], '*', MUST_EXIST);

        $this->assertSame($question->name, $questionafter->name);
        $this->assertSame((int) $entrybefore->id, (int) $entryafter->id);
        $this->assertSame((int) $entrybefore->version, (int) $entryafter->version);
        $this->assertSame($category['id'], (int) $bankentry->questioncategoryid);
    }

    /**
     * Fall ohne native Berechtigung: moodle/question:managecategory fehlt.
     */
    public function test_rejects_user_without_managecategory_capability(): void {
        $this->resetAfterTest();

        [$course, $topcategoryid] = $this->setup_course_and_bank();

        $category = ensure_question_category::execute('7.2 Stoffe', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

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

        update_question_category::execute($category['id'], '7.2 Neu');
    }

    /**
     * Die oberste Kategorie einer Fragensammlung darf nicht umbenannt oder
     * verschoben werden.
     */
    public function test_rejects_renaming_top_category(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $this->expectException(\invalid_parameter_exception::class);

        update_question_category::execute($topcategoryid, 'Neuer Name');
    }

    /**
     * Zyklus-Schutz: eine Kategorie kann nicht in eine ihrer eigenen
     * Unterkategorien verschoben werden.
     */
    public function test_rejects_move_into_own_descendant(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        $category = ensure_question_category::execute('7.2 Stoffe', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        $child = ensure_question_category::execute('7.2.1 Unterthema', $category['id']);
        $child = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $child);

        $this->expectException(\invalid_parameter_exception::class);

        update_question_category::execute($category['id'], '', $child['id']);
    }

    /**
     * Namenskollision unter demselben Ziel-Elternteil wird abgelehnt.
     */
    public function test_rejects_name_collision_under_target_parent(): void {
        $this->resetAfterTest();

        [, $topcategoryid] = $this->setup_course_and_bank();

        ensure_question_category::execute('Belegter Name', $topcategoryid);
        $category = ensure_question_category::execute('7.2 Stoffe', $topcategoryid);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);

        $this->expectException(\invalid_parameter_exception::class);

        update_question_category::execute($category['id'], 'Belegter Name');
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
