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

namespace local_coursepilot\external;

use core_external\external_api;
use local_coursepilot\pending_write_notice;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Skill catalog (Spec 0020 §4, #450): no course binding; remote-access
 * authorization suffices (#630).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(list_skills::class)]
final class list_skills_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Return name, trigger, kind and size without content. No existing course
     * or teacher enrollment is needed.
     */
    public function test_lists_catalog_without_course_binding(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertNotEmpty($result['skills']);
        $names = array_column($result['skills'], 'name');
        $this->assertContains('coursepilot', $names);
        $this->assertContains('coursepilot-core', $names);

        foreach ($result['skills'] as $skill) {
            $this->assertArrayNotHasKey('content', $skill);
            $this->assertContains($skill['kind'], ['adapter', 'reference']);
            $this->assertGreaterThan(0, $skill['length']);
        }
    }

    /**
     * Reject users without remote-access authorization, including enrolled
     * teachers (#630).
     */
    public function test_without_remote_access_is_rejected(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $generator->create_course()->id, 'editingteacher');
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('remoteaccessnotgranted', 'local_coursepilot'));
        list_skills::execute();
    }

    /**
     * Return an empty array rather than null when no pending entries exist
     * (#492, ADR 0023 §4).
     */
    public function test_ausstaende_field_is_empty_by_default(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['pending_entries']);
    }

    /**
     * Group pending entries by target file, oldest first (#492, ADR 0023 §4).
     * The handshake performs no network requests through fake WebDAV.
     */
    public function test_ausstaende_bundled_by_path_oldest_first_without_network_access(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        try {
            $aelter = pending_write_notice::record('plan.md', 'create', 'storage_full', 0);
            $neuer = pending_write_notice::record('plan.md', 'overwrite', 'unreachable', 0);
            pending_write_notice::record('journal.md', 'append', 'auth_rejected', 0);

            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills must not reach the external storage.');
            $this->assertCount(2, $result['pending_entries']);
            $this->assertSame('plan.md', $result['pending_entries'][0]['path']);
            $this->assertSame([$aelter, $neuer], array_column($result['pending_entries'][0]['entries'], 'identifier'));
            $this->assertSame('journal.md', $result['pending_entries'][1]['path']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Without WebDAV authorization, omit the location-selection notice and
     * return an empty notices array (#494).
     */
    public function test_hinweise_field_is_empty_without_webdav_freischaltung(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['notices']);
    }

    /**
     * With authorization but no context pointer, include the pending
     * location-selection fact and link without network requests (#494).
     */
    public function test_hinweise_field_names_open_location_selection_when_enabled_and_no_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);

        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        try {
            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills must not reach the external storage.');
            $this->assertCount(1, $result['notices']);
            $this->assertStringContainsString('/local/coursepilot/location_selection.php', $result['notices'][0]['link']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * An existing context pointer removes the pending-location fact even
     * while authorization remains enabled.
     */
    public function test_hinweise_field_is_empty_once_a_pointer_exists(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        storage_anchor::write_pointer_document([
            'context_area' => 'mein-kontext',
            'materialordner' => 'mein-material',
        ]);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['notices']);
    }

    /**
     * Without pending old content, omit its notice.
     */
    public function test_hinweise_field_has_no_previouslocation_hint_by_default(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertSame([], $result['notices']);
    }

    /**
     * Pending old content appears as a notice without counts
     * (#498, Spec #486 §9/§10).
     */
    public function test_hinweise_field_names_open_previouslocation_without_counting(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);
        $this->write_pointer_with_previous_location($user);

        $result = list_skills::execute();
        $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

        $this->assertCount(1, $result['notices']);
        $this->assertSame(
            get_string(
                'listskillspreviouslocationhint',
                'local_coursepilot',
                \local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE
            ),
            $result['notices'][0]['text']
        );
        // Old-content notices contain no count (#498). Check the German pack
        // for real umlauts and absence of digits.
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');
        $this->assertDoesNotMatchRegularExpression('/\d/', $string['listskillspreviouslocationhint']);
    }

    /**
     * Invalid context-pointer JSON must not break the handshake. Return
     * the skill catalog plus a named notice linking to location selection,
     * without network requests (#519, Spec #486 §10).
     */
    public function test_broken_pointer_still_returns_skills_with_named_hint_and_no_network(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], 'kein json');

        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        try {
            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills must not reach the external storage.');
            $this->assertNotEmpty($result['skills']);
            $this->assertCount(1, $result['notices']);
            $this->assertSame(
                get_string(
                    'listskillspointerbrokenhint',
                    'local_coursepilot',
                    \local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE
                ),
                $result['notices'][0]['text']
            );
            $this->assertStringContainsString('/local/coursepilot/location_selection.php', $result['notices'][0]['link']);
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Valid JSON missing required pointer fields produces the same notice
     * as an unreadable pointer, without network requests (#519, Spec #486 §10).
     */
    public function test_incomplete_pointer_still_returns_skills_with_named_hint_and_no_network(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);
        $this->enable_webdav_repository_type();
        $this->grant_webdav_capability($user);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], json_encode(['irgendwas' => 'ohne die Pflichtfelder']));

        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        try {
            $result = list_skills::execute();
            $result = external_api::clean_returnvalue(list_skills::execute_returns(), $result);

            $this->assertSame([], $fake->requests(), 'coursepilot_list_skills must not reach the external storage.');
            $this->assertNotEmpty($result['skills']);
            $this->assertCount(1, $result['notices']);
            $this->assertSame(
                get_string(
                    'listskillspointerbrokenhint',
                    'local_coursepilot',
                    \local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE
                ),
                $result['notices'][0]['text']
            );
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Grants remote access the way a school does after #579: a selected
     * system cohort, and the teacher role only inside a course - no
     * system-level role (Issue #630).
     *
     * @param \stdClass $user
     */
    private function grant_remote_access(\stdClass $user): void {
        $generator = $this->getDataGenerator();
        $cohort = $generator->create_cohort(['contextid' => \context_system::instance()->id]);
        cohort_add_member($cohort->id, $user->id);
        set_config('remoteaccesscohorts', (string) $cohort->id, 'local_coursepilot');
        $generator->enrol_user($user->id, $generator->create_course()->id, 'editingteacher');
    }
}
