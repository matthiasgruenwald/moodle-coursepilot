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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Anonymous registration budgets and size limits at the public DCR boundary (#642).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(oauth_budget::class)]
#[CoversClass(oauth_lib::class)]
#[CoversClass(task\oauth_cleanup::class)]
final class oauth_registration_budget_test extends \advanced_testcase {
    use \local_coursepilot\tests\oauth_budget_race;

    /**
     * Table.
     */
    private const TABLE = 'local_coursepilot_oauth_budget';

    /**
     * Callback.
     */
    private const CALLBACK = 'https://client.example/callback';

    /**
     * Registers the oauth registration budget test.
     *
     * @param string $source The source.
     * @param ?string $body The body.
     * @return array
     */
    private function register(string $source, ?string $body = null): array {
        return oauth_lib::handle_registration(
            'POST',
            $body ?? json_encode(['client_name' => 'Synthetic', 'redirect_uris' => [self::CALLBACK]]),
            $source
        );
    }

    /**
     * Provides limits.
     *
     * @param int $site The site.
     * @param int $source The source.
     */
    private function limits(int $site, int $source): void {
        set_config('oauthregistersitelimit', $site, 'local_coursepilot');
        set_config('oauthregistersourcelimit', $source, 'local_coursepilot');
    }

    /**
     * Provides clients.
     *
     * @return int
     */
    private function clients(): int {
        global $DB;
        return $DB->count_records('local_coursepilot_oauth_client');
    }

    public function test_source_and_site_budgets_allow_last_and_reject_first_excess(): void {
        $this->resetAfterTest();
        $this->limits(3, 2);

        $this->assertSame(201, $this->register('192.0.2.1')['status']);
        $this->assertSame(201, $this->register('192.0.2.1')['status'], 'Last request within the source budget.');
        $rejected = $this->register('192.0.2.1');
        $this->assertSame(429, $rejected['status'], 'First request beyond the source budget.');
        $this->assertSame('temporarily_unavailable', $rejected['body']['error']);
        $this->assertGreaterThan(0, (int) $rejected['headers']['Retry-After']);
        $this->assertSame(2, $this->clients());

        $this->assertSame(201, $this->register('192.0.2.2')['status'], 'Source rejection must not consume site budget.');
        $this->assertSame(429, $this->register('192.0.2.3')['status'], 'First request beyond the site budget.');
        $this->assertSame(3, $this->clients());
    }

    public function test_unset_or_unlimited_settings_fall_back_to_finite_values(): void {
        $this->resetAfterTest();
        $this->limits(0, -5);
        $this->assertSame(201, $this->register('192.0.2.1')['status']);
        $this->assertSame(429, $this->register('192.0.2.1')['status'], 'Non-positive limits never mean unlimited.');
        unset_config('oauthregistersitelimit', 'local_coursepilot');
        unset_config('oauthregistersourcelimit', 'local_coursepilot');
        unset_config('oauthregisterwindow', 'local_coursepilot');
        $this->assertSame(oauth_lib::REGISTRATION_SITE_LIMIT, oauth_budget::setting(
            'oauthregistersitelimit',
            oauth_lib::REGISTRATION_SITE_LIMIT
        ));
        set_config('oauthregisterwindow', 0, 'local_coursepilot');
        $this->assertSame(1, oauth_budget::setting('oauthregisterwindow', oauth_lib::REGISTRATION_WINDOW));
    }

    public function test_scheduled_task_purges_expired_budget_without_new_requests(): void {
        global $DB;
        $this->resetAfterTest();
        $this->register('192.0.2.1');
        $DB->insert_record(self::TABLE, (object) ['scope' => 'register', 'sourcekey' => 'stale',
            'expires' => time(), 'hits' => 1]);

        (new task\oauth_cleanup())->execute();

        $this->assertFalse($DB->record_exists(self::TABLE, ['sourcekey' => 'stale']));
        $this->assertSame(2, $DB->count_records(self::TABLE), 'Current site and source windows remain.');
        $this->assertTrue((bool) \core\task\manager::get_scheduled_task(task\oauth_cleanup::class));
    }

    public function test_oversized_body_and_uris_are_rejected_without_client_or_budget(): void {
        global $DB;
        $this->resetAfterTest();
        $this->limits(1, 1);

        $huge = json_encode(['redirect_uris' => [self::CALLBACK],
            'client_name' => str_repeat('a', oauth_lib::REGISTRATION_MAX_BODY_BYTES)]);
        $response = $this->register('192.0.2.1', $huge);
        $this->assertSame(413, $response['status']);
        $this->assertSame('invalid_client_metadata', $response['body']['error']);

        $longuri = 'https://client.example/' . str_repeat('a', oauth_lib::REGISTRATION_MAX_URI_LENGTH);
        $this->assertSame(400, $this->register(
            '192.0.2.1',
            json_encode(['redirect_uris' => [$longuri]])
        )['status']);
        $many = array_fill(0, oauth_lib::REGISTRATION_MAX_REDIRECT_URIS + 1, self::CALLBACK);
        $this->assertSame(400, $this->register('192.0.2.1', json_encode(['redirect_uris' => $many]))['status']);
        $this->assertSame(0, $this->clients());
        $this->assertSame(0, $DB->count_records(self::TABLE), 'Rejected sizes must not create budget state.');

        $this->assertSame(201, $this->register('192.0.2.1')['status'], 'Budget remains available after size rejections.');
    }

    public function test_long_client_name_is_truncated_to_column_size(): void {
        $this->resetAfterTest();
        $response = $this->register('192.0.2.1', json_encode(['redirect_uris' => [self::CALLBACK],
            'client_name' => str_repeat('b', 400)]));
        $this->assertSame(201, $response['status']);
        $this->assertSame(255, \core_text::strlen($response['body']['client_name']));
    }

    public function test_budget_state_is_bounded_and_expired_rows_are_purged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->limits(3, 1);
        $DB->insert_record(self::TABLE, (object) ['scope' => 'register', 'sourcekey' => 'stale',
            'expires' => time() - 1, 'hits' => 9]);

        for ($i = 1; $i <= 20; $i++) {
            $this->register('198.51.100.' . $i);
        }

        $this->assertFalse($DB->record_exists(self::TABLE, ['sourcekey' => 'stale']));
        $this->assertSame(3, $this->clients());
        $this->assertLessThanOrEqual(
            4,
            $DB->count_records(self::TABLE),
            'Per-source rows only exist for requests that passed the site budget.'
        );
        foreach ($DB->get_records(self::TABLE) as $row) {
            $this->assertLessThanOrEqual(time() + oauth_lib::REGISTRATION_WINDOW, (int) $row->expires);
            $this->assertStringNotContainsString('198.51.100', $row->sourcekey, 'No raw addresses in budget state.');
        }
    }

    public function test_source_ignores_unverified_forwarded_headers(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->getremoteaddrconf = GETREMOTEADDR_SKIP_HTTP_X_FORWARDED_FOR | GETREMOTEADDR_SKIP_HTTP_CLIENT_IP;
        $CFG->reverseproxyignore = '';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.50';
        $first = oauth_budget::request_source();
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.51';
        $_SERVER['HTTP_CLIENT_IP'] = '192.0.2.52';
        $this->assertSame($first, oauth_budget::request_source());
        $this->assertSame('203.0.113.7', $first);

        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:aaaa::1';
        $a = oauth_budget::request_source();
        $_SERVER['REMOTE_ADDR'] = '2001:db8:1:2:bbbb::2';
        $this->assertSame($a, oauth_budget::request_source(), 'IPv6 sources share their /64 budget.');
    }

    public function test_authorized_clients_keep_working_when_registration_is_exhausted(): void {
        $this->resetAfterTest();
        $this->limits(1, 1);
        $clientid = $this->register('192.0.2.1')['body']['client_id'];
        $this->assertSame(429, $this->register('192.0.2.9')['status']);

        $user = $this->getDataGenerator()->create_user();
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->assertArrayHasKey('client', oauth_lib::validate_authorize_request(['response_type' => 'code',
            'client_id' => $clientid, 'redirect_uri' => self::CALLBACK, 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256']));
        $this->assertSame('invalid_request', oauth_lib::validate_authorize_request(['response_type' => 'code',
            'client_id' => $clientid, 'redirect_uri' => self::CALLBACK, 'code_challenge' => $challenge,
            'code_challenge_method' => 'plain'])['error'], 'PKCE stays mandatory.');

        $code = oauth_lib::issue_code($clientid, (int) $user->id, self::CALLBACK, $challenge);
        $this->assertSame(400, oauth_lib::handle_token('POST', ['grant_type' => 'authorization_code',
            'client_id' => $clientid, 'code' => $code, 'redirect_uri' => self::CALLBACK,
            'code_verifier' => 'wrong-verifier'])['status'], 'Grant checks are not relaxed.');
        $code = oauth_lib::issue_code($clientid, (int) $user->id, self::CALLBACK, $challenge);
        $tokens = oauth_lib::handle_token('POST', ['grant_type' => 'authorization_code', 'client_id' => $clientid,
            'code' => $code, 'redirect_uri' => self::CALLBACK, 'code_verifier' => $verifier]);
        $this->assertSame(200, $tokens['status']);
        $this->assertSame(200, oauth_lib::handle_token('POST', ['grant_type' => 'refresh_token',
            'client_id' => $clientid, 'refresh_token' => $tokens['body']['refresh_token']])['status']);
    }

    /**
     * Real processes contend for the last allowed registration behind a DB barrier.
     */
    public function test_parallel_registrations_cannot_exceed_budget(): void {
        $this->resetAfterTest();
        $this->limits(1, 5);
        // Commit the settings so the separate processes see them.
        $this->assertSame(1, oauth_budget::setting('oauthregistersitelimit', 99));

        $this->assertSame([201, 429], $this->race_on_site_budget(['192.0.2.1'], ['192.0.2.2']));
        $this->assertSame(1, $this->clients());
    }
}
