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
#[\PHPUnit\Framework\Attributes\CoversClass(storage_failure::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\storage_anchor::class)]
#[\PHPUnit\Framework\Attributes\Group('storage_port')]
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

    /**
     * Location selection creates every missing level and preserves existing files.
     */
    public function test_create_location_directory_preserves_existing_content(): void {
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $this->transport);
        webdav_storage_port::create_location_directory($this->instanceid, ['Teaching', 'Context']);
        $port = new webdav_storage_port($this->instanceid, 'Teaching/Context', $this->transport);
        $port->write($this->area(), 'plan.md', '# Kept');

        webdav_storage_port::create_location_directory($this->instanceid, ['Teaching', 'Context']);

        $this->assertSame('# Kept', $port->read($this->area(), 'plan.md')['content']);
        $this->assertSame('plan.md', $port->list($this->area(), '')[0]['name']);
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
     * The adapter reports an outage; recording pending writes belongs to its caller.
     */
    public function test_write_reports_storage_failure_without_recording_a_pending_note(): void {
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
             * @param string[] $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null
            ): \local_coursepilot\webdav\webdav_response {
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
        } catch (storage_failure $e) {
            $this->assertSame('storage_full', $e->errorclass);
        }

        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Appending reports the same neutral outage as writing.
     */
    public function test_append_reports_storage_failure_without_recording_a_pending_note(): void {
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
             * @param string[] $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null
            ): \local_coursepilot\webdav\webdav_response {
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
        } catch (storage_failure $e) {
            $this->assertSame('storage_full', $e->errorclass);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
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
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * A deleted instance is an outage; its caller owns pending-write recording.
     */
    public function test_write_reports_unavailable_storage_when_the_instance_is_deleted(): void {
        global $DB;
        $DB->delete_records('repository_instances', ['id' => $this->instanceid]);

        try {
            $this->port()->write($this->area(), 'plan.md', '# Plan');
            $this->fail('Deleted instance should have been rejected.');
        } catch (storage_failure $e) {
            $this->assertSame('webdavinstancemissing', $e->errorclass);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * A write that loses against a concurrent creation is a conflict, not a storage outage.
     */
    public function test_create_losing_against_a_concurrent_creation_raises_conflict(): void {
        $stale = new \local_coursepilot\tests\webdav\stale_read_transport(
            $this->transport,
            '/storageport-webdav-contract-test/plan.md',
            $this->transport
        );
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $stale);

        $this->expectException(storage_conflict_exception::class);
        $port->write($this->area(), 'plan.md', 'meine Fassung');
    }

    /**
     * An overwrite based on a state that changed in the meantime is a conflict and keeps the other change.
     */
    public function test_overwrite_losing_against_a_concurrent_edit_raises_conflict(): void {
        $this->port()->write($this->area(), 'plan.md', 'erste Fassung');
        $stale = new \local_coursepilot\tests\webdav\stale_read_transport(
            $this->transport,
            '/storageport-webdav-contract-test/plan.md',
            $this->transport
        );
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $stale);

        try {
            $port->write($this->area(), 'plan.md', 'meine Fassung');
            $this->fail('A concurrent edit must be reported as conflict.');
        } catch (storage_conflict_exception $e) {
            $this->assertSame('handaenderung', $this->port()->read($this->area(), 'plan.md')['content']);
        }
    }

    /**
     * Returns the MOVE requests the fake saw.
     *
     * @return array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    private function moves(): array {
        return array_values(array_filter($this->transport->requests(), static fn (array $r): bool => $r['method'] === 'MOVE'));
    }

    /**
     * A strong ETag makes the server enforce the source version; the target is never overwritten.
     */
    public function test_move_sends_if_match_and_overwrite_false_for_a_strong_etag(): void {
        $port = $this->port();
        $written = $port->write($this->area(), 'alt.md', 'Inhalt');

        $result = $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);

        $this->assertTrue($result['server_enforced']);
        $this->assertCount(1, $this->moves());
        $headers = $this->moves()[0]['headers'];
        $this->assertSame('F', $headers['Overwrite']);
        $this->assertSame($this->transport->etag_of('/storageport-webdav-contract-test/archive/alt.md'), $headers['If-Match']);
    }

    /**
     * Without a strong ETag the bytes are compared right before the MOVE, which carries no If-Match (ADR 0035).
     */
    public function test_move_without_a_strong_etag_compares_bytes_and_reports_not_enforced(): void {
        $this->transport->without_etags();
        $port = $this->port();
        $written = $port->write($this->area(), 'alt.md', 'Inhalt');

        $result = $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);

        $this->assertFalse($result['server_enforced']);
        $this->assertSame('F', $this->moves()[0]['headers']['Overwrite']);
        $this->assertArrayNotHasKey('If-Match', $this->moves()[0]['headers']);
        $this->assertSame('Inhalt', $port->read($this->area(), 'archive/alt.md')['content']);
    }

    /**
     * A weak ETag is no version for If-Match (ADR 0035): same path as without an ETag.
     */
    public function test_move_with_a_weak_etag_sends_no_if_match(): void {
        $this->transport->with_weak_etags();
        $port = $this->port();
        $written = $port->write($this->area(), 'alt.md', 'Inhalt');

        $result = $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);

        $this->assertFalse($result['server_enforced']);
        $this->assertArrayNotHasKey('If-Match', $this->moves()[0]['headers']);
    }

    /**
     * Without a strong ETag a change after the first read is caught by the byte comparison; no MOVE is sent.
     */
    public function test_move_without_a_strong_etag_detects_a_change_before_the_move(): void {
        $this->transport->without_etags();
        $written = $this->port()->write($this->area(), 'alt.md', 'erste Fassung');
        $transport = new \local_coursepilot\tests\webdav\before_request_transport(
            $this->transport,
            'GET',
            fn () => $this->transport->seed_file('/storageport-webdav-contract-test/alt.md', 'Handänderung')
        );
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $transport);

        try {
            $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);
            $this->fail('The changed source must not be moved.');
        } catch (storage_conflict_exception $e) {
            $this->assertSame([], $this->moves());
            $this->assertSame('Handänderung', $this->port()->read($this->area(), 'alt.md')['content']);
        }
    }

    /**
     * With a strong ETag the server refuses a MOVE of a source changed after the PROPFIND (If-Match, 412).
     */
    public function test_move_with_a_strong_etag_is_refused_by_the_server_after_a_concurrent_change(): void {
        $written = $this->port()->write($this->area(), 'alt.md', 'erste Fassung');
        $transport = new \local_coursepilot\tests\webdav\before_request_transport(
            $this->transport,
            'MOVE',
            fn () => $this->transport->seed_file('/storageport-webdav-contract-test/alt.md', 'Handänderung')
        );
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $transport);

        try {
            $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);
            $this->fail('The server must refuse the changed source.');
        } catch (storage_conflict_exception $e) {
            $this->assertCount(1, $this->moves());
            $this->assertSame('Handänderung', $this->port()->read($this->area(), 'alt.md')['content']);
            $this->assertNull($this->port()->read($this->area(), 'archive/alt.md'));
        }
    }

    /**
     * A target that appears between the preflight and the MOVE survives: Overwrite F makes the server refuse (412).
     */
    public function test_move_onto_a_target_created_meanwhile_keeps_both_files(): void {
        $written = $this->port()->write($this->area(), 'alt.md', 'Quelle');
        $transport = new \local_coursepilot\tests\webdav\before_request_transport(
            $this->transport,
            'MOVE',
            fn () => $this->transport->seed_file('/storageport-webdav-contract-test/archive/alt.md', 'Ziel')
        );
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $transport);

        $this->expectException(storage_conflict_exception::class);
        try {
            $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);
        } finally {
            $this->assertSame('Quelle', $this->port()->read($this->area(), 'alt.md')['content']);
            $this->assertSame('Ziel', $this->port()->read($this->area(), 'archive/alt.md')['content']);
        }
    }

    /**
     * A server that does not answer is an outage, not a conflict.
     */
    public function test_move_reports_an_outage_as_storage_failure(): void {
        $written = $this->port()->write($this->area(), 'alt.md', 'Inhalt');
        $transport = new \local_coursepilot\tests\webdav\before_request_transport(
            $this->transport,
            'MOVE',
            fn () => $this->transport->deny_auth(401)
        );
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $transport);

        $this->expectException(storage_failure::class);
        $port->move($this->area(), 'alt.md', 'archive/alt.md', $written['checksum']);
    }

    /**
     * Reading a folder path yields no file, without fetching the collection.
     */
    public function test_read_of_a_folder_is_null(): void {
        $port = $this->port();
        $port->write($this->area(), 'ordner/plan.md', 'Inhalt');

        $this->assertNull($port->read($this->area(), 'ordner'));
    }

    /**
     * WebDAV has no place for image provenance; asking for it is a programming error.
     */
    public function test_write_with_provenance_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        $this->port()->write($this->area(), 'plan.md', 'Inhalt', null, '{"origin":"x"}');
    }

    /**
     * Creating no folder levels sends no request at all.
     */
    public function test_create_location_directory_without_segments_sends_nothing(): void {
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $this->transport);

        webdav_storage_port::create_location_directory($this->instanceid, []);

        $this->assertSame([], $this->transport->requests());
    }

    /**
     * A multistatus without a usable property set counts as a missing file; the write then creates it.
     */
    public function test_write_creates_the_file_when_the_server_reports_no_properties(): void {
        $inner = $this->transport;
        $emptyproperties = new class ($inner) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the double.
             *
             * @param fake_webdav_transport $inner Real storage fake.
             */
            public function __construct(
                /** @var fake_webdav_transport Real storage fake. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Answers the first file lookup with an empty multistatus.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param string[] $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null
            ): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PROPFIND' && str_ends_with($url, '/plan.md')) {
                    return new \local_coursepilot\webdav\webdav_response(
                        207,
                        ['content-type' => 'application/xml'],
                        '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>'
                    );
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        $port = new webdav_storage_port($this->instanceid, 'storageport-webdav-contract-test', $emptyproperties);

        $result = $port->write($this->area(), 'plan.md', 'Inhalt');

        $this->assertTrue($result['created']);
        $this->assertSame('Inhalt', $this->port()->read($this->area(), 'plan.md')['content']);
    }

    /**
     * A selected folder whose root check is refused (not "missing") stays a named storage failure.
     */
    public function test_write_below_a_selected_folder_reports_a_refused_root_check(): void {
        $this->transport->seed_folder('/Kontext');
        $this->transport->fail_once('/Kontext', 401);
        $location = pointer_location::external($this->instanceid, 'Kontext', webdav_instance::fingerprint_of($this->instanceid));
        $port = new webdav_storage_port($this->instanceid, 'Kontext', $this->transport, $location);

        try {
            $port->write($this->area(), 'plan.md', 'Inhalt');
            $this->fail('A refused root check must not create the file.');
        } catch (storage_failure $e) {
            $this->assertSame(webdav\webdav_error::AUTH_REJECTED, $e->errorclass);
        }
    }
}
