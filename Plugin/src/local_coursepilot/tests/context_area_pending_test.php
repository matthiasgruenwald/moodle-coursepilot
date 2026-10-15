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
#[\PHPUnit\Framework\Attributes\CoversClass(storage_write::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(material_area::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(context_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\material_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\storage_area::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\storage_anchor::class)]
final class context_area_pending_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Invalid context paths remain caller errors even when the selected adapter is unavailable.
     *
     * @param bool $append
     */
    #[DataProvider('context_write_operations')]
    public function test_context_path_is_checked_before_the_storage_preread(bool $append): void {
        $port = $this->failing_port(new \RuntimeException('Unavailable remote storage'), failread: true);
        $location = pointer_location::moodle('/context/');
        try {
            if ($append) {
                context_area::append_to($port, $location, '../plan.md', 'Content');
            } else {
                context_area::write_to($port, $location, '../plan.md', 'Content');
            }
            $this->fail('Invalid paths must not reach the unavailable adapter.');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidcontextpath', $error->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Both operations validate the same context path before reading storage.
     *
     * @return array<string, array{bool}>
     */
    public static function context_write_operations(): array {
        return ['replace' => [false], 'append' => [true]];
    }

    /**
     * An omitted course identifier records a course-neutral retry for either context operation.
     *
     * @param bool $append
     */
    #[DataProvider('context_write_operations')]
    public function test_failed_context_write_defaults_to_no_course(bool $append): void {
        $port = $this->failing_port(new \RuntimeException('Unavailable remote storage'));
        $location = pointer_location::moodle('/context/');
        try {
            if ($append) {
                context_area::append_to($port, $location, 'plan.md', 'Content');
            } else {
                context_area::write_to($port, $location, 'plan.md', 'Content');
            }
            $this->fail('Unavailable storage must reject the write.');
        } catch (\moodle_exception $error) {
            $this->assertSame('pendingwritefailed', $error->errorcode);
        }
        $notes = pending_write_notice::list_grouped();
        $this->assertCount(1, $notes);
        $entry = $notes[0]['entries'][0];
        $this->assertSame(0, $entry['course_id']);
        $this->assertSame($append ? 'append' : 'create', $entry['operation']);
    }

    /**
     * A conflict detected by an external adapter during preflight remains a merge request, not an outage.
     */
    public function test_preread_conflict_creates_no_pending_outage_note(): void {
        $failure = new storage_failure(\local_coursepilot\webdav\webdav_error::CONFLICT, new \RuntimeException('Changed target'));
        $port = $this->failing_port($failure, failread: true);
        $location = pointer_location::external(0, 'context', ['server' => '', 'basepath' => '', 'account' => '']);
        try {
            context_area::write_to($port, $location, 'plan.md', 'Content');
            $this->fail('A changed target must reject the write.');
        } catch (\moodle_exception $error) {
            $this->assertSame('contextfileexternalconflict', $error->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Adapter diagnostics reach the private access event while the teacher receives only the recovery response.
     */
    public function test_preread_outage_retains_the_private_adapter_diagnostic(): void {
        set_config('loglevel', access_log::LEVEL_ALL, 'local_coursepilot');
        $sink = $this->redirectEvents();
        $failure = new storage_failure(
            \local_coursepilot\webdav\webdav_error::UNREACHABLE,
            new \RuntimeException('Remote connection lost')
        );
        $port = $this->failing_port($failure, failread: true);
        $location = pointer_location::external(0, 'context', [
            'server' => 'https://storage.example', 'basepath' => '', 'account' => '',
        ]);
        try {
            context_area::write_to($port, $location, 'plan.md', 'Content', courseid: 42);
            $this->fail('An unavailable adapter must reject the write.');
        } catch (\moodle_exception $error) {
            $this->assertSame('pendingwritefailed', $error->errorcode);
            $this->assertStringNotContainsString('Remote connection lost', $error->getMessage());
        }
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($event) => $event instanceof \local_coursepilot\event\tool_access_failed
        ));
        $this->assertCount(1, $events);
        $this->assertSame('WebDAV unreachable: Remote connection lost', $events[0]->other['reason']);
        $notes = pending_write_notice::list_grouped();
        $this->assertCount(1, $notes);
        $this->assertSame('unreachable', $notes[0]['entries'][0]['error_class']);
        $this->assertSame(42, $notes[0]['entries'][0]['course_id']);
        $this->assertSame('unknown', $notes[0]['entries'][0]['operation']);
    }

    /**
     * A missing external root retains the intended retry operation during preflight.
     *
     * @param bool $append
     */
    #[DataProvider('context_write_operations')]
    public function test_preread_location_failure_keeps_the_retry_operation(bool $append): void {
        $failure = new \moodle_exception('contextrootmissing', 'local_coursepilot', '', 'location');
        $port = $this->failing_port($failure, failread: true);
        $location = pointer_location::external(0, 'context', [
            'server' => 'https://storage.example', 'basepath' => '', 'account' => '',
        ]);
        try {
            if ($append) {
                context_area::append_to($port, $location, 'plan.md', 'Content');
            } else {
                material_area::write_to($port, $location, 'sheet.txt', 'Content');
            }
            $this->fail('A missing storage root must reject the write.');
        } catch (\moodle_exception $error) {
            $this->assertSame('pendingwritefailed', $error->errorcode);
        }
        $notes = pending_write_notice::list_grouped();
        $this->assertCount(1, $notes);
        $this->assertSame($append ? 'append' : 'create', $notes[0]['entries'][0]['operation']);
        $this->assertSame('contextrootmissing', $notes[0]['entries'][0]['error_class']);
    }

    /**
     * A path rejected during persistence remains a caller error without a retry note.
     */
    public function test_adapter_path_error_creates_no_pending_outage_note(): void {
        $failure = new \moodle_exception('invalidcontextpath', 'local_coursepilot', '', 'plan.md');
        $port = $this->failing_port($failure);
        try {
            context_area::write_to($port, pointer_location::moodle('/context/'), 'plan.md', 'Content');
            $this->fail('A rejected adapter path must not be written.');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidcontextpath', $error->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Invalid material names are refused before an unavailable adapter can hide the input error.
     */
    public function test_material_name_is_checked_before_the_storage_preread(): void {
        $port = $this->failing_port(new \RuntimeException('Unavailable remote storage'), failread: true);
        try {
            material_area::write_to($port, pointer_location::moodle('/material/'), 'blocked.php', 'Content');
            $this->fail('Disallowed material names must be refused.');
        } catch (\moodle_exception $error) {
            $this->assertSame('materialfiledisallowedtype', $error->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Material access is checked before a preread can create an outage note for an unauthorized user.
     */
    public function test_material_write_requires_permission_before_the_storage_preread(): void {
        global $CFG;
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            storage_anchor::own_context()->id,
            true
        );
        $port = $this->failing_port(new \RuntimeException('Unavailable remote storage'), failread: true);
        try {
            material_area::write_to($port, pointer_location::moodle('/material/'), 'sheet.txt', 'Content');
            $this->fail('An unauthorized user must not reach storage.');
        } catch (\required_capability_exception $error) {
            $this->assertSame(get_capability_string('moodle/user:manageownfiles'), $error->a);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
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
     * Material outages retain the same pending-write outcome as context outages.
     */
    public function test_material_write_records_pending_note_when_storage_fails(): void {
        $port = $this->failing_port(new \RuntimeException('Disk unavailable'));
        try {
            material_area::write_to($port, pointer_location::moodle('/material/'), 'sheet.txt', 'Lesson');
            $this->fail('Unavailable storage must reject the write.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $notes = pending_write_notice::list_grouped();
        $this->assertCount(1, $notes);
        $this->assertSame('sheet.txt', $notes[0]['path']);
        $this->assertSame(pending_write_translation::OP_CREATE, $notes[0]['entries'][0]['operation']);
    }

    /**
     * Failed preflight reads never attempt a material write and record the unknown operation.
     */
    public function test_material_preread_outage_records_pending_note(): void {
        $port = $this->failing_port(new \RuntimeException('Read unavailable'), failread: true);
        try {
            material_area::write_to($port, pointer_location::moodle('/material/'), 'sheet.txt', 'Lesson');
            $this->fail('A failed preflight must reject writing.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $notes = pending_write_notice::list_grouped();
        $this->assertCount(1, $notes);
        $this->assertSame(pending_write_translation::OP_UNKNOWN, $notes[0]['entries'][0]['operation']);
        $this->assertSame(0, $notes[0]['entries'][0]['course_id']);
    }

    /**
     * Intervening creation and replacement preserve the newer material content.
     *
     * @param bool $exists Whether the preflight observes an existing target.
     */
    #[DataProvider('target_states')]
    public function test_material_preflight_rejects_intervening_changes(bool $exists): void {
        $location = pointer_location::moodle('/material/');
        $inner = new private_files_storage_port($location);
        $area = material_files::workbench_area();
        if ($exists) {
            $inner->write($area, 'sheet.txt', 'Before');
        }
        $port = new \local_coursepilot\tests\interleaved_storage_port(
            $inner,
            static function () use ($inner, $area): void {
                $inner->write($area, 'sheet.txt', 'Teacher edit');
            }
        );
        try {
            material_area::write_to($port, $location, 'sheet.txt', 'AI replacement');
            $this->fail('A changed target must reject replacement.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialfilechanged', $e->errorcode);
        }
        $this->assertSame('Teacher edit', $inner->read($area, 'sheet.txt')['content']);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Supplies missing and existing preflight states.
     *
     * @return array<string, array{bool}>
     */
    public static function target_states(): array {
        return ['missing' => [false], 'existing' => [true]];
    }

    /**
     * Generated XML retains the export's permissive path contract and can be replaced.
     */
    public function test_xml_export_write_preserves_permissive_paths(): void {
        $first = material_area::write_export('My questions/Überblick.xml', '<quiz/>');
        $second = material_area::write_export('My questions/Überblick.xml', '<quiz>Updated</quiz>');
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame('My questions/Überblick.xml', $second['path']);
        $this->assertSame('<quiz>Updated</quiz>', material_area::read('My questions/Überblick.xml')['content']);
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

        $this->assertSame(['path' => 'plan.md', 'created' => true, 'size' => 6, 'oldsize' => 0], $written);
        $this->assertFalse($appended['created']);
        $this->assertSame("# Plan\nNext step", $port->read(context_files::area(), 'plan.md')['content']);
        $replaced = context_area::write_to($port, $location, 'plan.md', '# Replaced');
        $this->assertSame(['path' => 'plan.md', 'created' => false, 'size' => 10, 'oldsize' => 16], $replaced);
        $this->assertSame('# Replaced', $port->read(context_files::area(), 'plan.md')['content']);
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
     * @param \Throwable $failure Thrown at the unmanaged boundary.
     * @param bool $failread Fail during preflight instead of persistence.
     * @return storage_port
     */
    private function failing_port(\Throwable $failure, bool $failread = false): storage_port {
        return new class ($failure, $failread) implements storage_port {
            /**
             * Creates the context area pending test.
             *
             * @param \Throwable $failure The failure.
             * @param bool $failread Fail during preflight.
             */
            public function __construct(
                /** @var \Throwable The failure. */
                private readonly \Throwable $failure,
                /** @var bool Fail the preflight read instead of persistence. */
                private readonly bool $failread,
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
                if ($this->failread) {
                    throw $this->failure;
                }
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
             * @param string $source Serialised provenance.
             */
            public function write(
                storage_area $area,
                string $path,
                string $content,
                ?string $expectedchecksum = null,
                string $source = ''
            ): array {
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
