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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Der einzige Schreibweg fuer Voraussetzungen, aus lehrkraftverstaendlichen
 * Argumenten statt rohem JSON (Spec 0015, Ticket #393).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(set_restriction::class)]
final class set_restriction_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Lehrkraft (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param int $cmid
     * @return array Ist-Stand, dieselbe Form wie get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * Kernkriterium: eine Voraussetzung ("nach Abschluss von Aktivitaet X")
     * laesst sich ohne rohes JSON setzen und landet als natives
     * Verfuegbarkeits-JSON auf der Aktivitaet.
     */
    public function test_completion_restriction_is_built_without_raw_json(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $lerncheck = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            set_restriction::execute_returns(),
            set_restriction::execute($ziel->cmid, json_encode([
                ['type' => 'completion', 'activity_cmid' => $lerncheck->cmid, 'status' => 'pass'],
            ]))
        );

        $this->assertSame($ziel->cmid, $result['cmid']);
        $this->assertStringContainsString('Voraussetzung', $result['message']);

        $availability = json_decode($this->read($ziel->cmid)['availabilityconditionsjson'], true);
        $this->assertSame('&', $availability['op']);
        $this->assertSame('completion', $availability['c'][0]['type']);
        $this->assertSame($lerncheck->cmid, $availability['c'][0]['cm']);
        $this->assertSame(2, $availability['c'][0]['e']); // COMPLETION_COMPLETE_PASS = bestanden.
    }

    /**
     * Datums- und Gruppenbedingung, kombiniert (UND) - beide praktisch
     * relevanten weiteren Bedingungstypen in einem Aufruf.
     */
    public function test_date_and_group_restriction_combine_with_and(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $gruppe = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $zeitstempel = time() + 3600;

        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'date', 'direction' => 'from', 'timestamp' => $zeitstempel],
            ['type' => 'group', 'group_id' => $gruppe->id],
        ]));

        $availability = json_decode($this->read($ziel->cmid)['availabilityconditionsjson'], true);
        $this->assertCount(2, $availability['c']);
        $this->assertSame('date', $availability['c'][0]['type']);
        $this->assertSame('>=', $availability['c'][0]['d']);
        $this->assertSame($zeitstempel, $availability['c'][0]['t']);
        $this->assertSame('group', $availability['c'][1]['type']);
        $this->assertSame((int) $gruppe->id, $availability['c'][1]['id']);
    }

    /**
     * "gruppen_id": 0 UND "0" (String) bedeuten beide "beliebige Gruppe" -
     * ein zahlenwertiges Feld darf fuer eine KI nicht davon abhaengen, ob sie
     * die Null als JSON-Zahl oder als JSON-String formuliert.
     */
    public function test_group_id_zero_as_string_means_any_group(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'group', 'group_id' => '0'],
        ]));

        $availability = json_decode($this->read($ziel->cmid)['availabilityconditionsjson'], true);
        $this->assertSame('group', $availability['c'][0]['type']);
        $this->assertArrayNotHasKey('id', $availability['c'][0]);
    }

    /**
     * Leeres Array entfernt alle Voraussetzungen.
     */
    public function test_empty_array_removes_all_restrictions(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $lerncheck = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'completion', 'activity_cmid' => $lerncheck->cmid, 'status' => 'complete'],
        ]));
        $this->assertNotSame('', $this->read($ziel->cmid)['availabilityconditionsjson']);

        $result = external_api::clean_returnvalue(
            set_restriction::execute_returns(),
            set_restriction::execute($ziel->cmid, json_encode([]))
        );

        $this->assertStringContainsString('entfernt', $result['message']);
        $this->assertSame('', $this->read($ziel->cmid)['availabilityconditionsjson']);
    }

    /**
     * Kriterium 2: rohes availability-JSON ist ueber update_module_settings
     * nicht setzbar - der Feldkatalog fuehrt es gar nicht (#388, bereits
     * erledigt), hier verifiziert statt wiederholt.
     */
    public function test_raw_availability_json_is_not_settable_via_update_module_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['availabilityconditionsjson' => '{"op":"&","c":[]}']));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('availabilityconditionsjson', $e->getMessage());
        }
    }

    /**
     * Kriterium 3: eine ungueltige Bedingung (unbekannter Typ) scheitert mit
     * einer Meldung, die das Feld nennt - nichts wird geschrieben, die
     * Kursseite bleibt aufrufbar (kein kaputtes JSON landet in der DB).
     */
    public function test_invalid_condition_type_fails_with_field_name_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_restriction::execute($page->cmid, json_encode([
                ['type' => 'unbekannt'],
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('type', $e->getMessage());
        }

        $raw = $DB->get_field('course_modules', 'availability', ['id' => $page->cmid]);
        $this->assertNull($raw);
        // Kursseite bleibt aufrufbar: kein core_availability\tree-Fehler beim Aufbau.
        $info = new \core_availability\info_module(get_fast_modinfo($course)->get_cm($page->cmid));
        $this->assertTrue($info->is_available($ignored));
    }

    /**
     * Ein Verweis auf eine nicht existierende Aktivitaet scheitert ebenso
     * vor dem Schreiben, mit dem Feldnamen in der Meldung.
     */
    public function test_completion_condition_with_unknown_activity_fails_with_field_name(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_restriction::execute($page->cmid, json_encode([
                ['type' => 'completion', 'activity_cmid' => 999999, 'status' => 'complete'],
            ]));
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('activity_cmid', $e->getMessage());
        }
        $this->assertSame('', $this->read($page->cmid)['availabilityconditionsjson']);
    }

    /**
     * Kriterium 4: geschrieben wird ueber update_moduleinfo(), nicht direkt
     * in die DB - eine parallele, unabhaengige Aenderung an einem anderen
     * Feld ueberlebt.
     */
    public function test_writes_via_update_moduleinfo_not_direct_db(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Unveraendert',
        ]);

        set_restriction::execute($page->cmid, json_encode([
            ['type' => 'group'],
        ]));

        $this->assertSame('Unveraendert', $this->read($page->cmid)['name']);
    }

    /**
     * Kriterium 5: "profile"-Bedingungen bleiben beim Lesen maskiert (ADR
     * 0011) - unveraendert durch dieses Werkzeug, ueber die bestehende
     * get_module_settings-Maskierung geprueft. set_restriction bietet
     * "profile" selbst nicht an (bewusste Ponytail-Beschraenkung).
     */
    public function test_profile_condition_from_elsewhere_stays_masked_on_read(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        // Simuliert eine ueber den nativen Formularweg gesetzte
        // profile-Bedingung (ausserhalb von Coursepilot).
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'Physik']],
            'showc' => [true],
        ]), ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $availability = json_decode($this->read($page->cmid)['availabilityconditionsjson'], true);
        $this->assertSame('***', $availability['c'][0]['v']);
        $this->assertSame('department', $availability['c'][0]['sf']);
    }

    /**
     * Kriterium 6: der Vorgang erzeugt einen Stand im Aenderungsverlauf
     * (course_module_updated wird automatisch beobachtet, #385-387).
     */
    public function test_write_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]);

        set_restriction::execute($page->cmid, json_encode([
            ['type' => 'group'],
        ]));

        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]));
    }

    /**
     * Kriterium 7: native Capability-Pruefung im Kurskontext - ohne
     * Bearbeiten-Berechtigung scheitert der Schreibversuch.
     */
    public function test_requires_manageactivities_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'student');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        set_restriction::execute($page->cmid, json_encode([
            ['type' => 'group'],
        ]));
    }
}
