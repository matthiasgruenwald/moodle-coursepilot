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

namespace local_coursepilot;

use local_coursepilot\tests\storage_port_contract_test;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

/**
 * Der Ablage-Vertrag gegen den zweiten Adapter (Issue #537, Spec 0021):
 * {@see webdav_storage_port} tritt hier gegen dieselbe, unveraenderte
 * {@see storage_port_contract_test} an wie schon
 * {@see private_files_storage_port_test} (Issue #536) - der Beweis, dass
 * beide Orte hinter dem einen Vertrag dasselbe beobachtbare Verhalten
 * zeigen.
 *
 * Benutzt {@see webdav_instance_fixture} fuer eine echte, dem angemeldeten
 * Testnutzer gehoerende WebDAV-Nutzerinstanz - {@see webdav_storage_port}
 * ruft dafuer bei jeder Operation {@see webdav_instance::resolve_owned()}
 * auf, die Instanzeigentum genau dort prueft (ADR 0021), nicht hier im Test.
 * Der Transport ist der injizierte In-Memory-Fake ({@see fake_webdav_transport}),
 * wie in jedem anderen WebDAV-Test.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(webdav_storage_port::class)]
final class webdav_storage_port_test extends storage_port_contract_test {

    use webdav_instance_fixture;

    /** @var int|null Instanz der Standard-Testnutzerin, siehe setUp(). */
    private ?int $instanceid = null;

    protected function setUp(): void {
        parent::setUp();

        global $USER;
        $this->grant_webdav_capability($USER);
        // webdav_path leer: der Testadapter legt seinen eigenen Basisordner
        // ("storageport-webdav-contract-test") per MKCOL an - dessen Elternordner
        // muss dafuer bereits existieren. Ein nicht-leerer webdav_path waere die
        // Instanzwurzel selbst, die im echten Betrieb schon auf dem Server liegt,
        // im Fake-Speicher aber nur die Wurzel "" vorab existiert.
        $this->instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
        webdav_instance::set_transport(new fake_webdav_transport());
    }

    protected function tearDown(): void {
        webdav_instance::set_transport(null);
        parent::tearDown();
    }

    protected function port(): storage_port {
        return new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test');
    }

    protected function area(): storage_area {
        return new storage_area(
            rootsetting: 'storageportcontracttestroot',
            defaultroot: 'storageport-contract-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_.-]+\.md$/', $filename)) {
                    throw new \moodle_exception('contextfilenotmarkdown', 'local_coursepilot', '', $filename);
                }
            },
        );
    }
}
