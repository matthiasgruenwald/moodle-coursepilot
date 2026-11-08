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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup of a default export that fails between instance creation and linking (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(export_default_activity::class)]
final class export_default_activity_674_final_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * When native creation fails after the instance exists but before the cm row links to it, the instance is
     * handed to the cleanup: it completes the link, and the incomplete removal is reported explicitly.
     */
    public function test_a_failure_before_the_link_hands_the_instance_to_the_cleanup(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $trigger = 'cp674e' . bin2hex(random_bytes(8));
        $flag = '@' . $trigger;
        // The first link of an instance to a course module of this course fails; later updates pass.
        $ddl->query("CREATE TRIGGER $trigger BEFORE UPDATE ON " . $DB->get_prefix() . "course_modules
            FOR EACH ROW BEGIN
                IF OLD.course = $course->id AND OLD.instance = 0 AND NEW.instance <> 0 AND $flag IS NULL THEN
                    SET $flag = 1;
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic link failure';
                END IF;
            END");
        try {
            $failure = $this->caught(fn() => export_default_activity::execute($course->id, 'book'));
            $left = array_values($DB->get_records('course_modules', ['course' => $course->id]));
            $books = array_values($DB->get_records('book', ['course' => $course->id]));
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $trigger");
            $ddl->close();
        }

        $this->assertInstanceOf(\moodle_exception::class, $failure);
        $this->assertSame('defaultactivitycleanupfailed', $failure->errorcode);
        $this->assertCount(1, $books);
        $this->assertCount(1, $left);
        $this->assertSame((int) $books[0]->id, (int) $left[0]->instance, 'The cleanup completed the link to the instance.');
    }
}
