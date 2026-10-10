<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Key endpoint (#336). Thin shell around oauth_lib::jwks_document(): valid
 * but empty. jwks_uri is declared because the OIDC namespace requires it
 * (#302, item 2). Coursepilot is not an OIDC provider and issues no signed
 * ID tokens; access tokens are opaque database values.
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
echo json_encode(oauth_lib::jwks_document());
