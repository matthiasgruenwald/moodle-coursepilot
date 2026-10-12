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
 * MCP endpoint: POST-only, JSON, stateless, supporting legacy 2025-*
 * initialize handshake and modern 2026-07-28 server/discover.
 *
 * I/O shell (#334): reads body, Bearer token and headers, then delegates
 * to {@see \local_coursepilot\dispatcher::handle()}. Authentication
 * and the ADR 0011 privacy contract are tested there with PHPUnit.
 * This pure I/O file deliberately has no separate tests.
 *
 * Established by research #290 and prototype #294.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

// WS_SERVER is required, not NO_MOODLE_COOKIES: otherwise
// external_api::call_external_function() fails with servicerequireslogin
// (external_api.php:216; prototype #294 finding).
define('WS_SERVER', true);
define('NO_DEBUG_DISPLAY', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Bearer-token endpoint; dispatcher authenticates every request itself.
require(__DIR__ . '/../../config.php');


raise_memory_limit(MEMORY_EXTRA);
\core_external\external_api::set_timeout();

\local_coursepilot\output\http_endpoint::mcp(
    (string) file_get_contents('php://input'),
    $_SERVER,
    \local_coursepilot\output\http_endpoint::request_headers()
)->send();
