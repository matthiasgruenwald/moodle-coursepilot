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

use core_external\external_api;

/**
 * Dispatcher seam (#334): decisions formerly embedded in mcp.php
 * (origin, discovery, HTTP method, parse error, authentication, protocol)
 * now accept values and return status, headers and body. No exit or HTTP
 * superglobals ($_SERVER, php://input), so PHPUnit needs no web server.
 *
 * Moodle bindings ($DB/$CFG and external_api::call_external_function())
 * stay here: advanced_testcase already provides DB and $USER, so callbacks
 * would add unused flexibility (YAGNI). These are framework globals, not
 * HTTP globals. Header extraction (Authorization, REDIRECT_HTTP_AUTHORIZATION
 * fallback and getallheaders()) remains I/O in mcp.php.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class dispatcher {
    /** @var string Legacy protocol revision (initialize handshake). */
    public const LEGACY_VERSION = '2025-06-18';

    /** @var string Modern protocol revision (server/discover). */
    public const MODERN_VERSION = '2026-07-28';

    /**
     * Entry guidance (#451, Spec 0020 §2): without a local skill file, newly
     * connected clients need the server to provide their starting point.
     * initialize and server/discover expose the same instructions; the
     * coursepilot_list_skills description repeats them for clients that hide
     * instructions. Only routing belongs here: planning discipline, privacy
     * and tool knowledge are supplied through get_skill.
     */
    public const HANDSHAKE_INSTRUCTIONS = 'Before planning or writing, call coursepilot_list_skills first.';

    /** @var string[] Allowed origins in addition to $CFG->wwwroot. */
    private const EXTRA_ALLOWED_ORIGINS = ['https://claude.ai', 'https://chatgpt.com'];

    /**
     * List freshness in milliseconds (five minutes). All four lists share
     * this TTL: the tool list changes only on upgrades; the other lists are empty.
     */
    private const LIST_TTL_MS = 300000;

    /**
     * Handles a complete MCP request, returning a value instead of emitting output.
     *
     * @param array|null $request The already decoded JSON body, or
     *        null if decoding failed (parse-error case).
     * @param string|null $token The Bearer token already extracted from
     *        the Authorization header.
     * @param array $headers
     * @phpstan-param array{origin:?string,pathinfo:?string,method:?string,protocolversion?:?string} $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    public static function handle(?array $request, ?string $token, array $headers): array {
        global $CFG;

        // Check origins only when a header is present (#294: allowlist maintenance).
        $origin = $headers['origin'] ?? null;
        if ($origin !== null) {
            $allowed = array_merge([rtrim($CFG->wwwroot, '/')], self::EXTRA_ALLOWED_ORIGINS);
            if (!in_array(rtrim($origin, '/'), $allowed, true)) {
                // #339: log separately, before handle_authorized(); this response
                // does not use the JSON-RPC error format.
                access_log::log_failure('Origin not allowed');
                return self::result(403, [], ['error' => 'Origin not allowed']);
            }
        }
        $corsheaders = $origin !== null ? ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'] : [];

        // CORS preflight (#337 addendum): Claude.ai uses browser fetch() with
        // Authorization. Without an OPTIONS response, the browser blocks the
        // POST before it reaches this server. curl and server logs cannot show
        // that client-side connection failure.
        if (($headers['method'] ?? 'POST') === 'OPTIONS') {
            return self::result(204, $corsheaders + [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Max-Age' => '86400',
            ], null);
        }

        $result = self::handle_authorized($request, $token, $headers);
        $result['headers'] = $corsheaders + $result['headers'];
        return $result;
    }

    /**
     * Request flow after origin validation and CORS preflight (#337 addendum).
     * Extracted from handle() so one place adds CORS headers to every response.
     *
     * @param array|null $request
     * @param string|null $token
     * @param array $headers
     * @phpstan-param array{origin:?string,pathinfo:?string,method:?string,protocolversion?:?string} $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function handle_authorized(?array $request, ?string $token, array $headers): array {
        global $CFG;

        // Global emergency switch (#338): deny all MCP access immediately, before
        // token or capability checks. Issued tokens survive, unlike bulk revocation
        // in oauth_lib::revoke_all_tokens(). This endpoint setting does not affect
        // normal Moodle login. Enabled by default, hence comparison to explicit 0.
        if ((string) get_config('local_coursepilot', 'remoteaccessenabled') === '0') {
            return self::error(403, $request['id'] ?? null, -32003, get_string('remoteaccessdisabled', 'local_coursepilot'));
        }

        // Protected-resource metadata on the resource path itself (RFC 9728 §3, #312).
        $pathinfo = trim($headers['pathinfo'] ?? '', '/');
        if ($pathinfo === '.well-known/oauth-protected-resource') {
            return self::result(
                200,
                ['Cache-Control' => 'no-store'],
                oauth_lib::protected_resource_metadata($CFG->wwwroot)
            );
        }

        $method = $headers['method'] ?? 'POST';
        if ($method !== 'POST') {
            // #339: deliberately unlogged; this is a misconfigured HTTP client,
            // without a parsed JSON-RPC request or tool reference.
            return self::result(405, ['Allow' => 'POST'], ['error' => 'Method Not Allowed - MCP over HTTP is POST only']);
        }

        if ($request === null) {
            return self::error(400, null, -32700, 'Parse error');
        }

        $id = $request['id'] ?? null;
        $rpcmethod = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        // Authenticate before handshake: 401 with resource_metadata prompts
        // clients to start the discovery chain (RFC 9728, #302).
        if (!self::authenticate($token)) {
            return self::error(401, $id, -32001, 'AUTHENTICATION_FAILED', [
                'WWW-Authenticate' => 'Bearer resource_metadata="'
                    . $CFG->wwwroot . '/local/coursepilot/oauth/protected-resource.php"',
            ]);
        }

        // Remote access (#296, #337, #579) is separate from local/coursepilot:use,
        // so admins can revoke it per person without changing courses. Checked
        // on every call, including existing connections. The specific error names
        // both grant methods (cohort or capability), unlike the generic auth error.
        if (!remote_access::is_granted()) {
            return self::error(403, $id, -32002, get_string('remoteaccessnotgranted', 'local_coursepilot'));
        }

        $serverinfo = ['name' => 'local_coursepilot', 'version' => self::plugin_release()];

        switch ($rpcmethod) {
            // Legacy protocol: handshake.
            case 'initialize':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => $params['protocolVersion'] ?? self::LEGACY_VERSION,
                        'capabilities' => ['tools' => new \stdClass()],
                        'serverInfo' => $serverinfo,
                        'instructions' => self::HANDSHAKE_INSTRUCTIONS,
                    ] + self::resultmeta($headers, 'complete'),
                ]);

            case 'notifications/initialized':
            case 'notifications/cancelled':
                return self::result(202, [], null);

            case 'ping':
                // The legacy ping result is an empty object. json_encode() would encode
                // an empty PHP array as [] instead of the required {}.
                $pingresult = self::resultmeta($headers, 'complete');
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $pingresult === [] ? new \stdClass() : $pingresult,
                ]);

            // Modern protocol: discovery replaces handshake.
            case 'server/discover':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'supportedVersions' => [self::MODERN_VERSION, self::LEGACY_VERSION],
                        'capabilities' => ['tools' => new \stdClass()],
                        'serverInfo' => $serverinfo,
                        'instructions' => self::HANDSHAKE_INSTRUCTIONS,
                    ] + self::resultmeta($headers, 'complete'),
                ]);

            case 'tools/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => self::tools(),
                        // data is invalid for tools/list ("Unsupported result type data for
                        // tools/list"). The full list is not paginated, so use complete.
                    ] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            case 'tools/call':
                return self::handle_tools_call($id, $params, $headers);

            // #401: empty responses instead of 404. We expose no resources or
            // prompts and advertise neither capability, but Codex requests these
            // three discovery methods after every handshake.
            case 'resources/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['resources' => []] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            case 'resources/templates/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['resourceTemplates' => []] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            case 'prompts/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['prompts' => []] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            default:
                return self::error(404, $id, -32601, 'Method not found: ' . $rpcmethod);
        }
    }

    /**
     * tools/call: validate the runtime contract and invoke Moodle
     * webservices (#295, item 1).
     *
     * @param mixed $id
     * @param array $params
     * @param array $headers
     * @phpstan-param array{origin:?string,pathinfo:?string,method:?string,protocolversion?:?string} $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function handle_tools_call($id, array $params, array $headers): array {
        $toolname = (string) ($params['name'] ?? '');
        $function = privacy_surface::function_for_tool($toolname);
        if ($function === null) {
            return self::error(404, $id, -32601, 'Unknown tool: ' . $toolname);
        }

        // #573 (Spec 0025 §A): every tool declares English inputs directly.
        // The #568 input translation is removed; Moodle parameter declarations
        // are the single contract.
        $response = external_api::call_external_function($function, $params['arguments'] ?? []);
        if ($response['error']) {
            $message = self::error_message($response['exception'] ?? null);
            access_log::log_failure(
                $message,
                $toolname,
                null,
                null,
                self::diagnostic_detail($response['exception'] ?? null)
            );
            return self::result(200, [], [
                'jsonrpc' => '2.0',
                'id' => $id,
                // Error results also require metadata (#466). A 2026-07-28 client
                // rejects replies without resultType and shows a protocol error instead
                // of the tool message. isError results are fully delivered, so complete.
                'result' => [
                    'isError' => true,
                    'content' => [['type' => 'text', 'text' => $message]],
                ] + self::resultmeta($headers, 'complete'),
            ]);
        }

        $data = $response['data'];
        // Trace material operations (Spec 0018 §9.2): all context and material
        // tools return their relative path under the same path key.
        $path = is_string($data['path'] ?? null) ? $data['path'] : null;
        access_log::log_success($toolname, tool_registry::is_write($toolname), $path);

        // Second content type (Spec 0018 §3.2, #430): preview_material_file
        // returns image_base64 and mimetype, converted here to an MCP image
        // block so the model can see the image instead of only its encoded text.
        // Other tools retain text plus structuredContent. Remove image bytes
        // from those copies to avoid sending them through context twice.
        $content = [];
        $textdata = $data;
        $imagebase64 = $data['image_base64'] ?? null;
        $mimetype = $data['mimetype'] ?? null;
        if (is_string($imagebase64) && $imagebase64 !== '' && is_string($mimetype) && $mimetype !== '') {
            unset($textdata['image_base64']);
            $content[] = ['type' => 'image', 'data' => $imagebase64, 'mimeType' => $mimetype];
        }
        array_unshift($content, [
            'type' => 'text',
            // JSON_INVALID_UTF8_SUBSTITUTE: as in mcp.php, invalid UTF-8 would
            // otherwise make this inner encoding silently return false.
            'text' => json_encode($textdata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        return self::result(200, [], [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => $content,
                'structuredContent' => $textdata,
            ] + self::resultmeta($headers, 'complete'),
        ]);
    }

    /**
     * Error text for a failed tool call.
     *
     * invalid_parameter_exception has a generic Moodle message; the useful
     * teacher-facing detail is in debuginfo. Without it, oversized XML and
     * invalid Moodle XML become the same unhelpful error and cannot be fixed.
     *
     * Only this error code exposes debuginfo: it contains caller-written
     * text or validate_parameters() descriptions. Other exceptions,
     * especially dml_* with SQL in debuginfo, retain their normal message.
     *
     * @param \stdClass|null $exception Exception information from
     *        external_api::call_external_function() (get_exception_info()).
     * @return string
     */
    private static function error_message(?\stdClass $exception): string {
        if ($exception === null) {
            return 'error';
        }
        // Developer debugging appends "Error code: ..." to debuginfo. Strip
        // this configuration-dependent noise from the teacher-facing message.
        $debuginfo = trim(preg_replace('/\n+Error code: \S+\s*$/', '', (string) ($exception->debuginfo ?? '')));
        if (($exception->errorcode ?? '') === 'invalidparameter' && $debuginfo !== '') {
            return $debuginfo;
        }
        return $exception->message ?? 'error';
    }

    /**
     * Return-contract error detail for the full diagnostic logging level.
     *
     * It may include internal field paths or course content, so never send
     * it in the MCP response. Only explicit diagnostic mode records it (#457).
     *
     * @param \stdClass|null $exception Exception information from
     *        external_api::call_external_function() (get_exception_info()).
     * @return string|null
     */
    private static function diagnostic_detail(?\stdClass $exception): ?string {
        if ($exception === null || ($exception->errorcode ?? '') !== 'invalidresponse') {
            return null;
        }
        $detail = trim(preg_replace('/\n+Error code: \S+\s*$/', '', (string) ($exception->debuginfo ?? '')));
        return $detail === '' ? null : $detail;
    }

    /**
     * Result metadata for revision 2026-07-28 only.
     *
     * The revisions have opposing requirements (#400): modern clients
     * (Claude Code, Claude.ai) reject missing resultType or ttlMs; cacheScope
     * accepts public/private, not session (#337 addendum). Legacy clients
     * (Codex 0.151+, rmcp) reject tools/call resultType as "Unexpected response
     * type". Choose via the negotiated MCP-Protocol-Version request header.
     * Without it, use legacy fields, which neither client actively rejects.
     *
     * cacheScope is private because course data belongs to the calling teacher.
     *
     * #458 (acceptance of #456): data was never a valid resultType; success
     * is complete, while input_required belongs to the unsupported MRTR
     * pattern. Cache metadata belongs to list/resource reads, not tools/call:
     * a TTL on a write suggests caching it, so tools/call leaves $ttlms null.
     *
     * #466: EVERY result requires resultType in the modern revision. A
     * forgotten tools/call error branch made all tool messages unreadable.
     * initialize/server/discover precede negotiation; absent headers select legacy.
     *
     * @param array $headers
     * @phpstan-param array{protocolversion?:?string} $headers
     * @param string $resulttype 'complete', the revision's only success value
     *        for every result.
     * @param int|null $ttlms Freshness in milliseconds for list results only.
     *        Null omits all caching fields.
     * @return array<string, mixed> Empty outside the modern revision.
     */
    private static function resultmeta(array $headers, string $resulttype, ?int $ttlms = null): array {
        if (($headers['protocolversion'] ?? null) !== self::MODERN_VERSION) {
            return [];
        }
        $meta = ['resultType' => $resulttype];
        if ($ttlms !== null) {
            $meta['ttlMs'] = $ttlms;
            $meta['cacheScope'] = 'private';
        }
        return $meta;
    }

    /**
     * Maps a Bearer token to a Moodle user and sets $USER (#337).
     *
     * Only OAuth access tokens from {@see oauth_lib::authenticate_access_token()}
     * are accepted. The prototype external_tokens workaround is removed;
     * the OAuth 2.1 authorization server (#335/#336) is its sole replacement.
     *
     * @param string|null $token
     * @return bool
     */
    private static function authenticate(?string $token): bool {
        global $DB, $USER;

        if ($token === null) {
            return false;
        }
        $userid = oauth_lib::authenticate_access_token($token);
        if ($userid === null) {
            return false;
        }
        $usr = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$usr) {
            return false;
        }

        \core\session\manager::set_user($usr);
        // Bearer authentication is stateless: each POST validates its token
        // without trusting cookies/sessions (#337, Spec 0012 §3). sesskey guards
        // ambient browser-cookie authority, which is absent here: attackers cannot
        // forge the Authorization header through a cross-site request.
        // external_api::call_external_function() skips sesskey only with WS_SERVER=true
        // (set by mcp.php before bootstrap); PHPUnit has already fixed the
        // constant to false, requiring this override. See dispatcher_test.php.
        $USER->ignoresesskey = true;
        external_api::set_context_restriction(\context_system::instance());
        return true;
    }

    /**
     * Reads $plugin->release from the same canonical source as
     * get_version_info::execute() (#577, plugin_meta::current()). Handshake
     * and version info must not maintain independent release numbers.
     *
     * @return string
     */
    private static function plugin_release(): string {
        return (string) plugin_meta::current()->release;
    }

    /**
     * Derives the tool list from the allowlist, keeping listed and callable tools identical.
     *
     * @return array
     */
    private static function tools(): array {
        $descriptions = tool_registry::descriptions();
        $schemas = tool_registry::schemas();
        $tools = [];
        foreach (array_keys(privacy_surface::allowed_tools()) as $name) {
            $schema = $schemas[$name] ?? null;
            $inputschema = [
                'type' => 'object',
                'properties' => $schema && $schema['properties'] ? $schema['properties'] : new \stdClass(),
                'additionalProperties' => false,
            ];
            if ($schema && !empty($schema['required'])) {
                $inputschema['required'] = $schema['required'];
            }
            $tools[] = [
                'name' => $name,
                'description' => $descriptions[$name] ?? '',
                'inputSchema' => $inputschema,
            ];
        }
        return $tools;
    }

    /**
     * Provides error.
     *
     * @param int $status The status.
     * @param mixed $id
     * @param int $code The code.
     * @param string $message The message.
     * @param array $extraheaders
     * @phpstan-param array<string,string> $extraheaders
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function error(int $status, $id, int $code, string $message, array $extraheaders = []): array {
        // Single funnel for JSON-RPC errors (#339): authentication, capability,
        // emergency switch, parse failures and unknown methods/tools. Origin
        // rejection uses its own log in handle(); 405 is deliberately unlogged,
        // as neither returns JSON-RPC errors. $message is fixed text/code, never
        // the access token; no error in this call path includes the token.
        access_log::log_failure($message);
        return self::result($status, $extraheaders, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    /**
     * Provides result.
     *
     * @param int $status The status.
     * @param array $headers
     * @phpstan-param array<string,string> $headers
     * @param array|null $body
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function result(int $status, array $headers, ?array $body): array {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
