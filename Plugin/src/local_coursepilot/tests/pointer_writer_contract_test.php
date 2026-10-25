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
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_error;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact reasons, targets and pending-write replies of the pointer writer (Spec #486 §8, #492, #516).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pointer_writer::class)]
final class pointer_writer_contract_test extends \advanced_testcase {
    use caught_throwable;
    use webdav_instance_fixture;

    /**
     * Provides error classes and their full reason.
     *
     * @return array<string, array{string, string}>
     */
    public static function reasons(): array {
        $later = 'this can be added later';
        $action = 'something needs to be done on your storage';
        return [
            'throttled' => [
                webdav_error::UNCLEAR,
                'the storage is briefly throttling requests (normal on some Nextcloud instances) – ' . $later,
            ],
            'unreachable' => [webdav_error::UNREACHABLE, 'the storage is currently unreachable – ' . $later],
            'login' => [webdav_error::AUTH_REJECTED, 'the login to the storage was rejected – ' . $action],
            'full' => [webdav_error::STORAGE_FULL, 'the storage is full – ' . $action],
            'unknown class' => ['strange', 'error class "strange" – ' . $action],
        ];
    }

    /**
     * Provides the full reason of an error class.
     *
     * @param string $errorclass Error class.
     * @param string $expected Full reason.
     */
    #[DataProvider('reasons')]
    public function test_the_reason_names_the_cause_and_whether_it_will_clear_by_itself(
        string $errorclass,
        string $expected
    ): void {
        $method = new \ReflectionMethod(pointer_writer::class, 'reason_for');
        $this->assertSame($expected, $method->invoke(null, $errorclass));
        $this->assertTrue($method->isPublic());
    }

    /**
     * Logged-in teacher with an instance named "Meine Cloud".
     *
     * @return int Instance id.
     */
    private function setup_teacher(): int {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->grant_webdav_capability($USER);
        return $this->create_webdav_instance($USER);
    }

    public function test_an_iserv_failure_without_a_location_takes_host_and_instance_from_the_error(): void {
        $instanceid = $this->setup_teacher();
        $error = new \moodle_exception('webdaviservfilesonly', 'local_coursepilot', '', (object) [
            'page' => 'p',
            'server' => 'cloud.example.test',
            'instanceid' => (string) $instanceid,
        ]);

        $reply = pointer_writer::record_location_failure($error, 'dir/plan.md', null, pointer_writer::OP_CREATE, 0);

        $this->assertSame('pendingwritefailed', $reply->errorcode);
        $this->assertSame('Meine Cloud (cloud.example.test)', $reply->a->target);
        $this->assertSame('dir/plan.md', $reply->a->path);
    }

    public function test_a_failure_without_usable_instance_or_host_names_only_what_is_known(): void {
        $this->setup_teacher();
        $noinstance = new \moodle_exception('webdaviservfilesonly', 'local_coursepilot', '', (object) [
            'server' => 'cloud.example.test',
            'instanceid' => 'abc',
        ]);
        $nothing = new \moodle_exception('webdaviservfilesonly', 'local_coursepilot', '', (object) []);

        $first = pointer_writer::record_location_failure($noinstance, 'a.md', null, pointer_writer::OP_CREATE, 0);
        $second = pointer_writer::record_location_failure($nothing, 'a.md', null, pointer_writer::OP_CREATE, 0);

        $this->assertSame('cloud.example.test', $first->a->target);
        $this->assertSame('', $second->a->target);
    }

    public function test_a_resolved_location_names_the_host_of_its_fingerprint(): void {
        $instanceid = $this->setup_teacher();
        $location = pointer_location::external(
            $instanceid,
            'Ctx',
            ['server' => 'cloud.example.test', 'basepath' => '', 'account' => 'a']
        );
        $bare = pointer_location::external($instanceid, 'Ctx', []);
        $error = new \moodle_exception('webdavnotenabled', 'local_coursepilot');

        $named = pointer_writer::record_location_failure($error, 'a.md', $location, pointer_writer::OP_APPEND, 0);
        $unnamed = pointer_writer::record_location_failure($error, 'a.md', $bare, pointer_writer::OP_APPEND, 0);

        $this->assertSame('Meine Cloud (cloud.example.test)', $named->a->target);
        $this->assertSame('Meine Cloud ()', $unnamed->a->target);
        $this->assertSame(
            'external storage is no longer enabled for you – something needs to be done on your storage',
            $named->a->reason
        );
    }

    public function test_other_errors_pass_through_untouched(): void {
        $this->setup_teacher();
        $error = new \moodle_exception('pointerincomplete', 'local_coursepilot');

        $this->assertSame($error, pointer_writer::record_location_failure($error, 'a.md', null, pointer_writer::OP_CREATE, 0));
    }

    public function test_a_failed_external_write_is_recorded_with_class_reason_host_and_instance(): void {
        $instanceid = $this->setup_teacher();
        $location = pointer_location::external(
            $instanceid,
            'Ctx',
            ['server' => 'cloud.example.test', 'basepath' => '', 'account' => 'a']
        );
        $failure = new storage_failure(webdav_error::STORAGE_FULL, new webdav_error(webdav_error::STORAGE_FULL, 'HTTP 507'));
        $sink = $this->redirectEvents();

        $reply = pointer_writer::record_storage_failure($failure, $location, 'a.md', pointer_writer::OP_CREATE, 0);

        $this->assertSame('pendingwritefailed', $reply->errorcode);
        $this->assertSame('the storage is full – something needs to be done on your storage', $reply->a->reason);
        $this->assertSame('Meine Cloud (cloud.example.test)', $reply->a->target);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertSame('WebDAV storage_full: HTTP 507', $events[0]->other['reason']);
        $bare = pointer_location::external($instanceid, 'Ctx', []);
        $barereply = pointer_writer::record_storage_failure($failure, $bare, 'a.md', pointer_writer::OP_CREATE, 0);
        $this->assertSame('Meine Cloud ()', $barereply->a->target);
    }
}
