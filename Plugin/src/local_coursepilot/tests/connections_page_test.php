<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursepilot;

/**
 * Access control for the personal connections page (Issue #548).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class connections_page_test extends \advanced_testcase {
    public function test_rejects_a_user_without_remote_access_capability(): void {
        $source = (string) file_get_contents(__DIR__ . '/../connections.php');
        $this->assertStringContainsString(
            "require_capability('local/coursepilot:useremote', \$context);",
            $source
        );

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        require_capability('local/coursepilot:useremote', \context_system::instance());
    }
}
