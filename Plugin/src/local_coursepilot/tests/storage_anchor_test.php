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

use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Location resolution preserves external boundaries and the local workbench.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(storage_anchor::class)]
final class storage_anchor_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Legacy Moodle paths cannot silently address an external context area.
     */
    public function test_external_context_rejects_a_moodle_directory(): void {
        $this->select_external('context_area');
        try {
            context_files::resolve_directory('notes');
            $this->fail('An external context must require its storage port.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pointerexternalnotsupported', $e->errorcode);
        }
    }

    /**
     * External material storage leaves uploads at the configured Moodle workbench.
     */
    public function test_external_material_storage_keeps_the_workbench_at_its_configured_root(): void {
        set_config('materialroot', 'school-workbench', 'local_coursepilot');
        $this->select_external('material_store');

        $this->assertSame('/school-workbench/uploads/', material_files::resolve_directory('uploads'));
        $this->assertSame('/school-workbench/', storage_anchor::effective_location(material_files::workbench_area())->path);
    }

    /**
     * Relative external coordinates stay inside the selected folder.
     */
    public function test_external_paths_preserve_the_selected_folder_and_normalise_subpaths(): void {
        $location = pointer_location::external(1, 'Teaching/Context', $this->fixture_fingerprint());
        $area = context_files::area();

        $this->assertSame(
            'Teaching/Context/notes/plan.md',
            storage_anchor::external_relative_path($area, $location, 'notes\\plan.md')
        );
        $this->assertSame('Teaching/Context', storage_anchor::external_relative_path($area, $location, ''));
        $root = pointer_location::external(1, '', $this->fixture_fingerprint());
        $this->assertSame('notes/plan.md', storage_anchor::external_relative_path($area, $root, 'notes/plan.md'));
    }

    /**
     * A manually corrupted pointer never falls back to the configured root.
     */
    public function test_unreadable_pointer_refuses_default_location(): void {
        private_files_storage_port::write_content(storage_anchor::anchor_root(), storage_anchor::POINTER_FILENAME, '{');
        try {
            storage_anchor::effective_location(context_files::area());
            $this->fail('An unreadable pointer must not choose the default location.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pointerunreadable', $e->errorcode);
        }
    }

    /**
     * A write path must name a file rather than the area itself.
     */
    public function test_empty_write_path_is_refused(): void {
        try {
            context_files::resolve_writable_file('');
            $this->fail('An empty write path must not address the area itself.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidcontextpath', $e->errorcode);
        }
    }

    /**
     * Selects an owned external repository through real pointer persistence.
     *
     * @param string $target Selected area.
     */
    private function select_external(string $target): void {
        global $USER;
        $this->grant_webdav_capability($USER);
        $instanceid = $this->create_webdav_instance($USER);
        $locations = [
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];
        $locations[$target] = [
            'location' => 'external',
            'instanceid' => $instanceid,
            'path' => 'Teaching',
            'fingerprint' => $this->fixture_fingerprint(),
        ];
        storage_anchor::write_pointer_document($locations);
    }
}
