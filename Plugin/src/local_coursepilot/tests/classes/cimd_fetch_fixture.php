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
 * In-memory public CIMD source; records requests without using the network.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class cimd_fetch_fixture implements \local_coursepilot\cimd_fetch_port {
    /** @var mixed[]|null Metadata returned for the next request. */
    public ?array $metadata = ['client_name' => 'Synthetic CIMD client',
        'redirect_uris' => ['https://client.example/callback']];

    /** @var string[] Requested public identifiers. */
    public array $requests = [];

    /**
     * Return the configured document and retain the requested identifier.
     *
     * @param string $url Public client identifier.
     * @return mixed[]|null
     */
    public function fetch(string $url): ?array {
        $this->requests[] = $url;
        return $this->metadata;
    }
}
