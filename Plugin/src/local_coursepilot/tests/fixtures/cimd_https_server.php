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
 * Synthetic TLS peer for oauth_cimd_fetch_test; never bootstraps Moodle.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Standalone child process, runs before or without a Moodle bootstrap.

if (PHP_SAPI !== 'cli' || count($argv) !== 4) {
    exit(1);
}
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2]]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
echo stream_socket_get_name($server, false) . "\n";
flush();
while ($socket = stream_socket_accept($server, 15)) {
    stream_set_timeout($socket, 6);
    if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($socket);
        continue;
    }
    $request = fgets($socket);
    $headers = '';
    while (($line = fgets($socket)) !== false && trim($line) !== '') {
        $headers .= $line;
    }
    file_put_contents($argv[3], $request . $headers, FILE_APPEND);
    $path = explode(' ', $request)[1];
    $body = json_encode(['client_name' => 'Synthetic TLS client', 'redirect_uris' => ['https://client.example/callback']]);
    if ($path === '/invalid') {
        $body = json_encode(['client_name' => 'Synthetic client without redirect URIs']);
    }
    if ($path === '/redirect') {
        fwrite($socket, "HTTP/1.1 302 Found\r\nLocation: /valid\r\nContent-Length: 0\r\n\r\n");
    } else if ($path === '/slow') {
        sleep(6);
    } else if ($path === '/chunked' || $path === '/unframed') {
        $chunked = $path === '/chunked';
        fwrite($socket, "HTTP/1.1 200 OK\r\n" . ($chunked ? "Transfer-Encoding: chunked\r\n" : '') . "Connection: close\r\n\r\n");
        // Valid JSON once complete, exceeding the limit only in trailing whitespace.
        $chunks = [$body, str_repeat(' ', 8192)];
        for ($i = 0; $i < 160; $i++) {
            $chunk = $chunks[min($i, 1)];
            if (@fwrite($socket, $chunked ? dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n" : $chunk) === false) {
                break;
            }
        }
        // Keep the response unfinished; the receiver must abort on size, not timeout.
        sleep(6);
    } else {
        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }
    fclose($socket);
}
