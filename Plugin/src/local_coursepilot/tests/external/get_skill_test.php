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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Deliver a skill-corpus entry (Spec 0020 §4, #450): remote-access
 * authorization suffices without course binding (#630).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_skill::class)]
final class get_skill_test extends \advanced_testcase {

    /**
     * Return content, referenced parts and corpus version.
     */
    public function test_returns_content_referenced_parts_and_corpus_stand(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $result = get_skill::execute('coursepilot');
        $result = external_api::clean_returnvalue(get_skill::execute_returns(), $result);

        $this->assertStringContainsString('coursepilot-core', $result['content']);
        $this->assertContains('coursepilot-core', $result['referenced_parts']);
        $this->assertNotSame('', $result['corpus_version']);
    }

    /**
     * Unknown names produce a message listing valid names rather than
     * an empty result.
     */
    public function test_unknown_name_names_valid_names(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        try {
            get_skill::execute('gibtsnicht');
            $this->fail('moodle_exception erwartet.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('coursepilot-core', $e->getMessage());
        }
    }

    /**
     * Reject names containing path components by matching the directory
     * listing rather than filtering characters.
     *
     * @param string $name
     */
    #[DataProvider('path_like_name_provider')]
    public function test_path_like_name_is_rejected(string $name): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_remote_access($user);
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        get_skill::execute($name);
    }

    /**
     * @return array<string, string[]>
     */
    public static function path_like_name_provider(): array {
        return [
            'dot-dot-slash' => ['../../../etc/passwd'],
            'leading-slash' => ['/etc/passwd'],
            'backslash' => ['..\\..\\coursepilot-core'],
            'encoded' => ['%2e%2e%2fcoursepilot-core'],
            'suffixed-real-name' => ['coursepilot-core/../../../etc/passwd'],
        ];
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
        get_skill::execute('coursepilot');
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
