<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

use local_coursepilot\webdav\webdav_error;
use local_coursepilot\webdav\webdav_setup_steps;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Direct check-value and error-translation tests for pointer_reader
 * (Issues #513/#526/#529). Context/material tool tests cover reads and lists.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pointer_reader::class)]
final class pointer_reader_test extends \advanced_testcase {
    /**
     * ETag takes precedence over getlastmodified; SHA-1 hex is safe for
     * PARAM_ALPHANUMEXT even when ETags contain quotes.
     */
    public function test_external_checkvalue_prefers_etag(): void {
        $withetag = pointer_reader::external_checkvalue('"abc"', 1700000000);

        $this->assertSame(sha1('etag:"abc"'), $withetag);
        $this->assertSame($withetag, pointer_reader::external_checkvalue('"abc"', 1800000000));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $withetag);
    }

    /**
     * Without ETags (IServ), getlastmodified supplies the check value;
     * a changed timestamp changes that value.
     */
    public function test_external_checkvalue_falls_back_to_timemodified(): void {
        $first = pointer_reader::external_checkvalue(null, 1700000000);

        $this->assertSame(sha1('mtime:1700000000'), $first);
        $this->assertNotSame($first, pointer_reader::external_checkvalue(null, 1700000001));
    }

    /**
     * Default key: include localized error class and location-selection page,
     * never raw error details (secret-protection test, Spec #486).
     */
    public function test_webdav_exception_uses_default_key_without_raw_detail(): void {
        $error = new webdav_error(webdav_error::AUTH_REJECTED, 'secret-host user:hunter2 HTTP 401');

        $exception = pointer_reader::webdav_exception($error);

        $this->assertSame('webdavexternalerror', $exception->errorcode);
        $this->assertStringContainsString(webdav_error::label(webdav_error::AUTH_REJECTED), $exception->getMessage());
        $this->assertStringContainsString(webdav_setup_steps::LOCATION_SELECTION_PAGE, $exception->getMessage());
        $this->assertStringNotContainsString('hunter2', $exception->getMessage());
        $this->assertStringNotContainsString('secret-host', $exception->getMessage());
    }

    /**
     * Throttling replaces the default message with wait-and-retry guidance (#529).
     */
    public function test_webdav_exception_switches_to_unclear_text_on_default_key(): void {
        $exception = pointer_reader::webdav_exception(new webdav_error(webdav_error::UNCLEAR));

        $this->assertSame('webdavexternalerrorunclear', $exception->errorcode);
    }

    /**
     * Explicit location-selection/material keys remain unchanged during
     * throttling because they address different audiences.
     */
    public function test_webdav_exception_keeps_custom_key_on_unclear(): void {
        $exception = pointer_reader::webdav_exception(new webdav_error(webdav_error::UNCLEAR), 'materialexternalerror');

        $this->assertSame('materialexternalerror', $exception->errorcode);
    }
}
