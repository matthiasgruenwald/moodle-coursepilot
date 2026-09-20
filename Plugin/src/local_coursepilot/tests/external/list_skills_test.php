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
use local_coursepilot\pending_write_notice;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Der Skill-Korpus-Katalog (Spec 0020 §4, Issue #450): ohne Kursbindung,
 * 'local/coursepilot:use' im Systemkontext genuegt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(list_skills::class)]
final class list_skills_test extends \advanced_testcase {
    use webdav_instance_fixture;

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
        $this->assertContains('coursepilot', $names);
        $this->assertContains('coursepilot-core', $names);

        foreach ($result['skills'] as $skill) {
            $this->assertArrayNotHasKey('content', $skill);
            $this->assertContains($skill['art'], ['adapter', 'referenz']);
            $this->assertGreaterThan(0, $skill['umfang']);
        }
    }

    /**
     * Ohne 'local/coursepilot:use' im Systemkontext wird abgewiesen.
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
        webdav_instance::set_transport($fake);
        try {
            $aelter = pending_write_notice::record('plan.md', 'anlegen', 'Speicher voll', 0);
            $neuer = pending_write_notice::record('plan.md', 'überschreiben', 'nicht erreichbar', 0);
            pending_write_notice::record('journal.md', 'anhängen', 'Anmeldung abgelehnt', 0);

            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills darf den externen Speicher nicht erreichen.');
            $this->assertCount(2, $result['ausstaende']);
            $this->assertSame('plan.md', $result['ausstaende'][0]['pfad']);
            $this->assertSame([$aelter, $neuer], array_column($result['ausstaende'][0]['eintraege'], 'kennung'));
            $this->assertSame('journal.md', $result['ausstaende'][1]['pfad']);
        } finally {
            webdav_instance::set_transport(null);
        }
    }

    /**
     * Ohne WebDAV-Freischaltung fehlt der Ortswahl-Hinweisfakt (Issue #494
     * Akzeptanzkriterium) - 'hinweise' bleibt ein leeres Array, nie null.
     */
    public function test_hinweise_field_is_empty_without_webdav_freischaltung(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['hinweise']);
    }

    /**
     * Mit Freischaltung und noch offener Ortswahl (kein Kontextpointer)
     * nennt 'hinweise' den Fakt samt Link zur Ortswahlseite - ohne
     * Netzzugriff (Issue #494 Akzeptanzkriterium).
     */
    public function test_hinweise_field_names_open_ortswahl_when_enabled_and_no_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);

        $fake = new fake_webdav_transport();
        webdav_instance::set_transport($fake);
        try {
            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills darf den externen Speicher nicht erreichen.');
            $this->assertCount(1, $result['hinweise']);
            $this->assertStringContainsString('/local/coursepilot/ortswahl.php', $result['hinweise'][0]['link']);
        } finally {
            webdav_instance::set_transport(null);
        }
    }

    /**
     * Sobald ein Kontextpointer existiert, gilt die Ortswahl nicht mehr als
     * offen - der Fakt verschwindet, obwohl die Freischaltung weiterbesteht.
     */
    public function test_hinweise_field_is_empty_once_a_pointer_exists(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        storage_anchor::write_pointer_document([
            'kontextbereich' => 'mein-kontext',
            'materialordner' => 'mein-material',
        ]);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['hinweise']);
    }

    /**
     * Ohne offenen Altbestand fehlt der Hinweisfakt.
     */
    public function test_hinweise_field_has_no_altbestand_hint_by_default(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['hinweise']);
    }

    /**
     * Ein offener Altbestand (Issue #498, Spec #486 §9/§10) erscheint als
     * Fakt in 'hinweise', ohne Zaehlung - der Hinweistext nennt keine Anzahl.
     */
    public function test_hinweise_field_names_open_altbestand_without_counting(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->write_pointer_with_vorheriger_ort($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertCount(1, $result['hinweise']);
        $this->assertSame(
            get_string(
                'listskillsaltbestandhint',
                'local_coursepilot',
                \local_coursepilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE
            ),
            $result['hinweise'][0]['text']
        );
        // Kein Zaehlwert (Issue #498 Akzeptanzkriterium: "ohne Zaehlung") -
        // am deutschen Sprachpaket geprueft, echte Umlaute, keine Ziffern.
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');
        $this->assertDoesNotMatchRegularExpression('/\d/', $string['listskillsaltbestandhint']);
    }

    /**
     * Ein kaputter Kontextpointer (kein gueltiges JSON-Objekt) darf den
     * Handshake nicht scheitern lassen (Issue #519, Spec #486 §10): der
     * Skillkatalog kommt trotzdem, dazu ein benannter Hinweis auf die
     * Ortswahlseite - ohne Netzzugriff.
     */
    public function test_broken_pointer_still_returns_skills_with_named_hint_and_no_network(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-ort.json',
        ], 'kein json');

        $fake = new fake_webdav_transport();
        webdav_instance::set_transport($fake);
        try {
            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills darf den externen Speicher nicht erreichen.');
            $this->assertNotEmpty($result['skills']);
            $this->assertCount(1, $result['hinweise']);
            $this->assertSame(
                get_string(
                    'listskillspointerbrokenhint',
                    'local_coursepilot',
                    \local_coursepilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE
                ),
                $result['hinweise'][0]['text']
            );
            $this->assertStringContainsString('/local/coursepilot/ortswahl.php', $result['hinweise'][0]['link']);
        } finally {
            webdav_instance::set_transport(null);
        }
    }

    /**
     * Ein unvollstaendiger Kontextpointer (gueltiges JSON-Objekt, aber ohne
     * die Pflichtfelder) faellt unter denselben Fakt wie ein unlesbarer
     * Pointer (Issue #519, Spec #486 §10: "unlesbar oder unvollstaendig") -
     * derselbe benannte Hinweis, weiterhin ohne Netzzugriff.
     */
    public function test_incomplete_pointer_still_returns_skills_with_named_hint_and_no_network(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-ort.json',
        ], json_encode(['irgendwas' => 'ohne die Pflichtfelder']));

        $fake = new fake_webdav_transport();
        webdav_instance::set_transport($fake);
        try {
            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills darf den externen Speicher nicht erreichen.');
            $this->assertNotEmpty($result['skills']);
            $this->assertCount(1, $result['hinweise']);
            $this->assertSame(
                get_string(
                    'listskillspointerbrokenhint',
                    'local_coursepilot',
                    \local_coursepilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE
                ),
                $result['hinweise'][0]['text']
            );
        } finally {
            webdav_instance::set_transport(null);
        }
    }
}
