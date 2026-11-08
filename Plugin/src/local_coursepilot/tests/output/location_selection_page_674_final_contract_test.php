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


namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Access guard, form reading and success message of the location selection controller (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection_page::class)]
final class location_selection_page_674_final_contract_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    /**
     * Signs in the admin with an owned instance and a fake transport holding the base folder.
     *
     * @return array{0: int, 1: \local_coursepilot\tests\webdav\fake_webdav_transport}
     */
    private function prepare(): array {
        global $USER;
        $this->setAdminUser();
        $instanceid = $this->create_webdav_instance($USER);
        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake->seed_folder('/' . $this->fixturebasispfad);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        return [$instanceid, $fake];
    }

    /**
     * Runs a finish request with the given form fields and returns the page data result.
     *
     * @param mixed[] $fields Form fields without the session key.
     * @return mixed[]|null The finish result.
     */
    private function finish(array $fields): ?array {
        $original = $_POST;
        $_POST = $fields + ['sesskey' => sesskey()];
        try {
            return location_selection_page::response(false, true)->data['finishresult'];
        } finally {
            $_POST = $original;
        }
    }

    /**
     * Teachers without the remote access grant cannot open the page.
     */
    public function test_response_requires_the_remote_access_grant(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('remoteaccessnotgranted', 'local_coursepilot'));
        location_selection_page::response(false, false);
    }

    /**
     * A submitted finish action without session key is rejected.
     */
    public function test_finish_requires_the_session_key(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $original = $_POST;
        $_POST = [];
        try {
            $this->expectException(\moodle_exception::class);
            $this->expectExceptionMessage('sesskey');
            location_selection_page::response(false, true);
        } finally {
            $_POST = $original;
        }
    }

    /**
     * The success message names every changed target by its translated tab label.
     */
    public function test_success_message_lists_both_changed_targets_by_label(): void {
        $this->resetAfterTest();
        [$instanceid] = $this->prepare();

        $result = $this->finish([
            'context_area_type' => 'external', 'context_area_instanceid' => $instanceid, 'context_area_path' => 'Lessons',
            'material_store_type' => 'external', 'material_store_instanceid' => $instanceid, 'material_store_path' => 'Stock',
        ]);

        $this->assertSame('alert-success', $result['class']);
        $expected = get_string('locationselectionfinishsuccess', 'local_coursepilot', 'Context area, Material stock');
        $this->assertSame($expected, $result['text']);
        $raw = \local_coursepilot\storage_anchor::read_raw_pointer();
        $this->assertSame('Lessons', $raw['context_area']['path']);
        $this->assertSame('Stock', $raw['material_store']['path']);
    }

    /**
     * Without an instance id field an external selection is invalid.
     */
    public function test_external_selection_without_instance_id_is_invalid(): void {
        $this->resetAfterTest();
        $this->prepare();

        $result = $this->finish(['context_area_type' => 'external', 'context_area_path' => 'Lessons']);

        $this->assertSame('alert-danger', $result['class']);
        $this->assertSame(get_string('locationselectionselectioninvalid', 'local_coursepilot'), $result['text']);
    }

    /**
     * The handover confirmation is read from its own field and defaults to not confirmed.
     */
    public function test_populated_folder_needs_the_confirmed_field(): void {
        $this->resetAfterTest();
        [$instanceid, $fake] = $this->prepare();
        $fake->seed_folder('/' . $this->fixturebasispfad . '/Voll');
        $fake->seed_file('/' . $this->fixturebasispfad . '/Voll/notiz.md', 'x');
        $fields = ['context_area_type' => 'external', 'context_area_instanceid' => $instanceid, 'context_area_path' => 'Voll'];

        $result = $this->finish($fields);
        $this->assertSame('alert-danger', $result['class']);
        $this->assertSame(get_string('locationselectionfolderconfirmrequired', 'local_coursepilot'), $result['text']);

        foreach (['material_store_confirmed', 'confirmed', 'context_area'] as $wrongfield) {
            $result = $this->finish($fields + [$wrongfield => 1]);
            $this->assertSame('alert-danger', $result['class'], $wrongfield);
        }

        $result = $this->finish($fields + ['context_area_confirmed' => 1]);
        $this->assertSame('alert-success', $result['class']);
        $this->assertSame('Voll', \local_coursepilot\storage_anchor::read_raw_pointer()['context_area']['path']);
    }
}
