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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Client registration and lookup through the public registry.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(client_registry::class)]
final class client_registry_test extends \advanced_testcase {
    /**
     * Registration returns the unchanged public metadata and an exactly matched redirect.
     */
    public function test_registered_client_can_be_found_and_redirect_checked(): void {
        $this->resetAfterTest();
        $registry = new client_registry();
        $response = $registry->register_client(['client_name' => 'Synthetic registry client',
            'redirect_uris' => ['https://client.example/callback']]);
        $client = $registry->get_client($response['client_id']);

        $this->assertNotNull($client);
        $this->assertSame('dcr', $client->source);
        $this->assertSame([
            'client_id' => $client->clientid,
            'client_name' => 'Synthetic registry client',
            'redirect_uris' => ['https://client.example/callback'],
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], $response);
        $this->assertTrue($registry->redirect_uri_matches($client, 'https://client.example/callback'));
        $this->assertFalse($registry->redirect_uri_matches($client, 'https://client.example/callback/extra'));
    }
    /**
     * CIMD metadata is fetched once, cached and subject to exact redirect checks.
     */
    public function test_cimd_lookup_uses_adapter_and_keeps_first_document(): void {
        $this->resetAfterTest();
        $fetcher = new tests\cimd_fetch_fixture();
        $registry = new client_registry($fetcher);
        $url = 'https://client.example/metadata.json';
        $client = $registry->get_client($url);
        $this->assertNotNull($client);
        $this->assertSame('cimd', $client->source);
        $this->assertSame($url, $client->clientid);
        $this->assertSame('none', $client->tokenendpointauthmethod);
        $this->assertNull($client->clientsecret);
        $fetcher->metadata = ['redirect_uris' => ['https://other.example/callback']];
        $retryafter = 42;
        $cached = $registry->get_client($url, $retryafter);
        $this->assertSame((int) $client->id, (int) $cached->id);
        $this->assertSame(0, $retryafter);
        $this->assertSame([$url], $fetcher->requests);
        $this->assertTrue($registry->redirect_uri_matches($cached, 'https://client.example/callback'));
        $this->assertFalse($registry->redirect_uri_matches($cached, 'https://client.example/callback?extra=1'));
        $this->assertFalse($registry->redirect_uri_matches($cached, 'https://other.example/callback'));
    }

    /**
     * Invalid identifiers and failed metadata never cause repeated adapter calls.
     */
    public function test_failed_metadata_and_invalid_ids_are_not_fetched_again(): void {
        $this->resetAfterTest();
        $fetcher = new tests\cimd_fetch_fixture();
        $registry = new client_registry($fetcher);
        foreach (['unknown', 'http://client.example/metadata', 'https://client.example/' . str_repeat('a', 255)] as $id) {
            $this->assertNull($registry->get_client($id));
        }
        $this->assertSame([], $fetcher->requests);
        foreach ([null, [], ['redirect_uris' => ['http://evil.example/callback']]] as $i => $metadata) {
            $fetcher->metadata = $metadata;
            $url = 'https://client.example/failure' . $i;
            $this->assertNull($registry->get_client($url));
            $retryafter = 42;
            $this->assertNull($registry->get_client($url, $retryafter));
            $this->assertSame(0, $retryafter);
        }
        $this->assertCount(3, $fetcher->requests);
    }

    /**
     * Source and site exhaustion report retryafter before consulting the adapter.
     */
    public function test_cimd_budget_limits_do_not_fetch_rejected_clients(): void {
        $this->resetAfterTest();
        set_config('oauthcimdsitelimit', 2, 'local_coursepilot');
        set_config('oauthcimdsourcelimit', 1, 'local_coursepilot');
        $fetcher = new tests\cimd_fetch_fixture();
        $registry = new client_registry($fetcher);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $this->assertNotNull($registry->get_client('https://client.example/first'));
        $retryafter = 0;
        $this->assertNull($registry->get_client('https://client.example/source-excess', $retryafter));
        $this->assertGreaterThan(0, $retryafter);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
        $this->assertNotNull($registry->get_client('https://client.example/second', $retryafter));
        $this->assertSame(0, $retryafter);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.3';
        $this->assertNull($registry->get_client('https://client.example/site-excess', $retryafter));
        $this->assertGreaterThan(0, $retryafter);
        $this->assertNotNull($registry->get_client('https://client.example/first', $retryafter));
        $this->assertSame(0, $retryafter);
        $this->assertSame(['https://client.example/first', 'https://client.example/second'], $fetcher->requests);
    }

    /**
     * Registration validation refuses invalid metadata through the registry itself.
     */
    public function test_invalid_registration_is_refused(): void {
        $this->resetAfterTest();
        $registry = new client_registry();
        $invalidmetadata = [[], ['redirect_uris' => []],
            ['redirect_uris' => array_fill(0, 11, 'https://client.example/callback')]];
        foreach ($invalidmetadata as $metadata) {
            $this->assertSame('invalid_client_metadata', $registry->register_client($metadata)['error']);
        }
        foreach (['https://client.example/' . str_repeat('x', 2048), 'http://evil.example/callback', '', 123, 'https://'] as $uri) {
            $this->assertSame('invalid_redirect_uri', $registry->register_client(['redirect_uris' => [$uri]])['error']);
        }
    }

    /**
     * Secret clients retain their public registration response and loopback redirects.
     */
    public function test_secret_client_registration_retains_secret_and_sanitizes_name(): void {
        $this->resetAfterTest();
        $registry = new client_registry();
        $response = $registry->register_client(['client_name' => '<b>Synthetic client</b>',
            'redirect_uris' => ['http://127.0.0.1:8000/callback'], 'token_endpoint_auth_method' => 'client_secret_post']);
        $this->assertSame('Synthetic client', $response['client_name']);
        $this->assertSame('client_secret_post', $response['token_endpoint_auth_method']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response['client_secret']);
        $client = $registry->get_client($response['client_id']);
        $this->assertSame($response['client_secret'], $client->clientsecret);
        $this->assertSame($response, $registry->client_registration_response($client));
        $fallback = $registry->register_client(['redirect_uris' => ['http://localhost/callback'],
            'token_endpoint_auth_method' => 'unsupported']);
        $this->assertSame('none', $fallback['token_endpoint_auth_method']);
        $this->assertNull($fallback['client_name']);
        $this->assertArrayNotHasKey('client_secret', $fallback);
    }
}
