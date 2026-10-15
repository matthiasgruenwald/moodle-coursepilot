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

/**
 * Storage contract tests for the private files adapter.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot;

use local_coursepilot\tests\storage_port_contract_test;
use local_coursepilot\webdav\webdav_setup_steps;

// phpcs:disable moodle.PHPUnit.TestCaseNames.Missing -- The test case is inherited from storage_port_contract_test.
/**
 * Storage contract against the first adapter (Issue #536, Spec 0021):
 * private_files_storage_port runs the shared storage_port_contract_test.
 *
 * The test area has an independent root and .md naming rule, borrowing
 * context-area error keys rather than adding test-only language strings.
 * It has no pointer field, so it cannot enter legacy pointer resolution.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(private_files_storage_port::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\storage_anchor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(material_area::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(material_files::class)]
#[\PHPUnit\Framework\Attributes\Group('storage_port')]
final class private_files_storage_port_test extends storage_port_contract_test {
    /**
     * Appending charges the added bytes and leaves existing content intact on refusal.
     */
    public function test_append_enforces_quota_before_changing_the_file(): void {
        $port = $this->port();
        $area = $this->area();
        $first = $port->write($area, 'plan.md', 'First');
        set_config('userquota', file_get_user_used_space() + 3);
        try {
            $port->append($area, 'plan.md', ' more', $first['checksum']);
            $this->fail('Appending beyond the available quota must be refused.');
        } catch (\moodle_exception $error) {
            $this->assertSame('contextquotaexceeded', $error->errorcode);
        }
        $this->assertSame('First', $port->read($area, 'plan.md')['content']);
        $this->assertSame([
            'path' => 'plan.md', 'created' => false, 'size' => 8, 'checksum' => sha1('First123'),
        ], $port->append($area, 'plan.md', '123', $first['checksum']));
        $this->assertSame('First123', $port->read($area, 'plan.md')['content']);
        $this->assertSame(0, private_files_storage_port::remaining_quota());
    }

    /**
     * A valid long filename remains writable when replacing its contents.
     */
    public function test_overwrite_preserves_a_long_valid_filename(): void {
        $filename = str_repeat('a', 223) . '.md';
        $this->port()->write($this->area(), $filename, 'Original');
        $this->port()->write($this->area(), $filename, 'Updated');
        $this->assertSame('Updated', $this->port()->read($this->area(), $filename)['content']);
        $this->assertSame([$filename], array_column($this->port()->list($this->area(), ''), 'name'));
    }

    /**
     * Missing configuration disables quota, matching Moodle's unlimited state.
     */
    public function test_missing_quota_configuration_means_unlimited_space(): void {
        global $CFG;
        unset($CFG->userquota);
        $this->assertNull(private_files_storage_port::remaining_quota());
    }

    /**
     * Lowering the quota below existing storage reports zero remaining bytes.
     */
    public function test_overdrawn_quota_has_no_negative_remaining_space(): void {
        $this->port()->write($this->area(), 'plan.md', 'Already stored');
        set_config('userquota', 1);
        $this->assertSame(0, private_files_storage_port::remaining_quota());
    }

    /**
     * Replacement cannot collide with another valid teacher filename.
     */
    public function test_overwrite_preserves_another_filename_with_the_temporary_prefix(): void {
        $other = private_files_storage_port::TEMP_PREFIX . '-plan.md';
        $this->port()->write($this->area(), 'plan.md', 'Original');
        $this->port()->write($this->area(), $other, 'Other file');
        $this->port()->write($this->area(), 'plan.md', 'Updated');
        $this->assertSame('Updated', $this->port()->read($this->area(), 'plan.md')['content']);
        $this->assertSame('Other file', $this->port()->read($this->area(), $other)['content']);
    }

    /**
     * Replacement charges only growth and reports the actual previous size.
     */
    public function test_material_replacement_uses_only_the_additional_quota(): void {
        $first = material_area::write('plan.txt', str_repeat('A', 60));
        $this->assertSame(0, $first['oldsize']);
        set_config('userquota', 100);
        $result = material_area::write('plan.txt', str_repeat('B', 80));
        $this->assertSame(60, $result['oldsize']);
        $this->assertSame(80, $result['size']);
        $this->assertNull($result['warning']);
        $this->assertSame(str_repeat('B', 80), material_area::read_for_location('workbench', 'plan.txt')['content']);
        $this->assertSame(20, private_files_storage_port::remaining_quota());
    }

    /**
     * Native clients can append and delete through the public file primitives.
     */
    public function test_public_append_and_delete_preserve_the_persisted_result(): void {
        $directory = '/storageport-contract-test/';
        $this->assertSame(5, private_files_storage_port::append_content($directory, 'plan.md', 'First'));
        $this->assertSame(12, private_files_storage_port::append_content($directory, 'plan.md', ' second'));
        $this->assertSame('First second', $this->port()->read($this->area(), 'plan.md')['content']);
        $this->assertTrue(private_files_storage_port::delete_file($directory, 'plan.md'));
        $this->assertNull($this->port()->read($this->area(), 'plan.md'));
        $this->assertFalse(private_files_storage_port::delete_file($directory, 'plan.md'));
    }

    /**
     * Recursive cleanup sees all files and their directories, never directory placeholders.
     */
    public function test_recursive_listing_keeps_every_file_and_its_metadata(): void {
        $directory = '/storageport-contract-test/';
        $this->port()->write($this->area(), 'first.md', 'First');
        $this->port()->write($this->area(), 'nested/second.md', 'Second');
        $entries = private_files_storage_port::list_entries_recursive($directory);
        $this->assertCount(2, $entries);
        $byname = array_column($entries, null, 'name');
        foreach (['first.md' => [$directory, 'First'], 'second.md' => [$directory . 'nested/', 'Second']] as $name => $data) {
            $file = get_file_storage()->get_file(storage_anchor::own_context()->id, 'user', 'private', 0, $data[0], $name);
            $this->assertSame([
                'directory' => $data[0], 'name' => $name, 'size' => strlen($data[1]),
                'contenthash' => sha1($data[1]), 'timecreated' => (int) $file->get_timecreated(),
            ], $byname[$name]);
        }
        private_files_storage_port::write_content($directory, storage_anchor::POINTER_FILENAME, '{}');
        private_files_storage_port::write_content($directory, storage_anchor::PENDING_FILENAME, '{}');
        $flat = private_files_storage_port::list_entries($directory);
        $this->assertCount(2, $flat);
        $byname = array_column($flat, null, 'name');
        $this->assertSame([
            'name' => 'nested', 'type' => 'folder', 'size' => 0, 'mimetype' => '', 'contenthash' => '', 'timemodified' => 0,
        ], $byname['nested']);
        $file = get_file_storage()->get_file(storage_anchor::own_context()->id, 'user', 'private', 0, $directory, 'first.md');
        $this->assertSame([
            'name' => 'first.md', 'type' => 'file', 'size' => 5, 'mimetype' => $file->get_mimetype(),
            'contenthash' => sha1('First'), 'timemodified' => (int) $file->get_timemodified(),
        ], $byname['first.md']);
    }

    /**
     * A refused write reports accurate rounded space figures and leaves no file.
     *
     * @param int $remaining
     * @param int $needed
     * @param string $leftmb
     * @param string $neededmb
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quota_message_boundaries')]
    public function test_quota_failure_keeps_accurate_space_figures(
        int $remaining,
        int $needed,
        string $leftmb,
        string $neededmb
    ): void {
        set_config('userquota', $remaining + file_get_user_used_space());
        try {
            $this->port()->write($this->area(), 'oversized.md', str_repeat('x', $needed));
            $this->fail('An over-quota write must not succeed.');
        } catch (\moodle_exception $error) {
            $this->assertSame('contextquotaexceeded', $error->errorcode);
            $this->assertSame(
                'Not written: your file quota does not have enough room — ' . $neededmb . ' MB needed, '
                    . $leftmb . ' MB left. See the location page (' . webdav_setup_steps::LOCATION_SELECTION_PAGE . ').',
                $error->getMessage()
            );
        }
        $this->assertNull($this->port()->read($this->area(), 'oversized.md'));
    }

    /**
     * Byte counts at rounding boundaries distinguish both figures and the binary MB unit.
     *
     * @return array<string, array{int, int, string, string}>
     */
    public static function quota_message_boundaries(): array {
        return [
            'quarter MB left' => [262144, 471859, '0.3', '0.4'],
            'three-quarter MB needed' => [471859, 786432, '0.4', '0.8'],
        ];
    }

    /**
     * Shared file writes retain metadata and stay isolated to the current person.
     */
    public function test_file_primitives_replace_content_and_isolate_users(): void {
        $directory = '/storageport-contract-test/';
        private_files_storage_port::write_content($directory, 'plan.md', '# First', ['source' => 'original']);
        private_files_storage_port::write_content($directory, 'plan.md', '# Updated', ['source' => 'retained']);

        $this->assertSame('# Updated', $this->port()->read($this->area(), 'plan.md')['content']);
        $this->assertSame('plan.md', private_files_storage_port::list_entries_recursive($directory)[0]['name']);
        $file = get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            'user',
            'private',
            0,
            $directory,
            'plan.md'
        );
        $this->assertSame('retained', $file->get_source());
        $this->assertSame([
            'content' => '# Updated', 'checksum' => sha1('# Updated'), 'size' => 9,
            'mimetype' => (string) $file->get_mimetype(), 'timemodified' => (int) $file->get_timemodified(),
        ], $this->port()->read($this->area(), 'plan.md'));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull($this->port()->read($this->area(), 'plan.md'));
        $this->assertSame([], private_files_storage_port::list_entries_recursive($directory));
    }

    /**
     * Conditional creation and replacement retain image provenance through the port.
     */
    public function test_conditional_writes_retain_source_metadata(): void {
        $port = $this->port();
        $area = $this->area();
        $source = serialize((object) ['original' => 'store:page.png']);
        $first = $port->write($area, 'plan.md', '# First', storage_port::MISSING_CHECKSUM, $source);
        $port->write($area, 'plan.md', '# Updated', $first['checksum'], $source);
        $file = get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            'user',
            'private',
            0,
            '/storageport-contract-test/',
            'plan.md'
        );
        $this->assertSame($source, $file->get_source());
        $this->assertSame('# Updated', $port->read($area, 'plan.md')['content']);
    }

    /**
     * Provides port.
     *
     * @return storage_port
     */
    protected function port(): storage_port {
        return new private_files_storage_port();
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
}
