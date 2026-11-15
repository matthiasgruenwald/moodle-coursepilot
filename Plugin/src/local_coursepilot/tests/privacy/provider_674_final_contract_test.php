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

namespace local_coursepilot\privacy;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Context-type branching of the privacy provider's context deletion (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_674_final_contract_test extends \core_privacy\tests\provider_testcase {
    /**
     * Module-context deletion leaves context marks untouched, even when a mark's userid equals the module id.
     */
    public function test_module_context_deletion_keeps_context_marks(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $markid = $this->add_mark((int) $cm->id, 'module-mark.md');

        provider::delete_data_for_all_users_in_context(\context_module::instance($cm->id));

        $this->assertTrue($DB->record_exists('local_coursepilot_context_mark', ['id' => $markid]));
    }

    /**
     * User-context deletion removes exactly the context marks of that user.
     */
    public function test_user_context_deletion_removes_only_that_users_marks(): void {
        global $DB;
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $ownmarkid = $this->add_mark((int) $user->id, 'own-mark.md');
        $othermarkid = $this->add_mark((int) $other->id, 'other-mark.md');

        provider::delete_data_for_all_users_in_context(\context_user::instance($user->id));

        $this->assertFalse($DB->record_exists('local_coursepilot_context_mark', ['id' => $ownmarkid]));
        $this->assertTrue($DB->record_exists('local_coursepilot_context_mark', ['id' => $othermarkid]));
    }

    /**
     * Inserts a context mark for the given user id and returns its id.
     *
     * @param int $userid
     * @param string $path
     * @return int
     */
    private function add_mark(int $userid, string $path): int {
        global $DB;
        return (int) $DB->insert_record('local_coursepilot_context_mark', (object) [
            'userid' => $userid, 'path' => $path, 'pathhash' => sha1($path),
            'filesize' => 10, 'timemodified' => 100, 'etag' => null, 'ismarked' => 1,
        ]);
    }
}
