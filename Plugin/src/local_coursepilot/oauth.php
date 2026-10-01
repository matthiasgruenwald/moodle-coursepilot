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
 * Autorisierungsserver-Metadaten unter dem PATH_INFO-Pfad (#302, Punkt 1+2).
 *
 * Issuer ist diese Datei selbst:
 *   https://<wwwroot>/local/coursepilot/oauth.php
 * Die OIDC-Pfadanhaengung landet damit als PATH_INFO hier:
 *   .../oauth.php/.well-known/openid-configuration
 *   .../oauth.php/.well-known/oauth-authorization-server
 *
 * Reine Schale (#334-Muster): liest PATH_INFO ein, uebergibt an
 * {@see \local_coursepilot\oauth_lib::handle_discovery()}. registration_endpoint
 * ist seit #335 ein echter Endpunkt (oauth/register.php), authorize_endpoint
 * und token_endpoint seit #336 (oauth/authorize.php, oauth/token.php).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_coursepilot\oauth_lib;

$pathinfo = trim($_SERVER['PATH_INFO'] ?? '', '/');
$response = oauth_lib::handle_discovery($CFG->wwwroot, $pathinfo);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
header('Content-Type: application/json');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
