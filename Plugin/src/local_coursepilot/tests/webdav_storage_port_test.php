<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

use local_coursepilot\tests\storage_port_contract_test;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

/**
 * The second storage adapter (#537, Spec 0021) runs the same unchanged
 * {@see storage_port_contract_test} as {@see private_files_storage_port_test}
 * (#536), proving equivalent observable behavior behind one contract.
 *
 * {@see webdav_instance_fixture} creates a real WebDAV user instance owned
 * by the test user. Every {@see webdav_storage_port} operation checks
 * ownership through {@see webdav_instance::resolve_owned()} (ADR 0021).
 * Transport uses the injected {@see fake_webdav_transport}, as elsewhere.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(webdav_storage_port::class)]
final class webdav_storage_port_test extends storage_port_contract_test {
    use webdav_instance_fixture;

    /** @var int|null Default test user’s instance; see setUp(). */
    private ?int $instanceid = null;

    /** @var fake_webdav_transport Transport shared by a contract test. */
    private fake_webdav_transport $transport;

    protected function setUp(): void {
        parent::setUp();

        global $USER;
        $this->grant_webdav_capability($USER);
        // Empty webdav_path: the adapter creates storageport-webdav-contract-test
        // through MKCOL and needs its parent to exist. A nonempty webdav_path
        // would be an existing server root; the fake initially has only root "".
        $this->instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
        $this->transport = new fake_webdav_transport();
    }

    protected function tearDown(): void {
        parent::tearDown();
    }

    /**
     * Provides port.
     *
     * @return storage_port
     */
    protected function port(): storage_port {
        return new webdav_storage_port(
            $this->instanceid,
            'storageport-webdav-contract-test',
            $this->transport
        );
    }

    /**
     * Provides area.
     *
     * @return storage_area
     */
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
     * Provides applies user quota.
     *
     * @return bool
     */
    protected function applies_user_quota(): bool {
        return false;
    }

    /**
     * Storage outages (507, #540, ADR 0023) record a pending entry before
     * returning a translated error, matching pointer_writer’s external path.
     */
    public function test_write_records_ausstand_when_the_underlying_put_fails(): void {
        $onlyputfails = new class (new fake_webdav_transport()) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the webdav storage port test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
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
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
            $this->assertStringContainsString('plan.md', $e->getMessage());
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['path']);
        $this->assertSame('create', $ausstaende[0]['entries'][0]['operation']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['entries'][0]['error_class']
        );
    }

    /**
     * Failed read-modify-write append has the same pending-entry protection
     * as writing.
     */
    public function test_append_records_ausstand_when_the_underlying_put_fails(): void {
        $onlyputfails = new class (new fake_webdav_transport()) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the webdav storage port test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
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
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('append', $ausstaende[0]['entries'][0]['operation']);
    }

    /**
     * An existing {@see storage_conflict_exception} remains a conflict,
     * not an outage (#540, criterion 2, ADR 0023 §2). Content stays in the
     * conversation without a pending entry.
     */
    public function test_write_with_stale_checksum_does_not_record_an_ausstand(): void {
        $port = $this->port();
        $area = $this->area();
        $written = $port->write($area, 'plan.md', 'erster Inhalt');
        $port->write($area, 'plan.md', 'inzwischen geaendert');

        try {
            $port->write($area, 'plan.md', 'would overwrite', $written['checksum']);
            $this->fail('Conflict should have been rejected.');
        } catch (storage_conflict_exception $e) {
            // Erwartet.
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Deleted WebDAV instances also create pending entries (ADR 0023).
     * This adapter has no pointer to catch a location failure earlier.
     */
    public function test_write_records_ausstand_when_the_instance_is_deleted(): void {
        global $DB;
        $DB->delete_records('repository_instances', ['id' => $this->instanceid]);

        try {
            $this->port()->write($this->area(), 'plan.md', '# Plan');
            $this->fail('Deleted instance should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('webdavinstancemissing', $ausstaende[0]['entries'][0]['error_class']);
    }
}
