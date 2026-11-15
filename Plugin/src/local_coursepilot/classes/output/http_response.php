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

/**
 * Carries the exact HTTP status, headers and bytes selected by an endpoint.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class http_response {
    /**
     * Creates an HTTP delivery with no output side effects.
     *
     * @param int $status HTTP status code.
     * @param string[] $headers Response header values keyed by name.
     * @param string $body Bytes to deliver, including binary downloads.
     */
    public function __construct(
        /** @var int HTTP status code. */
        public readonly int $status,
        /** @var string[] Response headers. */
        public readonly array $headers,
        /** @var string Response bytes. */
        public readonly string $body
    ) {
    }

    /**
     * Encodes a domain endpoint response using endpoint-specific JSON options.
     *
     * @param mixed[] $response Domain response with status, headers and optional JSON body.
     * @param int $flags Endpoint-specific JSON encoding options.
     * @return self Encoded HTTP response.
     */
    public static function json(array $response, int $flags = JSON_UNESCAPED_SLASHES): self {
        $headers = $response['headers'];
        $body = '';
        if ($response['body'] !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = (string) json_encode($response['body'], $flags);
        }
        return new self($response['status'], $headers, $body);
    }

    /**
     * Sends the status, headers and original bytes to the HTTP client.
     */
    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
