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
use local_coursepilot\catalog\module_state;
use local_coursepilot\catalog\registry;

/**
 * Kurskatalog serverseitig (#341): der Vertrag ist die reine Delegation an
 * local_coursepilot\external\get_course_catalog - dieser Test belegt, dass
 * die Delegation tatsaechlich denselben Vertrag liefert (Feldnamen,
 * Maskierung des Personenbezugs, keine Gruppennamen), statt es blind
 * anzunehmen.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_course_catalog::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(module_state::class)]
final class get_course_catalog_test extends \advanced_testcase {

    /**
     * Alle freigegebenen Modultypen liefern ihren Zustand aus dem Katalog.
     * Die bisherigen Settings-Schluessel der Sonderleser bleiben dabei Teil
     * des unveraenderten Katalogvertrags.
     */
    public function test_catalog_reads_every_registered_module_type(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $instances = [];
        foreach (registry::known_modnames() as $modname) {
            $instances[$modname] = $this->getDataGenerator()
                ->get_plugin_generator('mod_' . $modname)
                ->create_instance(['course' => $course->id, 'name' => 'Katalog ' . $modname]);
        }

        $catalog = get_course_catalog::execute($course->id, -1, '', 'full');
        foreach ($instances as $modname => $instance) {
            $module = self::find_module($catalog, (int) $instance->cmid);
            $this->assertNotNull($module, "$modname fehlt im Katalog.");
            if ($modname !== 'label') {
                $this->assertSame('Katalog ' . $modname, $module['name']);
            }
            $this->assertArrayHasKey('content', $module);
            $this->assertArrayHasKey('settings', $module);
            $this->assertArrayHasKey('quizslots', $module);
        }

        $modules = [];
        foreach ($catalog['sections'] as $section) {
            foreach ($section['modules'] as $module) {
                $modules[$module['modname']] = $module;
            }
        }
        $this->assertSame(['intro'], array_column($modules['page']['settings'], 'name'));
        $this->assertSame(['externalurl'], array_column($modules['url']['settings'], 'name'));
        $this->assertSame([
            'duedate', 'allowsubmissionsfromdate', 'cutoffdate', 'gradingduedate', 'completionsubmit', 'grade',
            'gradepass', 'submissiondrafts', 'maxattempts', 'attemptreopenmethod', 'requiresubmissionstatement',
            'teamsubmission', 'requireallteammemberssubmit', 'teamsubmissiongroupingid', 'sendnotifications',
            'sendlatenotifications', 'sendstudentnotifications', 'blindmarking', 'markingworkflow',
            'markingallocation', 'gradecat', 'gradingmethod', 'additionalfiles', 'onlinetext_enabled',
            'onlinetext_wordlimit_enabled', 'onlinetext_wordlimit', 'submission_file_enabled',
            'submission_file_maxfiles', 'submission_file_maxsizebytes', 'submission_file_filetypes',
            'feedback_comments_enabled', 'feedback_editpdf_enabled', 'feedback_file_enabled',
            'feedback_file_maxfiles', 'feedback_file_maxsizebytes', 'feedback_file_filetypes', 'feedback_offline_enabled',
        ], array_column($modules['assign']['settings'], 'name'));
        $this->assertSame(
            ['preferredbehaviour', 'attempts', 'grademethod', 'timelimit', 'grade', 'gradepass', 'grademax'],
            array_column($modules['quiz']['settings'], 'name')
        );
        foreach (['label', 'folder', 'resource', 'choice', 'forum'] as $modname) {
            $this->assertSame([], $modules[$modname]['settings']);
        }
    }

    /**
     * Der Katalog liefert Abschnitte, Inhalte, Sichtbarkeit, Abschluss und
     * Voraussetzungen - Grundvertrag, identisch zum lokalen Werkzeug.
     */
    public function test_catalog_covers_sections_content_completion_and_availability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = get_course_catalog::execute($course->id);
        $result = external_api::clean_returnvalue(get_course_catalog::execute_returns(), $result);

        $this->assertSame('aus Moodle gelesen', $result['source']);
        $this->assertSame((int) $course->id, $result['courseid']);
        $this->assertArrayHasKey('filters', $result);
        $this->assertArrayHasKey('sections', $result);

        $module = self::find_module($result, (int) $page->cmid);
        $this->assertNotNull($module, 'Die angelegte Seite muss im Katalog auftauchen.');
        $this->assertArrayHasKey('visible', $module);
        $this->assertArrayHasKey('completion', $module);
        $this->assertArrayHasKey('availability', $module);
        $this->assertArrayHasKey('content', $module);
    }

    /**
     * detail=full liefert Vollinhalte, detail=compact (Standard) nicht.
     */
    public function test_full_detail_returns_content_compact_does_not(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'content' => 'Voller Seiteninhalt fuer den Vollmodus-Test.',
        ]);

        $compact = get_course_catalog::execute($course->id, -1, '', 'compact');
        $full = get_course_catalog::execute($course->id, -1, '', 'full');

        $compactmodule = self::find_module($compact, (int) $page->cmid);
        $fullmodule = self::find_module($full, (int) $page->cmid);

        $this->assertSame('', $compactmodule['content']['html']);
        $this->assertStringContainsString('Voller Seiteninhalt', $fullmodule['content']['html']);
    }

    /**
     * Kernkriterium #341: eine echte Profilbeschraenkung (Fachgruppe) wird
     * maskiert - Typ, Feld und Operator bleiben, der Wert wird ersetzt.
     * Weglassen waere schlimmer als Maskieren, deshalb muss der Schluessel
     * "type"/"sf"/"op" bestehen bleiben und nur "v" veraendert werden.
     */
    public function test_profile_restriction_value_is_masked_but_type_field_operator_remain(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $availability = json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'profile', 'sf' => 'department', 'op' => 'contains', 'v' => 'Mathe-FG'],
            ],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $page->cmid]);

        $result = get_course_catalog::execute($course->id);
        $module = self::find_module($result, (int) $page->cmid);

        $decoded = json_decode($module['availability'], true);
        $condition = $decoded['c'][0];

        $this->assertSame('profile', $condition['type']);
        $this->assertSame('department', $condition['sf']);
        $this->assertSame('contains', $condition['op']);
        $this->assertSame('***', $condition['v']);
        $this->assertStringNotContainsString('Mathe-FG', $module['availability']);
    }

    /**
     * Gruppennamen erscheinen nie im Katalog - nur der Gruppenmodus als
     * Zahl, keine Gruppen-/Namensliste.
     */
    public function test_group_names_never_appear_only_groupmode(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['groupmode' => SEPARATEGROUPS]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Gruppe Mathe-FG']);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'groupmode' => SEPARATEGROUPS,
        ]);

        $result = get_course_catalog::execute($course->id);
        $module = self::find_module($result, (int) $page->cmid);

        $this->assertIsInt($module['groupmode']);
        $this->assertArrayNotHasKey('groupname', $module);
        $this->assertArrayNotHasKey('groups', $module);
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('Gruppe Mathe-FG', $encoded);
    }

    /**
     * Die eigene Capability local/coursepilot:use wird durchgesetzt, nicht nur
     * die des delegierten lokalen Werkzeugs (local/coursepilot:use) - sonst
     * waere die in db/services.php und privacy_surface deklarierte
     * Capability reine Metadaten ohne Wirkung (Fund aus dem Code-Review zu
     * #341). Eine Lehrkraft mit local/coursepilot:use, aber ohne
     * local/coursepilot:use, muss abgewiesen werden.
     */
    public function test_rejects_user_with_only_coursepilot_capability(): void {
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

        $this->expectException(\required_capability_exception::class);

        get_course_catalog::execute($course->id);
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
     * @param int $cmid
     * @return array|null
     */
    private static function find_module(array $result, int $cmid): ?array {
        foreach ($result['sections'] as $section) {
            foreach ($section['modules'] as $module) {
                if ((int) $module['cmid'] === $cmid) {
                    return $module;
                }
            }
        }
        return null;
    }
}
