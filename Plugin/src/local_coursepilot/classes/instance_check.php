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

namespace local_coursepilot;

/**
 * Instance check via self-request (#340): checks whether the discovery URL of
 * the own instance is actually reachable - not by looking at the configuration,
 * because reverse proxies, upstream services and disabled PATH_INFO
 * only show up on the real request (docs/specs/0012, section 7).
 *
 * Same pattern as {@see privacy_surface}: pure judgement function
 * ({@see evaluate()}) plus thin network shell ({@see self_check()}),
 * so the judgement part stays testable via PHPUnit without a real HTTP request.
 * Only caller of the shell is surface.php.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class instance_check {
    /**
     * The three instance prerequisites for remote access operation
     * (docs/specs/0012, section 7). Display data for the label;
     * only what the self-request can actually see is checked: HTTPS
     * via schema check in {@see evaluate()}, PATH_INFO via status code/body
     * of the own discovery response. We do not check egress to an external provider
     * separately - the self-request goes to the own
     * instance, not to the AI provider.
     *
     * @var string[]
     */
    public const REQUIREMENTS = [
        'https',
        'egress',
        'pathinfo',
    ];

    /**
     * Emergency-exit rule: documented deviation from the target path "zero intervention
     * on the web server" (docs/specs/0012, section 7), in case PATH_INFO does not
     * arrive (e.g. Apache `AcceptPathInfo Off`).
     *
     * @var string
     */
    public const EMERGENCY_EXIT_RULE = 'AcceptPathInfo On';

    /**
     * Discovery URL of the own instance. The issuer is oauth.php itself, the
     * OIDC path suffix lands on it as PATH_INFO (#302).
     *
     * @param string $wwwroot
     * @return string
     */
    public static function discovery_url(string $wwwroot): string {
        return rtrim($wwwroot, '/') . '/local/coursepilot/oauth.php/.well-known/openid-configuration';
    }

    /**
     * Pure judgement of an already available HTTP response - no
     * Moodle or network access, therefore testable without a real HTTP request.
     *
     * Also checks the HTTPS scheme of the fetched URL, because a
     * discovery document reachable under http:// does satisfy the
     * status code part, but not the prerequisite "public
     * HTTPS".
     *
     * @param int|null $httpcode null if the request itself failed (timeout, DNS, ...).
     * @param string $body
     * @param string $url The URL actually fetched (schema check).
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
     * Real self-request of the discovery URL plus judgement. Thin shell around
     * {@see evaluate()} - errors in the request itself (timeout, DNS, TLS,
     * invalid URL) must not crash the display page,
     * so a try/catch also catches what \curl itself does not report as a
     * regular error code.
     *
     * @param string $wwwroot
     * @return array{ok: bool, detail: string, url: string, httpcode: ?int}
     */
    public static function self_check(string $wwwroot): array {
        global $CFG;

        $url = self::discovery_url($wwwroot);

        try {
            // \curl (lib/filelib.php) is not generally autoloaded - unlike
            // in the full web request bootstrap, it is not always pulled in under
            // CLI_SCRIPT. Load explicitly instead of relying on
            // a chance hit through other includes.
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
