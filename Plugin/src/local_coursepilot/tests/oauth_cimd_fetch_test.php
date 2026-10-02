<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

/** Real TLS/streaming regressions at the public client lookup boundary. */
#[CoversClass(oauth_lib::class)]
final class oauth_cimd_fetch_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    /** A CA-signed local peer works; untrusted and mismatched peers cannot register clients. */
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

    /** The receive limit applies without Content-Length, including decoded chunked bodies. */
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

    /** Moodle host/port policy, redirects and the existing five-second deadline remain enforced. */
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

    /** Public metadata never includes the configured storage credentials. */
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

    /** Start a local TLS peer signed by a freshly generated synthetic CA. */
    private function with_peer(string $identity, callable $check): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/filelib.php');
        $directory = make_request_directory();
        $config = $directory . '/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign\n[leaf]\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048];
        $cakey = openssl_pkey_new($options);
        $cacsr = openssl_csr_new(['commonName' => 'Synthetic CIMD CA'], $cakey, $options);
        $ca = openssl_csr_sign($cacsr, null, $cakey, 1, $options + ['x509_extensions' => 'ca']);
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => $identity === 'wronghost' ? 'wrong.example' : 'localhost'], $key, $options);
        $cert = openssl_csr_sign($csr, $identity === 'selfsigned' ? null : $ca,
            $identity === 'selfsigned' ? $key : $cakey, 1, $options + ['x509_extensions' => 'leaf']);
        openssl_x509_export_to_file($cert, $directory . '/peer.pem');
        openssl_pkey_export_to_file($key, $directory . '/peer.key', null, $options);
        $capath = $CFG->dataroot . '/moodleorgca.crt';
        $previousca = is_file($capath) ? file_get_contents($capath) : null;
        openssl_x509_export_to_file($ca, $capath);
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/cimd_https_server.php',
            $directory . '/peer.pem', $directory . '/peer.key', $directory . '/requests'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $directory . '/server.log', 'a']], $pipes);
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

    /** Rejection must not leave a cached client record behind. */
    private function assert_rejected(string $url): void {
        global $DB;
        $this->assertNull(oauth_lib::get_client($url));
        $this->assertFalse($DB->record_exists('local_coursepilot_oauth_client', ['clientid' => $url]));
    }
}
