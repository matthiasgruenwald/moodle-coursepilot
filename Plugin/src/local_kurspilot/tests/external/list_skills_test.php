<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\external;

use core_external\external_api;
use local_kurspilot\ausstand_notice;
use local_kurspilot\tests\webdav\fake_webdav_transport;
use local_kurspilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Skill-Korpus-Katalog (Spec 0020 §4, Issue #450): ohne Kursbindung,
 * 'local/kurspilot:use' im Systemkontext genuegt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(list_skills::class)]
final class list_skills_test extends \advanced_testcase {

    /**
     * Nennt je Eintrag Name, Auslöser, Art und Umfang - keinen Inhalt, ohne
     * dass ein Kurs existiert oder die Lehrkraft in einem eingeschrieben ist.
     */
    public function test_lists_catalog_without_course_binding(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertNotEmpty($result['skills']);
        $names = array_column($result['skills'], 'name');
        $this->assertContains('kurspilot', $names);
        $this->assertContains('kurspilot-core', $names);

        foreach ($result['skills'] as $skill) {
            $this->assertArrayNotHasKey('content', $skill);
            $this->assertContains($skill['art'], ['adapter', 'referenz']);
            $this->assertGreaterThan(0, $skill['umfang']);
        }
    }

    /**
     * Ohne 'local/kurspilot:use' im Systemkontext wird abgewiesen.
     */
    public function test_without_capability_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        list_skills::execute();
    }

    /**
     * Ohne offene Ausstaende liefert das Feld ein leeres Array, nie null
     * (Issue #492, ADR 0023 Punkt 4).
     */
    public function test_ausstaende_field_is_empty_by_default(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['ausstaende']);
    }

    /**
     * Offene Ausstaende sind je Zieldatei gebuendelt, die aeltesten zuerst
     * (Issue #492, ADR 0023 Punkt 4) - und der Handshake sieht dabei keinen
     * Netzzugriff: der WebDAV-Fake protokolliert keine Anfrage.
     */
    public function test_ausstaende_bundled_by_path_oldest_first_without_network_access(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $fake = new fake_webdav_transport();
        webdav_instance::use_test_transport($fake);
        try {
            $aelter = ausstand_notice::record('plan.md', 'anlegen', 'Speicher voll');
            $neuer = ausstand_notice::record('plan.md', 'überschreiben', 'nicht erreichbar');
            ausstand_notice::record('journal.md', 'anhängen', 'Anmeldung abgelehnt');

            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'kurspilot_list_skills darf den externen Speicher nicht erreichen.');
            $this->assertCount(2, $result['ausstaende']);
            $this->assertSame('plan.md', $result['ausstaende'][0]['pfad']);
            $this->assertSame([$aelter, $neuer], array_column($result['ausstaende'][0]['eintraege'], 'kennung'));
            $this->assertSame('journal.md', $result['ausstaende'][1]['pfad']);
        } finally {
            webdav_instance::use_test_transport(null);
        }
    }
}
