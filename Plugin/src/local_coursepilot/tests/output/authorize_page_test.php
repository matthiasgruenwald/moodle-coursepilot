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

namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template-Datenaufbereitung fuer oauth/authorize.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(authorize_page::class)]
final class authorize_page_test extends \advanced_testcase {

    public function test_consent_text_names_the_client_and_contains_markup(): void {
        $this->resetAfterTest();

        $data = authorize_page::page_data(
            'Claude Desktop',
            ['client_id' => 'abc', 'redirect_uri' => 'https://example.test/cb'],
            'thestate',
            new \moodle_url('/local/coursepilot/oauth/authorize.php'),
            new \moodle_url('/local/coursepilot/ortswahl.php')
        );

        $this->assertStringContainsString('Claude Desktop', $data['consenttext']);
        $this->assertStringContainsString('<strong>', $data['consenttext']);
    }

    public function test_hidden_fields_carry_all_params_and_state(): void {
        $this->resetAfterTest();

        $data = authorize_page::page_data(
            'Claude Desktop',
            ['client_id' => 'abc', 'redirect_uri' => 'https://example.test/cb'],
            'thestate',
            new \moodle_url('/local/coursepilot/oauth/authorize.php'),
            new \moodle_url('/local/coursepilot/ortswahl.php')
        );

        $names = array_column($data['hiddenfields'], 'name');
        $this->assertContains('client_id', $names);
        $this->assertContains('redirect_uri', $names);
        $this->assertContains('state', $names);

        $byname = array_combine($names, array_column($data['hiddenfields'], 'value'));
        $this->assertSame('thestate', $byname['state']);
    }

    public function test_actions_offer_allow_and_deny(): void {
        $this->resetAfterTest();

        $data = authorize_page::page_data(
            'Claude Desktop',
            [],
            '',
            new \moodle_url('/local/coursepilot/oauth/authorize.php'),
            new \moodle_url('/local/coursepilot/ortswahl.php')
        );

        $actionvalues = array_column($data['actions'], 'actionvalue');
        $this->assertSame(['allow', 'deny'], $actionvalues);
    }
}
