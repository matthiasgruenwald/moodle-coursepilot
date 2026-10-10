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
 * Protected-resource metadata under RFC 9728 (#302, item 2).
 *
 * Linked by mcp.php's WWW-Authenticate header (first of two addresses,
 * #335). The second is mcp.php PATH_INFO at .well-known/oauth-protected-resource,
 * for clients deriving it from the resource URL without reading the header.
 * Both share {@see \local_coursepilot\oauth_lib::protected_resource_metadata()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_coursepilot\oauth_lib;

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(oauth_lib::protected_resource_metadata($CFG->wwwroot), JSON_UNESCAPED_SLASHES);
