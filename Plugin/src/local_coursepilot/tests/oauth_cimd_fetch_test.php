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

/**
 * Real TLS/streaming regressions at the public client lookup boundary.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Real TLS/streaming regressions at the public client lookup boundary.
 */
#[CoversClass(oauth_lib::class)]
final class oauth_cimd_fetch_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;
    use \local_coursepilot\tests\oauth_budget_race;

    /**
     * A CA-signed local peer works; untrusted and mismatched peers cannot register clients.
     */
    public function test_tls_identity_is_required(): void {
        $this->with_peer('trusted', function (string $base): void {
            $client = oauth_lib::get_client($base . '/valid');
            $this->assertNotNull($client);
            $this->assertSame('Synthetic TLS client', $client->clientname);
            $this->assertSame((int) $client->id, (int) oauth_lib::get_client($base . '/valid')->id);
        });
        foreach (['selfsigned', 'wronghost'] as $identity) {
            $this->with_peer($identity, fn(string $base) => $this->assert_rejected($base . '/valid'));
        }
    }

    /**
     * The receive limit applies without Content-Length, including decoded chunked bodies.
     */
    public function test_oversized_streaming_metadata_is_not_cached(): void {
        foreach (['/chunked', '/unframed'] as $path) {
            $this->with_peer('trusted', function (string $base) use ($path): void {
                $start = microtime(true);
                $this->assert_rejected($base . $path);
                // The peer keeps the body open past the timeout: reject during receipt.
                $this->assertLessThan(3.0, microtime(true) - $start);
            });
        }
    }

    /**
     * Moodle host/port policy, redirects and the existing five-second deadline remain enforced.
     */
    public function test_fetch_policy_and_deadline_are_preserved(): void {
        global $CFG;
        $this->with_peer('trusted', function (string $base, string $requests) use ($CFG): void {
            $CFG->curlsecurityblockedhosts = 'localhost';
            $this->assert_rejected($base . '/blocked-host');
            $this->assertDebuggingCalledCount(1);
            $CFG->curlsecurityblockedhosts = '';
            $allowed = $CFG->curlsecurityallowedport;
            $CFG->curlsecurityallowedport = '443';
            $this->assert_rejected($base . '/blocked-port');
            $this->assertDebuggingCalledCount(1);
            $CFG->curlsecurityallowedport = $allowed;
            $this->assertFileDoesNotExist($requests);
            $this->assert_rejected($base . '/redirect');
            $this->assertSame(1, substr_count(file_get_contents($requests), 'GET '));
            $start = microtime(true);
            $this->assert_rejected($base . '/slow');
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThan(4.5, $elapsed);
            $this->assertLessThan(5.8, $elapsed);
        });
    }

    /**
     * Public metadata never includes the configured storage credentials.
     */
    public function test_public_fetch_sends_no_storage_credentials(): void {
        $this->with_peer('trusted', function (string $base, string $requests): void {
            $user = $this->getDataGenerator()->create_user();
            $this->setUser($user);
            $this->grant_webdav_capability($user);
            $instanceid = $this->create_webdav_instance($user, [
                'webdav_user' => 'synthetic-storage-user',
                'webdav_password' => 'synthetic-storage-password',
            ]);
            $this->write_v2_pointer($user, 'context_area', $instanceid, 'Context');
            $this->assertNotNull(oauth_lib::get_client($base . '/valid'));
            $request = file_get_contents($requests);
            $this->assertStringNotContainsString('authorization:', strtolower($request));
            $this->assertStringNotContainsString('synthetic-storage', $request);
        });
    }

    /**
     * Unknown ids up to the site/source limits are fetched; the first excess starts no network work (#643).
     */
    public function test_unknown_ids_are_budgeted_before_first_fetch(): void {
        $this->with_peer('trusted', function (string $base, string $requests): void {
            $this->cimd_limits(3, 2);
            $this->assertNotNull($this->lookup('192.0.2.1', $base . '/a1'));
            $this->assertNotNull($this->lookup('192.0.2.1', $base . '/a2'), 'Last fetch within the source budget.');
            $retryafter = 0;
            $this->assertNull($this->lookup('192.0.2.1', $base . '/a3', $retryafter), 'First fetch beyond the source budget.');
            $this->assertGreaterThan(0, $retryafter);
            $this->assertNotNull($this->lookup('192.0.2.2', $base . '/b1'), 'Source rejection keeps the site unit.');
            $this->assertSame(3, $this->gets($requests));

            $_SERVER['REMOTE_ADDR'] = '192.0.2.3';
            $token = oauth_lib::handle_token('POST', ['grant_type' => 'authorization_code', 'client_id' => $base . '/c1']);
            $this->assertSame(429, $token['status'], 'First fetch beyond the site budget.');
            $this->assertSame('temporarily_unavailable', $token['body']['error']);
            $this->assertGreaterThan(0, (int) $token['headers']['Retry-After']);
            $this->assertSame('temporarily_unavailable', oauth_lib::validate_authorize_request(
                $this->authorize_params($base . '/c2')
            )['error']);
            $this->assertSame(3, $this->gets($requests), 'Rejected lookups start no network work.');
            $this->assertFalse($this->stored($base . '/c1'));

            // Stored clients keep working without a new fetch while the budget is exhausted.
            $this->assertArrayHasKey('client', oauth_lib::validate_authorize_request($this->authorize_params($base . '/a1')));
            $this->assertSame(400, oauth_lib::handle_token('POST', ['grant_type' => 'authorization_code',
                'client_id' => $base . '/b1'])['status'], 'Known client reaches grant validation, not the budget.');
            $this->assertSame(3, $this->gets($requests));
            $this->assert_no_raw_identifiers();
        });
    }

    /**
     * Failed fetches are cached negatively for a finite time; repeats start no network work (#643).
     */
    public function test_failed_fetches_are_negatively_cached(): void {
        global $DB;
        $this->with_peer('trusted', function (string $base, string $requests) use ($DB): void {
            $this->cimd_limits(2, 10);
            for ($i = 0; $i < 3; $i++) {
                $this->assertNull($this->lookup('192.0.2.1', $base . '/redirect'));
            }
            $this->assertSame(1, $this->gets($requests), 'Repeated failures of one URL are fetched once.');
            $this->assertNull($this->lookup('192.0.2.1', $base . '/invalid'));
            $this->assertNull($this->lookup('192.0.2.1', $base . '/invalid'), 'Invalid documents are cached too.');
            $retryafter = 0;
            $this->assertNull($this->lookup('192.0.2.1', $base . '/valid', $retryafter));
            $this->assertGreaterThan(0, $retryafter, 'Further failures are bounded by the fetch budget.');
            $this->assertSame(2, $this->gets($requests));

            $negatives = $DB->get_records('local_coursepilot_oauth_budget', ['scope' => 'cimdfail']);
            // Two URL entries plus the site counter: never more than the site limit per window.
            $this->assertCount(3, $negatives);
            foreach ($negatives as $row) {
                $this->assertLessThanOrEqual(time() + oauth_lib::CIMD_NEGATIVE_WINDOW, (int) $row->expires);
            }
            $this->assert_no_raw_identifiers();

            // After the negative window the URL may be tried again.
            $DB->set_field('local_coursepilot_oauth_budget', 'expires', time(), ['scope' => 'cimdfail']);
            $this->cimd_limits(10, 10);
            $this->assertNull($this->lookup('192.0.2.1', $base . '/redirect'));
            $this->assertSame(3, $this->gets($requests));
        });
    }

    /**
     * Moodle net blocks and the streaming size cap still apply to budgeted fetches; both failures are cached.
     */
    public function test_transport_failures_use_budget_and_are_negatively_cached(): void {
        global $CFG;
        $this->with_peer('trusted', function (string $base, string $requests) use ($CFG): void {
            $this->cimd_limits(10, 10);
            $CFG->curlsecurityblockedhosts = 'localhost';
            $this->assertNull($this->lookup('192.0.2.1', $base . '/blocked'));
            $this->assertDebuggingCalledCount(1);
            $this->assertNull($this->lookup('192.0.2.1', $base . '/blocked'));
            $CFG->curlsecurityblockedhosts = '';
            $this->assertTrue(oauth_budget::active('cimdfail', $base . '/blocked'));
            $this->assertSame(0, $this->gets($requests));

            $this->assertNull($this->lookup('192.0.2.1', $base . '/chunked'));
            $this->assertNull($this->lookup('192.0.2.1', $base . '/chunked'));
            $this->assertSame(1, $this->gets($requests), 'Oversized response is fetched once.');
            $this->assertFalse($this->stored($base . '/chunked'));
        });
    }

    /**
     * A parallel first lookup that lost the insert race returns the stored client, not a DB error.
     */
    public function test_duplicate_cimd_insert_returns_stored_client(): void {
        $this->resetAfterTest();
        $url = 'https://client.example/cimd.json';
        $metadata = ['client_name' => 'Synthetic', 'redirect_uris' => ['https://client.example/callback']];
        $first = oauth_lib::cache_cimd_client($url, $metadata);
        $this->assertSame((int) $first->id, (int) oauth_lib::cache_cimd_client($url, $metadata)->id);
    }

    /**
     * Overlong and non-https ids are refused before budget and network.
     */
    public function test_invalid_ids_consume_no_budget(): void {
        global $DB;
        $this->with_peer('trusted', function (string $base, string $requests) use ($DB): void {
            $this->cimd_limits(1, 1);
            $long = $base . '/' . str_repeat('a', oauth_lib::CIMD_MAX_URI_LENGTH);
            $this->assertNull($this->lookup('192.0.2.1', $long));
            $this->assertNull($this->lookup('192.0.2.1', str_replace('https:', 'http:', $base) . '/valid'));
            $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_budget'));
            $this->assertNotNull($this->lookup('192.0.2.1', $base . '/valid'));
            $this->assertSame(1, $this->gets($requests));
        });
    }

    /**
     * Two real processes race for the last site fetch unit: exactly one fetch happens (#643).
     */
    public function test_parallel_unknown_ids_cannot_exceed_budget(): void {
        $this->with_peer('trusted', function (string $base, string $requests): void {
            global $CFG;
            $this->cimd_limits(1, 5);
            // Commit the fetch policy so the separate processes see it.
            set_config('curlsecurityblockedhosts', '');
            set_config('curlsecurityallowedport', $CFG->curlsecurityallowedport);

            $this->assertSame([400, 429], $this->race_on_site_budget(
                ['192.0.2.1', $base . '/p1'],
                ['192.0.2.2', $base . '/p2']
            ), 'One known client reaches grant validation, one is refused.');
            $this->assertSame(1, $this->gets($requests));
        });
    }

    /**
     * Look up a client as the given request source.
     *
     * @param string $source
     * @param string $clientid
     * @param int $retryafter
     * @return \stdClass|null
     */
    private function lookup(string $source, string $clientid, int &$retryafter = 0): ?\stdClass {
        $_SERVER['REMOTE_ADDR'] = $source;
        return oauth_lib::get_client($clientid, $retryafter);
    }

    /**
     * Provides cimd limits.
     *
     * @param int $site The site.
     * @param int $source The source.
     */
    private function cimd_limits(int $site, int $source): void {
        set_config('oauthcimdsitelimit', $site, 'local_coursepilot');
        set_config('oauthcimdsourcelimit', $source, 'local_coursepilot');
    }

    /**
     * Provides gets.
     *
     * @param string $requests The requests.
     * @return int
     */
    private function gets(string $requests): int {
        return is_file($requests) ? substr_count(file_get_contents($requests), 'GET ') : 0;
    }

    /**
     * Provides stored.
     *
     * @param string $clientid The clientid.
     * @return bool
     */
    private function stored(string $clientid): bool {
        global $DB;
        return $DB->record_exists('local_coursepilot_oauth_client', ['clientid' => $clientid]);
    }

    /**
     * Provides authorize params.
     *
     * @param string $clientid The clientid.
     * @return mixed[]
     */
    private function authorize_params(string $clientid): array {
        return ['response_type' => 'code', 'client_id' => $clientid, 'redirect_uri' => 'https://client.example/callback',
            'code_challenge' => str_repeat('c', 43), 'code_challenge_method' => 'S256'];
    }

    /**
     * Budget state holds only HMACs: no URL, path or address.
     */
    private function assert_no_raw_identifiers(): void {
        global $DB;
        foreach ($DB->get_records('local_coursepilot_oauth_budget') as $row) {
            $this->assertMatchesRegularExpression('/^(\*|[0-9a-f]{64})$/', $row->sourcekey);
        }
    }

    /**
     * Start a local TLS peer signed by a freshly generated synthetic CA.
     *
     * @param string $identity The identity.
     * @param callable $check The check.
     */
    private function with_peer(string $identity, callable $check): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/filelib.php');
        $directory = make_request_directory();
        $config = $directory . '/openssl.cnf';
        file_put_contents(
            $config,
            "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign\n[leaf]\n"
                . "basicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n"
        );
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
        $cakey = openssl_pkey_new($options);
        $cacsr = openssl_csr_new(['commonName' => 'Synthetic CIMD CA'], $cakey, $options);
        $ca = openssl_csr_sign($cacsr, null, $cakey, 1, $options + ['x509_extensions' => 'ca']);
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => $identity === 'wronghost' ? 'wrong.example' : 'localhost'], $key, $options);
        $cert = openssl_csr_sign(
            $csr,
            $identity === 'selfsigned' ? null : $ca,
            $identity === 'selfsigned' ? $key : $cakey,
            1,
            $options + ['x509_extensions' => 'leaf']
        );
        openssl_x509_export_to_file($cert, $directory . '/peer.pem');
        openssl_pkey_export_to_file($key, $directory . '/peer.key', null, $options);
        $capath = $CFG->dataroot . '/moodleorgca.crt';
        $previousca = is_file($capath) ? file_get_contents($capath) : null;
        openssl_x509_export_to_file($ca, $capath);
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/cimd_https_server.php',
            $directory . '/peer.pem', $directory . '/peer.key', $directory . '/requests'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/server.log', 'a']],
            $pipes
        );
        try {
            stream_set_timeout($pipes[1], 5);
            $address = trim(fgets($pipes[1]));
            $this->assertNotEmpty($address, 'TLS fixture did not start');
            $port = substr(strrchr($address, ':'), 1);
            $CFG->curlsecurityblockedhosts = '';
            $CFG->curlsecurityallowedport = $port;
            $check('https://localhost:' . $port, $directory . '/requests');
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            if ($previousca === null) {
                unlink($capath);
            } else {
                file_put_contents($capath, $previousca);
            }
        }
    }

    /**
     * Rejection must not leave a cached client record behind.
     *
     * @param string $url The url.
     */
    private function assert_rejected(string $url): void {
        global $DB;
        $this->assertNull(oauth_lib::get_client($url));
        $this->assertFalse($DB->record_exists('local_coursepilot_oauth_client', ['clientid' => $url]));
    }
}
