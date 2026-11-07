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

use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The WebDAV adapter reports the size the server stored (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_storage_port::class)]
final class webdav_storage_port_674_final_contract_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * A write returns the size the server reports after storing, not the length of what was sent.
     */
    public function test_write_reports_the_stored_size_of_the_server(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->grant_webdav_capability($USER);
        $instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
        $inner = new fake_webdav_transport();
        $resizing = new class ($inner) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the double.
             *
             * @param fake_webdav_transport $inner Real storage fake.
             */
            public function __construct(
                /** @var fake_webdav_transport Real storage fake. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Passes the request on and lets the server report a different stored size for the file.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param string[] $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(
                string $method,
                string $url,
                array $headers = [],
                ?string $body = null
            ): \local_coursepilot\webdav\webdav_response {
                $response = $this->inner->request($method, $url, $headers, $body);
                if ($method !== 'PROPFIND' || !str_ends_with($url, '/plan.md')) {
                    return $response;
                }
                $length = '~<d:getcontentlength>\d+</d:getcontentlength>~';
                $body = preg_replace($length, '<d:getcontentlength>4242</d:getcontentlength>', $response->body);
                return new \local_coursepilot\webdav\webdav_response($response->statuscode, $response->headers, $body);
            }
        };
        $port = new webdav_storage_port($instanceid, 'size-contract-test', $resizing);
        $area = new storage_area(
            rootsetting: 'storageportcontracttestroot',
            defaultroot: 'storageport-contract-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
            },
        );

        $result = $port->write($area, 'plan.md', 'Inhalt');

        $this->assertSame(4242, $result['size']);
        $this->assertTrue($result['created']);
    }
}
