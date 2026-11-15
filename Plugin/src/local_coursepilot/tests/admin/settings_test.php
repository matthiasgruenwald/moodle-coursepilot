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

namespace local_coursepilot\admin;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Administrative configuration remains available only to site administrators.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(settings::class)]
final class settings_test extends \advanced_testcase {
    public function test_settings_register_defaults_and_guard_the_admin_tree(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $root = new \admin_root(true);
        $root->add('root', new \admin_category('localplugins', 'Local plugins'));
        $this->assertNull(settings::register($root, false));
        $this->assertNull($root->locate('local_coursepilot'));
        $page = settings::register($root, true);
        $this->assertSame($page, $root->locate('local_coursepilot'));
        $this->assertSame('1', $page->settings->{'local_coursepilotremoteaccessenabled'}->get_defaultsetting());
        $this->assertSame('0', $page->settings->{'local_coursepilotallowpersonaldata'}->get_defaultsetting());
        $this->assertSame('coursepilot', $page->settings->{'local_coursepilotcontextroot'}->get_defaultsetting());
        $this->assertInstanceOf(\admin_externalpage::class, $root->locate('local_coursepilot_connections'));
    }
}
