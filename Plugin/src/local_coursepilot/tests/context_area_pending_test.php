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

use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Context writes through the public storage-port seam. Adapter doubles fail at
 * the unmanaged storage boundary; Moodle's pending-note storage runs for real.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(context_area::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(pointer_writer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(private_files_storage_port::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(webdav_storage_port::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(storage_failure::class)]
final class context_area_pending_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * A failure while persisting (simulated here: an arbitrary adapter
     * error) records a pending write before the error is returned -
     * never passed through raw (issue #540 acceptance criteria 1+5).
     */
    public function test_write_records_ausstand_when_the_port_fails_to_persist(): void {
        $port = $this->failing_port(new \RuntimeException('Disk full (simulated)'));

        try {
            context_area::write_to($port, pointer_location::moodle('/context/'), 'plan.md', '# Plan', courseid: 7);
            $this->fail('The failure should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
            $this->assertStringContainsString('plan.md', $e->getMessage());
        }

        $ausstaende = pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['path']);
        $entry = $ausstaende[0]['entries'][0];
        $this->assertSame(pending_write_translation::OP_CREATE, $entry['operation']);
        $this->assertSame(7, $entry['course_id']);
    }

    /**
     * The same failure handling applies when appending.
     */
    public function test_append_records_ausstand_when_the_port_fails_to_persist(): void {
        $port = $this->failing_port(new \RuntimeException('Disk full (simulated)'));

        try {
            context_area::append_to($port, pointer_location::moodle('/context/'), 'journal.md', 'line', courseid: 0);
            $this->fail('The failure should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = pending_write_notice::list_grouped();
        $this->assertSame(pending_write_translation::OP_APPEND, $ausstaende[0]['entries'][0]['operation']);
    }

    /**
     * The area's quota check remains a caller error, not a pending
     * write (issue #540 acceptance criterion 2, ADR 0023 point 2) - even if
     * it only takes effect during persisting itself.
     */
    public function test_write_quota_exceeded_from_the_port_is_not_recorded_as_an_ausstand(): void {
        $port = $this->failing_port(new \moodle_exception('contextquotaexceeded', 'local_coursepilot', '', (object) [
            'available' => 0,
            'page' => '',
        ]));

        try {
            context_area::write_to($port, pointer_location::moodle('/context/'), 'plan.md', '# Plan', courseid: 0);
            $this->fail('The quota check should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextquotaexceeded', $e->errorcode);
        }

        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * A checksum conflict ({@see storage_conflict_exception}) still does
     * not count as a pending write (issue #540 acceptance criterion 2).
     */
    public function test_write_conflict_from_the_port_is_not_recorded_as_an_ausstand(): void {
        $port = $this->failing_port(new storage_conflict_exception('plan.md'));

        try {
            context_area::write_to($port, pointer_location::moodle('/context/'), 'plan.md', '# Plan', courseid: 0);
            $this->fail('The conflict should have been rejected.');
        } catch (storage_conflict_exception $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], pending_write_notice::list_grouped());
    }

    public function test_append_conflict_from_the_port_is_not_recorded_as_an_ausstand(): void {
        $port = $this->failing_port(new storage_conflict_exception('journal.md'));
        try {
            context_area::append_to($port, pointer_location::moodle('/context/'), 'journal.md', 'line', courseid: 0);
            $this->fail('A checksum conflict must remain a conflict.');
        } catch (storage_conflict_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * External errors outside the adapter outage contract retain their original code.
     */
    public function test_external_non_outage_error_keeps_original_code_without_pending_note(): void {
        $port = $this->failing_port(new \moodle_exception('webdavfingerprintchanged', 'local_coursepilot'));
        $location = pointer_location::external(0, 'context', ['server' => '', 'basepath' => '', 'account' => '']);
        try {
            context_area::write_to($port, $location, 'plan.md', '# Plan');
            $this->fail('The changed connection must reject writing.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavfingerprintchanged', $e->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Both production adapters keep context writes and appends behind the same seam.
     *
     * @param bool $external Use the WebDAV port instead of Private Files.
     */
    #[DataProvider('locations')]
    public function test_both_ports_write_and_append_context_content(bool $external): void {
        global $USER;
        $location = pointer_location::moodle('/context/');
        $port = new private_files_storage_port($location);
        if ($external) {
            $this->grant_webdav_capability($USER);
            $instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
            $location = pointer_location::external($instanceid, 'context', webdav_instance::fingerprint_of($instanceid));
            $fake = new fake_webdav_transport();
            $fake->seed_folder('/context');
            $port = new webdav_storage_port($instanceid, 'context', $fake, $location);
        }
        $written = context_area::write_to($port, $location, 'plan.md', '# Plan');
        $appended = context_area::append_to($port, $location, 'plan.md', "\nNext step");

        $this->assertTrue($written['created']);
        $this->assertFalse($appended['created']);
        $this->assertSame("# Plan\nNext step", $port->read(context_files::area(), 'plan.md')['content']);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Supplies the two supported storage locations.
     *
     * @return array<string, array{bool}>
     */
    public static function locations(): array {
        return ['private files' => [false], 'webdav' => [true]];
    }

    /**
     * Provides failing port.
     *
     * @param \Throwable $failure Thrown by write()/append() of the test double.
     * @return storage_port
     */
    private function failing_port(\Throwable $failure): storage_port {
        return new class ($failure) implements storage_port {
            /**
             * Creates the context area pending test.
             *
             * @param \Throwable $failure The failure.
             */
            public function __construct(
                /** @var \Throwable The failure. */
                private readonly \Throwable $failure,
            ) {
            }

            /**
             * Reads the context area pending test.
             *
             * @param storage_area $area The area.
             * @param string $path The path.
             * @return array{content: string, checksum: string, size: int, mimetype: string, timemodified: int}|null
             */
            public function read(storage_area $area, string $path): ?array {
                return null;
            }

            /**
             * Lists the context area pending test.
             *
             * @param storage_area $area The area.
             * @param string $path The path.
             * @return array<int,array{name:string,type:string,size:int,mimetype:string,checksum:string,timemodified:int}>
             */
            public function list(storage_area $area, string $path): array {
                return [];
            }

            /**
             * Writes the context area pending test.
             *
             * @param storage_area $area The area.
             * @param string $path The path.
             * @param string $content The content.
             * @param ?string $expectedchecksum The expectedchecksum.
             * @return array{path: string, created: bool, size: int, checksum: string}
             */
            public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
                throw $this->failure;
            }

            /**
             * Appends the context area pending test.
             *
             * @param storage_area $area The area.
             * @param string $path The path.
             * @param string $content The content.
             * @param ?string $expectedchecksum The expectedchecksum.
             * @return array{path: string, created: bool, size: int, checksum: string}
             */
            public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
                throw $this->failure;
            }

            /**
             * Deletes the context area pending test.
             *
             * @param storage_area $area The area.
             * @param string $path The path.
             * @return bool
             */
            public function delete(storage_area $area, string $path): bool {
                return false;
            }
        };
    }
}
