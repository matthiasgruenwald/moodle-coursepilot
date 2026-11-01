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
// along with Coursepilot.  If not, see <http://www.gnu.org/licenses/>.

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Administrator token listing exposes the owner's name fields as real columns.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
final class connection_active_tokens_674_contract_test extends \advanced_testcase {
    /**
     * Each active token row carries the owner's name and email under plain column names.
     */
    public function test_active_tokens_exposes_owner_name_and_email_columns(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada.674@example.com',
        ]);
        $response = oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Testclient',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]), '192.0.2.1');
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $clientid = $response['body']['client_id'];

        $code = connection::issue_code($clientid, (int) $user->id, $redirect, $challenge);
        $this->assertNotNull(connection::exchange_code($code, $clientid, $redirect, $verifier));

        $rows = connection::active_tokens();
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('Ada', $row->firstname);
        $this->assertSame('Lovelace', $row->lastname);
        $this->assertSame('ada.674@example.com', $row->email);
        $this->assertSame('Testclient', $row->clientname);
        $this->assertSame((int) $user->id, (int) $row->userid);
    }
}
