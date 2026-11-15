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

/**
 * Fetch public CIMD documents with Moodle's host policy and CA trust.
 * TLS peer/host checks, no redirects, five seconds and a 1 MiB streaming cap
 * apply without attaching storage credentials.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class cimd_http_fetcher implements cimd_fetch_port {
    /** @var int Maximum decoded response size, enforced while receiving. */
    private const CIMD_MAX_BYTES = 1048576;

    /**
     * Fetch complete public client metadata through Moodle's HTTP client.
     *
     * @param string $url Public HTTPS client identifier.
     * @return mixed[]|null Decoded metadata or null when the response is invalid.
     */
    public function fetch(string $url): ?array {
        $curl = new \curl();
        $body = '';
        $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_WRITEFUNCTION' => static function ($handle, string $chunk) use (&$body): int {
                $length = strlen($chunk);
                if (strlen($body) + $length > self::CIMD_MAX_BYTES) {
                    return 0; // Abort the transfer before retaining an oversized chunk.
                }
                $body .= $chunk;
                return $length;
            },
        ]);
        $info = $curl->get_info();
        if ($curl->get_errno() !== 0 || ($info['http_code'] ?? 0) !== 200) {
            return null;
        }
        $metadata = json_decode($body, true);
        if (!is_array($metadata)) {
            return null;
        }
        return $metadata;
    }
}
