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
 * Single-use workbench download endpoint (#501, Spec #486 §13). The URL
 * ticket is the sole authorization, without Moodle login. A dedicated route
 * avoids webservice/pluginfile.php (ignores OAuth Bearer) and
 * tokenpluginfile.php (overly broad access).
 *
 * Thin wrapper like oauth/token.php (#334): pass the ticket to
 * workbench_ticket::redeem(), whose validation and consumption logic is
 * unit-testable without a webserver. Ignore Range headers and always
 * deliver the complete file.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

\local_coursepilot\output\http_endpoint::download(optional_param('ticket', '', PARAM_ALPHANUM))->send();
