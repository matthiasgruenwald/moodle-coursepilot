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

    /** @var fake_webdav_transport Der fuer einen Vertragstest gemeinsam genutzte Transport. */
    private fake_webdav_transport $transport;

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
        $this->transport = new fake_webdav_transport();
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    protected function port(): storage_port {
        return new webdav_storage_port(
            $this->instanceid,
            'storageport-webdav-contract-test',
            $this->transport
        );
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

    /**
     * Ein Ausfall am Speicher (507, Issue #540 ADR 0023 "an beiden Orten")
     * vermerkt einen Ausstand, bevor der Fehler zurueckgeht - nie roh
     * durchgereicht, dasselbe Verhalten wie bisher nur der externe Zweig
     * ueber {@see pointer_writer} kannte.
     */
    public function test_write_records_ausstand_when_the_underlying_put_fails(): void {
        $onlyputfails = new class(new fake_webdav_transport()) implements \local_coursepilot\webdav\webdav_transport {
            public function __construct(private readonly fake_webdav_transport $inner) {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    return new \local_coursepilot\webdav\webdav_response(507, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        try {
            (new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $onlyputfails))
                ->write($this->area(), 'plan.md', '# Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
            $this->assertStringContainsString('plan.md', $e->getMessage());
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['pfad']);
        $this->assertSame('anlegen', $ausstaende[0]['eintraege'][0]['vorgang']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['eintraege'][0]['fehlerklasse']
        );
    }

    /**
     * Anhaengen scheitert am Speicher (Read-modify-write) - derselbe
     * Ausstand-Schutz wie beim Schreiben.
     */
    public function test_append_records_ausstand_when_the_underlying_put_fails(): void {
        $onlyputfails = new class(new fake_webdav_transport()) implements \local_coursepilot\webdav\webdav_transport {
            public function __construct(private readonly fake_webdav_transport $inner) {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    return new \local_coursepilot\webdav\webdav_response(507, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        try {
            (new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $onlyputfails))
                ->append($this->area(), 'journal.md', 'erste Zeile');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('anhängen', $ausstaende[0]['eintraege'][0]['vorgang']);
    }

    /**
     * Eine bereits als {@see storage_conflict_exception} unterwegs stehende
     * Absage (Pruefwert-Konflikt) zaehlt weiterhin nicht als Ausstand (Issue
     * #540 Abnahmekriterium 2, ADR 0023 Punkt 2) - der Inhalt bleibt im
     * Gespraech, kein Ausstand-Eintrag entsteht.
     */
    public function test_write_with_stale_checksum_does_not_record_an_ausstand(): void {
        $port = $this->port();
        $area = $this->area();
        $written = $port->write($area, 'plan.md', 'erster Inhalt');
        $port->write($area, 'plan.md', 'inzwischen geaendert');

        try {
            $port->write($area, 'plan.md', 'wuerde ueberschreiben', $written['checksum']);
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (storage_conflict_exception $e) {
            // Erwartet.
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Eine geloeschte WebDAV-Instanz (ADR 0023: "eine geloeschte Instanz")
     * legt ebenfalls einen Ausstand an - dieser Adapter hat keinen Pointer,
     * der einen Ort-Ausfall sonst schon vorher abfangen wuerde.
     */
    public function test_write_records_ausstand_when_the_instance_is_deleted(): void {
        global $DB;
        $DB->delete_records('repository_instances', ['id' => $this->instanceid]);

        try {
            $this->port()->write($this->area(), 'plan.md', '# Plan');
            $this->fail('Geloeschte Instanz haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('webdavinstancemissing', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
    }
}
