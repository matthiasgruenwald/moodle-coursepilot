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
 * Fragenbank-Kategorien serverseitig (#342): eigenstaendige Portierung von
 * local_coursepilot\external\get_question_categories, Vertrag (Feldnamen,
 * Top-Kategorie enthalten) identisch zum lokalen Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_question_categories::class)]
final class get_question_categories_test extends \advanced_testcase {

    /**
     * Regelfall: Top-Kategorie plus angelegte Unterkategorien werden mit
     * id, Name und Ueberkategorie-ID geliefert.
     */
    public function test_returns_top_category_and_subcategories(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => $qbankcontext->id,
            'name' => 'Meine Kategorie',
        ]);

        $result = get_question_categories::execute($course->id, $qbank->cmid);
        $result = external_api::clean_returnvalue(get_question_categories::execute_returns(), $result);

        $names = array_column($result, 'name');
        $this->assertContains('top', $names);
        $this->assertContains('Meine Kategorie', $names);

        $found = self::find_category($result, (int) $category->id);
        $this->assertNotNull($found);
        $this->assertGreaterThan(0, $found['parent']);
    }

    /**
     * Fall ohne Berechtigung: local/coursepilot:use fehlt trotz Einschreibung.
     */
    public function test_rejects_user_without_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:use',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);

        $this->expectException(\required_capability_exception::class);

        get_question_categories::execute($course->id, $qbank->cmid);
    }

    /**
     * Fall ohne Einschreibung: eine nicht eingeschriebene Person bekommt
     * keine Daten.
     */
    public function test_rejects_user_without_enrolment(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);

        get_question_categories::execute($course->id, $qbank->cmid);
    }

    /**
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * @param array $result
     * @param int $id
     * @return array|null
     */
    private static function find_category(array $result, int $id): ?array {
        foreach ($result as $category) {
            if ((int) $category['id'] === $id) {
                return $category;
            }
        }
        return null;
    }
}
