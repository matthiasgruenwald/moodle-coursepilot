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
 * RFC-8414-Standardpfad fuer die Autorisierungsserver-Metadaten (#337-Fix).
 *
 * RFC 8414 §3.1 verlangt fuer einen Issuer mit Pfadkomponente
 * (local/coursepilot/oauth.php), dass ".well-known/oauth-authorization-server"
 * DIREKT hinter dem Host steht und der Issuer-Pfad DAHINTER angehaengt wird -
 * umgekehrt zur PATH_INFO-Loesung in local/coursepilot/oauth.php selbst
 * (".well-known" hinter der Datei). Claude.ai (Custom Connector) probiert
 * ausschliesslich den RFC-Standardpfad und findet ohne diese Datei nie den
 * Login-Screen. Deshalb dieser echte, pfadgleiche Dateibaum ausserhalb des
 * Plugin-Verzeichnisses im Moodle-Wurzelverzeichnis - kein Rewrite noetig,
 * die URL entspricht 1:1 dem Dateipfad. Ruft dieselbe Quelle wie oauth.php
 * auf ({@see \local_coursepilot\oauth_lib::authorization_server_metadata()}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../../config.php');

use local_coursepilot\oauth_lib;

header('Cache-Control: no-store');
header('Content-Type: application/json');
echo json_encode(oauth_lib::authorization_server_metadata($CFG->wwwroot), JSON_UNESCAPED_SLASHES);
