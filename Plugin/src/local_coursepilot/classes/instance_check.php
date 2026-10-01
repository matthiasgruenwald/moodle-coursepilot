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
 * Instanzpruefung per Selbstabruf (#340): prueft, ob die Discovery-URL der
 * eigenen Instanz tatsaechlich erreichbar ist - nicht per Konfigurationsblick,
 * denn Reverse-Proxies, vorgelagerte Dienste und abgeschaltetes PATH_INFO
 * fallen erst beim echten Abruf auf (docs/specs/0012, Abschnitt 7).
 *
 * Gleiches Muster wie {@see privacy_surface}: reine Urteilsfunktion
 * ({@see evaluate()}) plus duenne Netzwerk-Schale ({@see self_check()}),
 * damit der Urteilsteil ohne echten HTTP-Request per PHPUnit pruefbar bleibt.
 * Einziger Aufrufer der Schale ist surface.php.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class instance_check {

    /**
     * Die drei Instanzvoraussetzungen fuer den Fernzugriffsbetrieb
     * (docs/specs/0012, Abschnitt 7). Anzeigedaten fuer die Beschriftung;
     * geprueft wird nur, was der Selbstabruf tatsaechlich sehen kann: HTTPS
     * per Schema-Check in {@see evaluate()}, PATH_INFO per Statuscode/Body
     * der eigenen Discovery-Antwort. Egress zu einem externen Anbieter
     * pruefen wir nicht gesondert - der Selbstabruf geht an die eigene
     * Instanz, nicht an den KI-Anbieter.
     *
     * @var string[]
     */
    public const REQUIREMENTS = [
        'https',
        'egress',
        'pathinfo',
    ];

    /**
     * Notausgangs-Regel: dokumentierte Abweichung vom Zielweg "Null-Eingriff
     * am Webserver" (docs/specs/0012, Abschnitt 7), falls PATH_INFO nicht
     * ankommt (z. B. Apache `AcceptPathInfo Off`).
     *
     * @var string
     */
    public const EMERGENCY_EXIT_RULE = 'AcceptPathInfo On';

    /**
     * Discovery-URL der eigenen Instanz. Issuer ist oauth.php selbst, die
     * OIDC-Pfadanhaengung landet als PATH_INFO darauf (#302).
     *
     * @param string $wwwroot
     * @return string
     */
    public static function discovery_url(string $wwwroot): string {
        return rtrim($wwwroot, '/') . '/local/coursepilot/oauth.php/.well-known/openid-configuration';
    }

    /**
     * Reines Urteil ueber eine bereits vorliegende HTTP-Antwort - kein
     * Moodle-, kein Netzwerkzugriff, deshalb ohne echten HTTP-Request testbar.
     *
     * Prueft nebenbei das HTTPS-Schema der abgerufenen URL mit, denn ein
     * unter http:// erreichbares Discovery-Dokument beantwortet zwar den
     * Statuscode-Teil, erfuellt aber nicht die Voraussetzung "oeffentliches
     * HTTPS".
     *
     * @param int|null $httpcode null, wenn der Abruf selbst scheiterte (Timeout, DNS, ...).
     * @param string $body
     * @param string $url Die tatsaechlich abgerufene URL (Schema-Pruefung).
     * @return array{ok: bool, detail: string}
     */
    public static function evaluate(?int $httpcode, string $body, string $url = 'https://'): array {
        if (!str_starts_with($url, 'https://')) {
            return ['ok' => false, 'detail' => 'selfcheckrequireshttps'];
        }
        if ($httpcode === null) {
            return ['ok' => false, 'detail' => 'selfcheckrequestfailed'];
        }
        if ($httpcode !== 200) {
            return ['ok' => false, 'detail' => 'selfcheckunexpectedstatus'];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['issuer'])) {
            return ['ok' => false, 'detail' => 'selfcheckinvalidbody'];
        }
        return ['ok' => true, 'detail' => 'selfcheckok'];
    }

    /**
     * Echter Selbstabruf der Discovery-URL plus Urteil. Duenne Schale um
     * {@see evaluate()} - Fehler beim Abruf selbst (Timeout, DNS, TLS,
     * ungueltige URL) duerfen die Anzeigeseite nicht zum Absturz bringen,
     * deshalb faengt ein try/catch auch das ab, was \curl selbst nicht als
     * regulaeren Fehlercode meldet.
     *
     * @param string $wwwroot
     * @return array{ok: bool, detail: string, url: string, httpcode: ?int}
     */
    public static function self_check(string $wwwroot): array {
        global $CFG;

        $url = self::discovery_url($wwwroot);

        try {
            // \curl (lib/filelib.php) ist nicht generell autogeladen - anders
            // als im vollen Web-Request-Bootstrap wird sie z. B. unter
            // CLI_SCRIPT nicht immer mitgezogen. Explizit laden statt sich
            // auf einen Zufallstreffer durch andere Includes zu verlassen.
            require_once($CFG->libdir . '/filelib.php');

            $curl = new \curl();
            $body = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 5, 'CURLOPT_FOLLOWLOCATION' => true]);
            $info = $curl->get_info();
            $httpcode = $curl->get_errno() ? null : (int) ($info['http_code'] ?? 0);
            if ($httpcode === 0) {
                $httpcode = null;
            }
        } catch (\Throwable $e) {
            $httpcode = null;
            $body = '';
        }

        $judgement = self::evaluate($httpcode, (string) $body, $url);

        return $judgement + ['url' => $url, 'httpcode' => $httpcode];
    }
}
