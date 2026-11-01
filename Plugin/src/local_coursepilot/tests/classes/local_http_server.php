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
namespace local_coursepilot\tests;

/**
 * A real HTTP server on the loopback interface (PHP's built-in server) for tests of real HTTP behaviour.
 *
 * It lets a test observe what a client really puts on the wire (status line, headers, redirects, delays)
 * without replacing the code under test by a double.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class local_http_server {
    /** @var resource|null Server process. */
    private $process = null;

    /** @var string Directory with the router script. */
    private string $directory;

    /** @var int Port. */
    private int $port;

    /**
     * Starts the server with a router script.
     *
     * @param string $routerphp PHP source of the router (including the opening tag).
     */
    public function __construct(string $routerphp) {
        $this->directory = sys_get_temp_dir() . '/coursepilot-http-' . uniqid();
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/router.php', $routerphp);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, '-t', $this->directory, $this->directory . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.2);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new \RuntimeException('The local HTTP test server did not start.');
    }

    /**
     * Base URL without trailing slash.
     *
     * @return string
     */
    public function base_url(): string {
        return 'http://127.0.0.1:' . $this->port;
    }

    /**
     * Fetches a path and returns the status, header lines and body, including for error statuses.
     *
     * @param string $path Path with query.
     * @return array{status: int, headers: string[], body: string}
     */
    public function fetch(string $path): array {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 30]]);
        $body = file_get_contents($this->base_url() . $path, false, $context);
        $headers = (array) $http_response_header;
        preg_match('~^HTTP/\S+ (\d+)~', $headers[0] ?? '', $match);
        return ['status' => (int) ($match[1] ?? 0), 'headers' => $headers, 'body' => (string) $body];
    }

    /**
     * Stops the server and removes its files.
     */
    public function stop(): void {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        if (is_dir($this->directory)) {
            @unlink($this->directory . '/router.php');
            @rmdir($this->directory);
        }
    }

    /**
     * Stops the server with the object.
     */
    public function __destruct() {
        $this->stop();
    }
}
