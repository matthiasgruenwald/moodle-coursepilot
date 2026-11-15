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
 * Construction, choice loading and the member notes of the remote access cohort setting (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(remoteaccesscohorts_setting::class)]
final class remoteaccesscohorts_setting_674_contract_test extends \advanced_testcase {
    public function test_the_setting_is_named_and_described(): void {
        $setting = new remoteaccesscohorts_setting();

        $this->assertSame('remoteaccesscohorts', $setting->name);
        $this->assertSame('local_coursepilot', $setting->plugin);
        $this->assertSame(get_string('settingremoteaccesscohorts', 'local_coursepilot'), $setting->visiblename);
        $this->assertSame(get_string('settingremoteaccesscohorts_desc', 'local_coursepilot'), $setting->description);
    }

    public function test_loading_the_choices_succeeds_and_lists_the_system_cohorts(): void {
        $this->resetAfterTest();
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Kollegium']);
        $setting = new remoteaccesscohorts_setting();

        $this->assertTrue($setting->load_choices());
        $this->assertSame('Kollegium', $setting->choices[$cohort->id]);
        $this->assertTrue($setting->load_choices(), 'A second call keeps the loaded choices.');
    }

    public function test_the_html_appends_the_member_notes_to_the_description_and_restores_it(): void {
        global $DB;
        $this->resetAfterTest();
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Kollegium']);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('cohort_members', (object) ['cohortid' => $cohort->id, 'userid' => $user->id, 'timeadded' => time()]);
        set_config('remoteaccesscohorts', (string) $cohort->id, 'local_coursepilot');
        $setting = new remoteaccesscohorts_setting();
        $description = $setting->description;

        $html = $setting->output_html([]);

        $this->assertStringContainsString($description, $html);
        $this->assertStringContainsString('Kollegium: 1 member(s)', $html);
        $this->assertSame($description, $setting->description);
    }
}
