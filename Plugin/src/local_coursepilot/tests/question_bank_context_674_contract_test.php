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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Question bank resolver: the course is validated first and the bank context becomes the page context (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(question_bank_context::class)]
final class question_bank_context_674_contract_test extends \advanced_testcase {
    use caught_throwable;

    public function test_the_bank_context_becomes_the_page_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = external\ensure_question_bank::execute($course->id, 'Bank');

        [, $context] = question_bank_context::resolve((int) $course->id, (int) $bank['questionbankid']);

        $this->assertSame((int) $bank['contextid'], (int) $context->id);
        $this->assertSame((int) $context->id, (int) $PAGE->context->id);
    }

    public function test_a_user_outside_the_course_is_refused_by_the_course_check_before_the_bank_lookup(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $system = \context_system::instance();
        assign_capability('local/coursepilot:use', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);
        $this->setUser($user);

        $e = $this->caught(fn() => question_bank_context::resolve((int) $course->id, 987654));

        $this->assertInstanceOf(\require_login_exception::class, $e);
    }
}
