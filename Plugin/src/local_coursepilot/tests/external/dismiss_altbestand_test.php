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
use local_coursepilot\altbestand;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\webdav_instance_fixture;

defined('MOODLE_INTERNAL') || die();

/**
 * Beendet den Altbestand ausdruecklich (Issue #498, Spec #486 §9) - nach dem
 * Muster von {@see dismiss_ausstand_test}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dismiss_altbestand::class)]
final class dismiss_altbestand_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Ein offener Altbestand verschwindet, Kontextbereich/Materialbestand/
     * Ortsverlauf bleiben unveraendert.
     */
    public function test_dismisses_open_altbestand(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->write_pointer_with_vorheriger_ort($user);

        $result = dismiss_altbestand::execute();
        $result = external_api::clean_returnvalue(dismiss_altbestand::execute_returns(), $result);

        $this->assertNotEmpty($result['message']);
        $this->assertFalse(altbestand::open());
        $document = storage_anchor::read_raw_pointer();
        $this->assertSame('coursepilot', $document['kontextbereich']['pfad']);
        $this->assertArrayNotHasKey('vorheriger_ort', $document);
    }

    /**
     * Ohne offenen Altbestand ist es ein benannter Fehler, kein stiller Erfolg.
     */
    public function test_rejects_when_nothing_is_open(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            dismiss_altbestand::execute();
            $this->fail('Ohne offenen Altbestand haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('altbestandclosed', $e->errorcode);
        }
    }

    /**
     * Ohne moodle/user:manageownfiles kein Zugriff - dasselbe Recht wie bei
     * dismiss_ausstand.
     */
    public function test_rejects_missing_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->write_pointer_with_vorheriger_ort($user);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        dismiss_altbestand::execute();
    }

    /**
     * Ein externer Altbestand laesst sich auch ohne moodle/user:manageownfiles
     * beenden (Issue #517, Spec §6: das Recht wirkt extern nicht) - anders
     * als beim Moodle-Altbestand oben.
     */
    public function test_dismisses_external_altbestand_without_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_pointer_with_external_vorheriger_ort($user, $instanceid);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $result = dismiss_altbestand::execute();
        $result = external_api::clean_returnvalue(dismiss_altbestand::execute_returns(), $result);

        $this->assertNotEmpty($result['message']);
        $this->assertFalse(altbestand::open());
    }

    /**
     * Person A beendet nie den Altbestand von Person B.
     */
    public function test_person_a_cannot_dismiss_person_bs_altbestand(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $this->write_pointer_with_vorheriger_ort($teachera);

        $this->setUser($teacherb);
        try {
            dismiss_altbestand::execute();
            $this->fail('Ohne eigenen Altbestand haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('altbestandclosed', $e->errorcode);
        }
        $this->assertTrue(true);

        $this->setUser($teachera);
        $this->assertTrue(altbestand::open(), 'Der Altbestand von Person A darf unberuehrt bleiben.');
    }

    /**
     * Der Endpunkt haengt am Coursepilot-Dienst und steht in der Allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_dismiss_altbestand',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_dismiss_altbestand',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_dismiss_altbestand'));
    }
}
