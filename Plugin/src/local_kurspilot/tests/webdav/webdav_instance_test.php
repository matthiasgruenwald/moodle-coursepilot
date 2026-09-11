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

namespace local_kurspilot\webdav;

use local_kurspilot\pointer_location;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Die Auflösungsprüfungen einer WebDAV-Nutzerinstanz, ohne Netz (Issue #490,
 * Spec #486 §2): Existenz, Instanzeigentum (inkl. "Login as"),
 * WebDAV-Freischaltung, https+Basic, Prüfmerkmal. Jeder Verstoß ein
 * benannter Fehler.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(webdav_instance::class)]
final class webdav_instance_test extends \advanced_testcase {
    use webdav_instance_fixture;

    private function location(int $instanceid, ?array $fingerprint = null): pointer_location {
        return pointer_location::extern($instanceid, 'Kurspilot-Kontext', $fingerprint ?? $this->fixture_fingerprint());
    }

    public function test_resolves_valid_instance_to_credentials_and_base_url(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);

        $resolved = webdav_instance::resolve($this->location($instanceid));

        $this->assertSame(
            'https://cloud.example.test/Kurspilot/Kurspilot-Kontext',
            $resolved->file_url('Kurspilot-Kontext')
        );
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
}
