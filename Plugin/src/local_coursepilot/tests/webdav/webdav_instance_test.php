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

namespace local_coursepilot\webdav;

use local_coursepilot\pointer_location;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Die Auflösungsprüfungen einer WebDAV-Nutzerinstanz, ohne Netz (Issue #490,
 * Spec #486 §2): Existenz, Instanzeigentum (inkl. "Login as"),
 * WebDAV-Freischaltung, https+Basic, Prüfmerkmal. Jeder Verstoß ein
 * benannter Fehler.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_instance::class)]
final class webdav_instance_test extends \advanced_testcase {
    use webdav_instance_fixture;

    private function location(int $instanceid, ?array $fingerprint = null): pointer_location {
        return pointer_location::extern($instanceid, 'Coursepilot-Kontext', $fingerprint ?? $this->fixture_fingerprint());
    }

    public function test_resolves_valid_instance_to_credentials_and_base_url(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $resolved = webdav_instance::resolve($this->location($instanceid));

        $this->assertSame(
            'https://cloud.example.test/Coursepilot/Coursepilot-Kontext',
            $resolved->file_url('Coursepilot-Kontext')
        );
    }

    /**
     * Moodles WebDAV-Formular speichert "kein Port" als '0' - das darf nie als
     * ":0" in der Adresse landen (Live-Abnahme #505: jede Anfrage lief in den Timeout).
     */
    public function test_port_zero_means_default_port(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_port' => '0']);

        $resolved = webdav_instance::resolve($this->location($instanceid));

        $this->assertSame('https://cloud.example.test/Coursepilot/Coursepilot-Kontext', $resolved->file_url('Coursepilot-Kontext'));
    }

    public function test_explicit_port_is_kept(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_port' => '8443']);

        $resolved = webdav_instance::resolve($this->location($instanceid));

        $this->assertSame('https://cloud.example.test:8443/Coursepilot/Coursepilot-Kontext', $resolved->file_url('Coursepilot-Kontext'));
    }

    public function test_missing_instance_throws_named_error(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $this->enable_webdav_repository_type();

        try {
            webdav_instance::resolve($this->location(999999));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavinstancemissing', $e->errorcode);
        }
    }

    public function test_instance_owned_by_another_person_is_rejected(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $attacker = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->grant_webdav_capability($owner);
        $instanceid = $this->create_webdav_instance($owner);

        $this->setUser($attacker);
        $this->grant_webdav_capability($attacker);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavinstanceforeign', $e->errorcode);
        }
    }

    /**
     * "Login as" darf eine fremde Person nicht ueber deren eigenen
     * Nutzerkontext hinweg an die WebDAV-Instanz heranfuehren, selbst wenn
     * `$USER` waehrenddessen formal die Zielperson ist (Spec §2 Pruefung 3).
     */
    public function test_loginas_session_is_rejected_even_for_the_owning_context(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_webdav_capability($user);
        $this->setUser($user);
        $instanceid = $this->create_webdav_instance($user);

        $this->setAdminUser();
        \core\session\manager::loginas($user->id, \context_system::instance());

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavinstanceforeign', $e->errorcode);
        }
    }

    public function test_missing_freischaltung_throws_named_error(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Keine Capability zugewiesen - Schritt 3 fehlt.
        $this->enable_webdav_repository_type();
        $instanceid = $this->create_webdav_instance($user);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavnotenabled', $e->errorcode);
        }
    }

    public function test_revoked_freischaltung_fails_immediately(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        // Bisher gueltig ...
        webdav_instance::resolve($this->location($instanceid));

        // ... ein Entzug wirkt sofort, ohne Zwischenspeicher.
        set_config('enableuserinstances', 0, 'webdav');
        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavnotenabled', $e->errorcode);
        }
    }

    public function test_non_ssl_type_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_type' => 0]);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavauthunsupported', $e->errorcode);
        }
    }

    public function test_digest_auth_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_auth' => 'digest']);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavauthunsupported', $e->errorcode);
        }
    }

    public function test_none_auth_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_auth' => 'none']);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavauthunsupported', $e->errorcode);
        }
    }

    public function test_changed_server_fails_fingerprint_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_server' => 'andere-cloud.example.test']);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavfingerprintchanged', $e->errorcode);
        }
    }

    public function test_changed_basispfad_fails_fingerprint_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_path' => 'Anderer-Pfad']);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavfingerprintchanged', $e->errorcode);
        }
    }

    public function test_changed_konto_fails_fingerprint_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_user' => 'anderes-konto']);

        try {
            webdav_instance::resolve($this->location($instanceid));
            $this->fail('Erwartete moodle_exception ist ausgeblieben.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavfingerprintchanged', $e->errorcode);
        }
    }

    /**
     * Der Geheimnis-Test (Spec Testing Decisions): das Passwort der Instanz
     * taucht in keiner der Fehlermeldungen auf, ueber alle Fehlerklassen
     * hinweg.
     */
    public function test_password_never_leaks_into_any_exception_message(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $password = 'g3h31m-nie-sichtbar-' . random_string(8);
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_password' => $password]);

        $attempts = [
            fn () => webdav_instance::resolve($this->location(999999)),
            fn () => webdav_instance::resolve($this->location($instanceid, ['server' => 'x', 'basispfad' => 'y', 'konto' => 'z'])),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Erwartete moodle_exception ist ausgeblieben.');
            } catch (\moodle_exception $e) {
                $this->assertStringNotContainsString($password, $e->getMessage());
            }
        }
    }

    // --- Issue #497: has_supported_auth(), IServ-Erkennung ---

    public function test_has_supported_auth_is_true_for_https_basic_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $this->assertTrue(webdav_instance::has_supported_auth($instanceid));
    }

    public function test_has_supported_auth_is_false_without_https(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_type' => 0]);

        $this->assertFalse(webdav_instance::has_supported_auth($instanceid));
    }

    public function test_has_supported_auth_is_false_for_digest(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_auth' => 'digest']);

        $this->assertFalse(webdav_instance::has_supported_auth($instanceid));
    }

    public function test_has_supported_auth_is_false_for_none(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user, ['webdav_auth' => 'none']);

        $this->assertFalse(webdav_instance::has_supported_auth($instanceid));
    }

    public function test_is_iserv_listing_is_true_for_exactly_the_five_areas(): void {
        $entries = [
            ['name' => 'Windows', 'type' => 'folder'],
            ['name' => 'Files', 'type' => 'folder'],
            ['name' => 'Groups', 'type' => 'folder'],
            ['name' => 'Print', 'type' => 'folder'],
            ['name' => 'Temp', 'type' => 'folder'],
        ];

        $this->assertTrue(webdav_instance::is_iserv_listing($entries));
    }

    public function test_is_iserv_listing_is_false_for_a_regular_nextcloud_root(): void {
        $entries = [
            ['name' => 'Dokumente', 'type' => 'folder'],
            ['name' => 'Fotos', 'type' => 'folder'],
        ];

        $this->assertFalse(webdav_instance::is_iserv_listing($entries));
    }

    public function test_is_iserv_listing_is_false_with_an_extra_folder(): void {
        $entries = [
            ['name' => 'Files', 'type' => 'folder'],
            ['name' => 'Groups', 'type' => 'folder'],
            ['name' => 'Print', 'type' => 'folder'],
            ['name' => 'Temp', 'type' => 'folder'],
            ['name' => 'Windows', 'type' => 'folder'],
            ['name' => 'Extra', 'type' => 'folder'],
        ];

        $this->assertFalse(webdav_instance::is_iserv_listing($entries));
    }

    public function test_detect_iserv_root_reads_the_instance_root_over_the_network(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $fake = new fake_webdav_transport();
        $fake->as_iserv_root('/' . $this->fixturebasispfad);
        webdav_instance::set_transport($fake);

        try {
            $this->assertTrue(webdav_instance::detect_iserv_root($instanceid));
        } finally {
            webdav_instance::set_transport(null);
        }
    }

    public function test_detect_iserv_root_is_false_for_a_regular_nextcloud_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $fake = new fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Unterricht');
        webdav_instance::set_transport($fake);

        try {
            $this->assertFalse(webdav_instance::detect_iserv_root($instanceid));
        } finally {
            webdav_instance::set_transport(null);
        }
    }
}
