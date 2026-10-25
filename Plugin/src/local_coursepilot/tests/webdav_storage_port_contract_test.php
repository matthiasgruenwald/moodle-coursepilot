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
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\hiding_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_transport;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact logging, paths, depths and write reports of the WebDAV storage adapter (Spec 0021).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_storage_port::class)]
final class webdav_storage_port_contract_test extends \advanced_testcase {
    use caught_throwable;
    use webdav_instance_fixture;

    /** @var int Instance of the test user. */
    private int $instanceid;

    /** @var fake_webdav_transport Shared server double. */
    private fake_webdav_transport $fake;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Teacher with a WebDAV instance whose base path is the server root.
     */
    private function setup_instance(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->grant_webdav_capability($USER);
        $this->instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
        $this->fake = new fake_webdav_transport();
    }

    /**
     * Area that accepts markdown files.
     *
     * @return storage_area
     */
    private function area(): storage_area {
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

    public function test_a_failing_iserv_check_is_logged_with_class_and_message_and_the_listing_still_works(): void {
        $this->setup_instance();
        $this->fake->seed_folder('/Sub');
        $this->fake->seed_file('/Sub/a.md', 'x');
        \core\di::set(webdav_transport::class, $this->fake);
        $this->fake->fail_once('/', 401);
        $sink = $this->redirectEvents();
        // The listing of "Sub" is requested first and succeeds; the root check afterwards fails once.
        $result = webdav_storage_port::browse_location($this->instanceid, 'Sub');

        $this->assertFalse($result['iserv']);
        $this->assertSame(['a.md'], array_column($result['entries'], 'name'));
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_coursepilot\event\tool_access_failed::class, $events[0]);
        $this->assertSame('WebDAV auth_rejected during IServ detection: HTTP 401', $events[0]->other['reason']);
    }

    public function test_listing_the_base_folder_and_a_subfolder_return_their_own_entries(): void {
        $this->setup_instance();
        $this->fake->seed_folder('/Base');
        $this->fake->seed_file('/Base/top.md', 't');
        $this->fake->seed_folder('/Base/sub');
        $this->fake->seed_file('/Base/sub/inner.md', 'i');
        $port = new webdav_storage_port($this->instanceid, 'Base', $this->fake);

        $top = array_column($port->list($this->area(), ''), 'name');
        $sub = array_column($port->list($this->area(), 'sub'), 'name');

        sort($top);
        $this->assertSame(['sub', 'top.md'], $top);
        $this->assertSame(['inner.md'], $sub);
    }

    public function test_writes_and_appends_report_the_client_path_size_and_creation(): void {
        $this->setup_instance();
        $port = new webdav_storage_port($this->instanceid, 'Base', $this->fake);

        $written = $port->write($this->area(), 'dir/plan.md', 'Hello');
        $appended = $port->append($this->area(), 'dir/plan.md', ' world');
        $fresh = $port->append($this->area(), 'dir/new.md', 'Tail');

        $this->assertSame('dir/plan.md', $written['path']);
        $this->assertTrue($written['created']);
        $this->assertSame(5, $written['size']);
        $this->assertSame('dir/plan.md', $appended['path']);
        $this->assertFalse($appended['created']);
        $this->assertSame(11, $appended['size']);
        $this->assertSame('dir/new.md', $fresh['path']);
        $this->assertTrue($fresh['created']);
        $this->assertSame(4, $fresh['size']);
        $this->assertSame('Hello world', $port->read($this->area(), 'dir/plan.md')['content']);
    }

    public function test_empty_parts_of_the_base_folder_never_reach_the_server_as_double_slashes(): void {
        $this->setup_instance();
        $this->fake->seed_folder('/Teach');
        $this->fake->seed_folder('/Teach/Ctx');
        $port = new webdav_storage_port($this->instanceid, '/Teach//Ctx/', $this->fake);

        $port->write($this->area(), 'plan.md', 'x');

        foreach ($this->fake->requests() as $request) {
            $this->assertStringNotContainsString('//', substr($request['url'], strlen('https://')), $request['url']);
        }
        $puts = array_values(array_filter($this->fake->requests(), static fn(array $r): bool => $r['method'] === 'PUT'));
        $this->assertStringEndsWith('/Teach/Ctx/plan.md', $puts[0]['url']);
    }

    public function test_the_context_root_of_a_pointer_is_checked_with_depth_zero(): void {
        $this->setup_instance();
        $this->fake->seed_folder('/Base');
        $location = pointer_location::external($this->instanceid, 'Base', [
            'server' => $this->fixtureserver,
            'basepath' => '',
            'account' => $this->fixturekonto,
        ]);
        $port = new webdav_storage_port($this->instanceid, 'Base', $this->fake, $location);

        $port->write($this->area(), 'plan.md', 'x');

        $propfinds = array_values(
            array_filter($this->fake->requests(), static fn(array $r): bool => $r['method'] === 'PROPFIND')
        );
        $this->assertSame('0', $propfinds[0]['headers']['Depth']);
    }

    public function test_a_write_whose_result_cannot_be_read_back_reports_the_content_length_and_a_zero_time(): void {
        $this->setup_instance();
        $hiding = new hiding_transport($this->fake);
        $port = new webdav_storage_port($this->instanceid, 'Base', $hiding);

        $written = $port->write($this->area(), 'plan.md', 'Hello');
        $hiding->reset();
        $appended = $port->append($this->area(), 'other.md', '!');

        $this->assertSame(5, $written['size']);
        $this->assertSame(pointer_reader::external_checkvalue(null, 0), $written['checksum']);
        $this->assertSame(pointer_reader::external_checkvalue(null, 0), $appended['checksum']);
    }
}
