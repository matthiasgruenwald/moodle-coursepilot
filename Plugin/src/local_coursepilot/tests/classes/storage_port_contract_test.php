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

namespace local_coursepilot\tests;

use local_coursepilot\storage_area;
use local_coursepilot\storage_conflict_exception;
use local_coursepilot\storage_port;

/**
 * Shared storage contract suite (Issue #536, Spec 0021 Testing Decisions)
 * for all adapters, independent of location. Place the abstract fixture in
 * tests/classes under local_coursepilot\tests for Moodle autoloading.
 * PHPUnit runs concrete subclasses, which provide only an adapter and
 * test area, e.g. private_files_storage_port_test and the WebDAV adapter.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
abstract class storage_port_contract_test extends \advanced_testcase {

    /**
     * Adapter under test.
     *
     * @return storage_port
     */
    abstract protected function port(): storage_port;

    /**
     * Invented test area expressed as storage_area values (ADR 0020),
     * keeping the suite away from real context and material areas.
     *
     * @return storage_area
     */
    abstract protected function area(): storage_area;

    /** WebDAV is outside Moodle's user quota. */
    protected function applies_user_quota(): bool {
        return true;
    }

    /**
     * Create an authenticated user with write permission for all tests;
     * every adapter needs its own user context.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    public function test_read_returns_null_for_missing_file(): void {
        $this->assertNull($this->port()->read($this->area(), 'fehlt.md'));
    }

    public function test_write_creates_new_file_and_returns_it_readable(): void {
        $result = $this->port()->write($this->area(), 'plan.md', 'erster Inhalt');

        $this->assertTrue($result['created']);
        $this->assertSame('plan.md', $result['path']);
        $this->assertSame(strlen('erster Inhalt'), $result['size']);
        $this->assertNotSame('', $result['checksum']);

        $read = $this->port()->read($this->area(), 'plan.md');
        $this->assertSame('erster Inhalt', $read['content']);
        $this->assertSame($result['checksum'], $read['checksum']);
    }

    public function test_write_overwrites_without_a_checksum(): void {
        $port = $this->port();
        $area = $this->area();

        $port->write($area, 'plan.md', 'erster Inhalt');
        $result = $port->write($area, 'plan.md', 'zweiter Inhalt');

        $this->assertFalse($result['created']);
        $this->assertSame('zweiter Inhalt', $port->read($area, 'plan.md')['content']);
    }

    public function test_write_with_matching_checksum_succeeds(): void {
        $port = $this->port();
        $area = $this->area();

        $written = $port->write($area, 'plan.md', 'erster Inhalt');
        $result = $port->write($area, 'plan.md', 'zweiter Inhalt', $written['checksum']);

        $this->assertSame('zweiter Inhalt', $port->read($area, 'plan.md')['content']);
        $this->assertNotSame($written['checksum'], $result['checksum']);
    }

    public function test_write_with_stale_checksum_raises_conflict(): void {
        $port = $this->port();
        $area = $this->area();

        $written = $port->write($area, 'plan.md', 'erster Inhalt');
        $port->write($area, 'plan.md', 'inzwischen geaendert');

        $this->expectException(storage_conflict_exception::class);
        $port->write($area, 'plan.md', 'wuerde ueberschreiben', $written['checksum']);
    }

    public function test_write_with_a_checksum_against_a_missing_file_raises_conflict(): void {
        $this->expectException(storage_conflict_exception::class);
        $this->port()->write($this->area(), 'fehlt.md', 'inhalt', 'irgendein-pruefwert');
    }

    public function test_write_rejects_traversal_segments(): void {
        $area = $this->area();
        try {
            $this->port()->write($area, '../../../etc/plan.md', 'inhalt');
            $this->fail('A path containing a .. segment must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame($area->invalidpathkey, $e->errorcode);
        }
    }

    public function test_write_applies_the_areas_own_writable_name_rule(): void {
        $area = $this->area();
        try {
            $this->port()->write($area, 'unerlaubt.exe', 'inhalt');
            $this->fail('The area naming rule must reject this extension.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('unerlaubt.exe', $e->getMessage());
        }
    }

    public function test_write_rejects_when_quota_is_exceeded(): void {
        if (!$this->applies_user_quota()) {
            $this->markTestSkipped('This location is outside the Moodle user quota.');
        }
        global $CFG;

        $area = $this->area();
        $CFG->userquota = 10;

        try {
            $this->port()->write($area, 'zu-gross.md', str_repeat('x', 1000));
            $this->fail('A write exceeding quota must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame($area->quotaerrorkey, $e->errorcode);
        }
    }

    public function test_append_creates_a_file_when_it_is_missing(): void {
        $result = $this->port()->append($this->area(), 'journal.md', 'erste Zeile');

        $this->assertTrue($result['created']);
        $this->assertSame('erste Zeile', $this->port()->read($this->area(), 'journal.md')['content']);
    }

    public function test_append_adds_to_existing_content(): void {
        $port = $this->port();
        $area = $this->area();

        $port->write($area, 'journal.md', 'erste Zeile');
        $result = $port->append($area, 'journal.md', '/zweite Zeile');

        $this->assertFalse($result['created']);
        $this->assertSame('erste Zeile/zweite Zeile', $port->read($area, 'journal.md')['content']);
        $this->assertSame(strlen('erste Zeile/zweite Zeile'), $result['size']);
    }

    public function test_list_reflects_written_files_with_the_same_field_set(): void {
        $port = $this->port();
        $area = $this->area();

        $port->write($area, 'a.md', 'a');
        $port->write($area, 'b.md', 'bb');

        $entries = $port->list($area, '');
        $names = array_column($entries, 'name');
        sort($names);
        $this->assertSame(['a.md', 'b.md'], $names);

        foreach ($entries as $entry) {
            $this->assertArrayHasKey('name', $entry);
            $this->assertArrayHasKey('type', $entry);
            $this->assertArrayHasKey('size', $entry);
            $this->assertArrayHasKey('mimetype', $entry);
            $this->assertArrayHasKey('checksum', $entry);
            $this->assertArrayHasKey('timemodified', $entry);
        }
    }

    /**
     * Location neutrality (Issue #560): reading the same Markdown through
     * any adapter returns the same mimetype value, beyond field-set parity.
     * Moodle's extension table has no .md entry; content sniffing avoids
     * location-specific text/plain versus document/unknown results.
     *
     * Check read(), not list(): Private Files listing uses stored mimetypes
     * without extra reads, while WebDAV sniffing would require a GET per
     * unknown extension, violating its zero-GET listing contract
     * (webdav_client::parse_multistatus()). WebDAV lists document/unknown.
     */
    public function test_mimetype_is_location_independent_for_the_same_content_when_read(): void {
        $port = $this->port();
        $area = $this->area();

        $port->write($area, 'notiz.md', "# Titel\n\nText");

        $this->assertSame('text/plain', $port->read($area, 'notiz.md')['mimetype']);
    }

    public function test_delete_removes_an_existing_file(): void {
        $port = $this->port();
        $area = $this->area();

        $port->write($area, 'weg.md', 'inhalt');
        $this->assertTrue($port->delete($area, 'weg.md'));
        $this->assertNull($port->read($area, 'weg.md'));
    }

    public function test_delete_reports_false_when_nothing_existed(): void {
        $this->assertFalse($this->port()->delete($this->area(), 'nieexistiert.md'));
    }
}
