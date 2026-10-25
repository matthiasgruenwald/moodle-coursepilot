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

use local_coursepilot\admin\personaldatahosts_setting;

/**
 * Approved personal-data hosts (Issue #493, ADR 0021 §3): domains and
 * subdomains, rejecting single-part names and wildcards on save.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(personal_data_hosts::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(personaldatahosts_setting::class)]
final class personal_data_hosts_test extends \advanced_testcase {
    /**
     * An empty list approves no external hosts.
     */
    public function test_empty_list_allows_nothing(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', '', 'local_coursepilot');

        $this->assertFalse(personal_data_hosts::allowed('cloud.example.test'));
    }

    /**
     * An exact configured host is approved.
     */
    public function test_exact_match_is_allowed(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "cloud.example.test", 'local_coursepilot');

        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
    }

    /**
     * An entry covers the domain and its subdomains.
     */
    public function test_entry_covers_subdomains(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_coursepilot');

        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
        $this->assertTrue(personal_data_hosts::allowed('nextcloud.schule.example.test'));
    }

    /**
     * Match dot boundaries: anders-example.test is not a subdomain of example.test.
     */
    public function test_only_dot_separated_suffix_matches(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_coursepilot');

        $this->assertFalse(personal_data_hosts::allowed('anders-example.test'));
    }

    /**
     * An unlisted host is rejected.
     */
    public function test_unrelated_host_is_not_allowed(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_coursepilot');

        $this->assertFalse(personal_data_hosts::allowed('anderer-speicher.test'));
    }

    /**
     * For multiple lines, report the first invalid entry.
     */
    public function test_multiple_lines_all_apply(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test\nschulcloud.test", 'local_coursepilot');

        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
        $this->assertTrue(personal_data_hosts::allowed('schulcloud.test'));
    }

    /**
     * Reject single-part names.
     */
    public function test_rejects_single_label_entry(): void {
        $this->assertSame('localhost', personal_data_hosts::first_invalid_entry('localhost'));
    }

    /**
     * Wildcards are outside the syntax and are rejected.
     */
    public function test_rejects_wildcard_entry(): void {
        $this->assertSame('*.example.test', personal_data_hosts::first_invalid_entry('*.example.test'));
    }

    /**
     * Accept valid entries with at least two name parts.
     */
    public function test_accepts_valid_entry(): void {
        $this->assertNull(personal_data_hosts::first_invalid_entry('cloud.example.test'));
    }

    /**
     * For multiline input, identify the first invalid entry.
     */
    public function test_reports_first_invalid_entry_among_several_lines(): void {
        $this->assertSame(
            'localhost',
            personal_data_hosts::first_invalid_entry("cloud.example.test\nlocalhost\nschulcloud.test")
        );
    }

    /**
     * Empty input is valid, representing an empty list.
     */
    public function test_empty_input_is_valid(): void {
        $this->assertNull(personal_data_hosts::first_invalid_entry(''));
        $this->assertNull(personal_data_hosts::first_invalid_entry("\n\n"));
    }

    /**
     * The admin setting rejects single-part names on save, using the shared
     * validation rule (Issue #493).
     */
    public function test_admin_setting_rejects_single_label_entry_on_save(): void {
        $this->resetAfterTest();
        $setting = new personaldatahosts_setting('local_coursepilot/personaldatahosts', 'x', 'y', '', PARAM_RAW);

        $this->assertIsString($setting->validate('localhost'));
    }

    /**
     * The admin setting accepts a valid entry.
     */
    public function test_admin_setting_accepts_valid_entry(): void {
        $this->resetAfterTest();
        $setting = new personaldatahosts_setting('local_coursepilot/personaldatahosts', 'x', 'y', '', PARAM_RAW);

        $this->assertTrue($setting->validate('cloud.example.test'));
    }

    /**
     * A blank host is never approved, even when entries exist.
     */
    public function test_blank_host_is_not_allowed(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_coursepilot');

        $this->assertFalse(personal_data_hosts::allowed('  '));
    }

    /**
     * An external location on an unapproved host is rejected with the path in the message.
     */
    public function test_unapproved_external_location_is_rejected(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_coursepilot');
        $location = pointer_location::external(1, 'a/b.md', ['server' => 'cloud.other.test']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('contextfilehostnotallowed', 'local_coursepilot', 'a/b.md'));
        personal_data_hosts::require_allowed_location($location, 'a/b.md');
    }

    /**
     * Approved external hosts, Private Files and a missing location pass.
     */
    public function test_approved_or_private_locations_pass(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "example.test", 'local_coursepilot');

        $approved = pointer_location::external(1, 'a.md', ['server' => 'cloud.example.test']);
        personal_data_hosts::require_allowed_location($approved, 'a.md');
        personal_data_hosts::require_allowed_location(pointer_location::moodle('/a'), 'a.md');
        personal_data_hosts::require_allowed_location(null, 'a.md');
        $this->assertTrue(personal_data_hosts::allowed('cloud.example.test'));
    }

    /**
     * An absent value is valid and is not passed on as null to the entry check.
     */
    public function test_admin_setting_accepts_an_absent_value(): void {
        $this->resetAfterTest();
        $setting = new personaldatahosts_setting('local_coursepilot/personaldatahosts', 'x', 'y', '', PARAM_RAW);

        try {
            $result = $setting->validate(null);
        } catch (\TypeError $e) {
            $this->fail('An absent value must not reach the entry check as null: ' . $e->getMessage());
        }
        $this->assertTrue($result);
    }
}
