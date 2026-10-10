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

/**
 * Failure handling of the Moodle branch of {@see context_area} (issue
 * #540, ADR 0023 "at both locations"): a failure while persisting itself -
 * not path/extension/quota validation, not a conflict - records a
 * pending write before the error is returned.
 *
 * A real failure of the Moodle file storage (database/file system) cannot
 * be provoked safely in a test environment - unlike for the external
 * location, there is no injectable fake transport for it. This test
 * therefore uses reflection to access the private static core method
 * ({@see context_area::persist_moodle_write()}/{@see context_area::persist_moodle_append()})
 * directly and passes it a test double of the {@see storage_port} contract
 * (the type the method accepts anyway, not the concrete
 * {@see private_files_storage_port}) - the same contract that
 * {@see private_files_storage_port_test} also checks against the real adapter.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(context_area::class)]
final class context_area_pending_test extends \advanced_testcase {
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
            $this->invoke_persist_write($port, 'plan.md', '# Plan', pending_write_translation::OP_CREATE, 7);
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
            $this->invoke_persist_append($port, 'journal.md', 'line', pending_write_translation::OP_APPEND, 0);
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
            $this->invoke_persist_write($port, 'plan.md', '# Plan', pending_write_translation::OP_CREATE, 0);
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
            $this->invoke_persist_write($port, 'plan.md', '# Plan', pending_write_translation::OP_OVERWRITE, 0);
            $this->fail('The conflict should have been rejected.');
        } catch (storage_conflict_exception $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], pending_write_notice::list_grouped());
    }

    public function test_append_conflict_from_the_port_is_not_recorded_as_an_ausstand(): void {
        $port = $this->failing_port(new storage_conflict_exception('journal.md'));
        try {
            $this->invoke_persist_append($port, 'journal.md', 'line', pending_write_translation::OP_APPEND, 0);
            $this->fail('A checksum conflict must remain a conflict.');
        } catch (storage_conflict_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }
        $this->assertSame([], pending_write_notice::list_grouped());
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
             * @return ?array
             */
            public function read(storage_area $area, string $path): ?array {
                return null;
            }

            /**
             * Lists the context area pending test.
             *
             * @param storage_area $area The area.
             * @param string $path The path.
             * @return array
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
             * @return array
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
             * @return array
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

    /**
     * Provides invoke persist write.
     *
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation
     * @param int $courseid
     * @return array
     */
    private function invoke_persist_write(storage_port $port, string $path, string $content, string $operation, int $courseid): array {
        $method = new \ReflectionMethod(context_area::class, 'persist_moodle_write');
        $method->setAccessible(true);
        return $method->invoke(null, $port, $path, $content, $operation, $courseid);
    }

    /**
     * Provides invoke persist append.
     *
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation
     * @param int $courseid
     * @return array
     */
    private function invoke_persist_append(storage_port $port, string $path, string $content, string $operation, int $courseid): array {
        $method = new \ReflectionMethod(context_area::class, 'persist_moodle_append');
        $method->setAccessible(true);
        return $method->invoke(null, $port, $path, $content, $operation, $courseid);
    }
}
