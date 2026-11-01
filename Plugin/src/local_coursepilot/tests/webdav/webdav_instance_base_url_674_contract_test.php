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


namespace local_coursepilot\webdav;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Base address of a WebDAV instance: port and path handling (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_instance::class)]
final class webdav_instance_base_url_674_contract_test extends \advanced_testcase {
    /**
     * Calls the private base_url().
     *
     * @param mixed[] $options Instance options.
     * @return string
     */
    private function base_url(array $options): string {
        return (new \ReflectionMethod(webdav_instance::class, 'base_url'))->invoke(null, $options);
    }

    public function test_a_port_and_a_path_are_part_of_the_address_with_a_trailing_slash(): void {
        $url = $this->base_url(['webdav_server' => 'h.example', 'webdav_port' => '8443', 'webdav_path' => '/dav/x/']);
        $this->assertSame('https://h.example:8443/dav/x/', $url);
    }

    public function test_the_default_port_zero_and_an_empty_path_give_the_bare_host(): void {
        $this->assertSame('https://h.example/', $this->base_url(['webdav_server' => 'h.example', 'webdav_port' => '0']));
        $this->assertSame('https://h.example/', $this->base_url(['webdav_server' => 'h.example', 'webdav_path' => '']));
    }

    public function test_a_non_numeric_port_counts_as_no_port(): void {
        $this->assertSame('https://h.example/', $this->base_url(['webdav_server' => 'h.example', 'webdav_port' => 'abc']));
    }
}
