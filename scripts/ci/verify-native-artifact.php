<?php
// Artefakt-Check des nativen Server-MCP (Issue #268, Akzeptanzkriterium 8).
//
// Wird vom Workflow-Job "artifact" NACH der Installation des gebauten
// Release-ZIPs (scripts/build-native-release.js, #577) auf einer frischen,
// vom Testinstanz-Matrixjob getrennten Moodle-Instanz aufgerufen - siehe
// .github/workflows/native-ci.yml. Prueft dieselbe Kette, die eine
// Lehrkraft/ein KI-Client tatsaechlich durchlaeuft:
//
//   1. Registrierung: die Webservice-Funktion ist nach dem Upgrade bekannt.
//   2. Discovery: die Funktion gehoert zum Dienst "Coursepilot".
//   3. Autorisierter Aufruf: ein Nutzer mit lokal/coursepilot:useremote
//      kann coursepilot_get_version_info (schreibfrei, ohne Kurskontext)
//      wirklich ausfuehren und bekommt eine gueltige Antwort.
//   4. MCP-Aufruf: mit einem ueber OAuth (DCR, PKCE, Code-Einloesung)
//      ausgestellten Token server/discover, tools/list und tools/call ueber
//      den Dispatcher; ohne Token 401; Versionen stimmen mit version.php.
//
// Aufruf: php admin/cli/verify-native-artifact.php (aus dem Moodle-Root,
// diese Datei wird vom Workflow dorthin kopiert - kein Bestandteil des
// installierten Plugins).

define('CLI_SCRIPT', true);
// Wie mcp.php: ohne WS_SERVER lehnt external_api::call_external_function()
// im Cookie-losen CLI-Kontext jeden loginpflichtigen Aufruf ab
// ('servicerequireslogin') - der echte MCP-Endpunkt laeuft genau so.
define('WS_SERVER', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/accesslib.php');

/**
 * @param string $message
 * @return void
 */
function verify_native_artifact_fail(string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

global $DB;

$component = 'local_coursepilot';
$functionname = 'local_coursepilot_get_version_info';

// 1. Registrierung.
if (!$DB->record_exists('external_functions', ['name' => $functionname, 'component' => $component])) {
    verify_native_artifact_fail("Webservice-Funktion {$functionname} ist nach dem Upgrade nicht registriert.");
}

// 2. Discovery ueber den deklarierten Dienst.
$service = $DB->get_record('external_services', ['shortname' => 'coursepilot']);
if (!$service) {
    verify_native_artifact_fail('Dienst "coursepilot" (external_services) fehlt.');
}
$inservice = $DB->record_exists('external_services_functions', [
    'externalserviceid' => $service->id,
    'functionname' => $functionname,
]);
if (!$inservice) {
    verify_native_artifact_fail("{$functionname} ist nicht Teil des Dienstes \"coursepilot\".");
}

// 3. Autorisierter Aufruf: eigener Testnutzer mit local/coursepilot:useremote
// statt des Admin-Kontos - prueft die tatsaechliche Capability-Kette, nicht
// nur "geht mit Admin-Rechten alles". Das Passwort wird pro Lauf zufaellig
// erzeugt und nirgends ausgegeben - kein Secret im Quelltext.
$user = $DB->get_record('user', ['username' => 'coursepilot_artifact_check']);
if (!$user) {
    $randompassword = 'ArtifactCheck#' . bin2hex(random_bytes(16));
    $user = create_user_record('coursepilot_artifact_check', $randompassword, 'manual');
    // Ohne Vor-/Nachname und E-Mail lehnt require_login() den Aufruf mit
    // "User not fully set-up" ab - ein echtes Lehrkraftkonto hat sie.
    $user->firstname = 'Artifact';
    $user->lastname = 'Check';
    $user->email = 'coursepilot_artifact_check@example.com';
    $DB->update_record('user', $user);
}
$systemcontext = \context_system::instance();
$roleid = create_role('Coursepilot Artifact Check', 'coursepilot_artifact_check', 'Nur fuer den CI-Artefakt-Check');
assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, $systemcontext->id, true);
role_assign($roleid, $user->id, $systemcontext->id);

// Frischer CLI-Prozess: keine gealterten Capability-Caches vorhanden, ein
// zusaetzlicher Cache-Reset ist hier nicht noetig (anders als im
// PHPUnit-Kontext, fuer den accesslib_clear_all_caches_for_unit_testing()
// gedacht ist).
\core\session\manager::set_user($user);

$result = \core_external\external_api::call_external_function($functionname, []);

if (!empty($result['error'])) {
    $exception = $result['exception'] ?? null;
    $detail = is_object($exception) ? ($exception->message ?? json_encode($exception)) : json_encode($result);
    verify_native_artifact_fail("Autorisierter Aufruf von {$functionname} ist fehlgeschlagen: {$detail}");
}

if (!isset($result['data']) || !is_array($result['data']) || empty($result['data'])) {
    verify_native_artifact_fail("Autorisierter Aufruf von {$functionname} lieferte keine verwertbaren Daten.");
}

fwrite(STDOUT, "OK: {$functionname} registriert, discoverbar und autorisiert aufrufbar.\n");

// 4. Derselbe Aufruf als MCP-Anfrage (#578): OAuth-Token ueber den echten
// Autorisierungsweg (DCR, Code mit PKCE, Einloesung), dann Discovery und
// Werkzeugaufruf ueber dispatcher::handle() - dieselbe Seam, an die mcp.php
// den HTTP-Rumpf uebergibt. Nur der HTTP-Transport selbst entfaellt.
$client = \local_coursepilot\oauth_lib::register_client([
    'redirect_uris' => ['http://127.0.0.1/callback'],
    'client_name' => 'Coursepilot Artifact Check',
]);
if (empty($client['client_id'])) {
    verify_native_artifact_fail('OAuth-Client-Registrierung fehlgeschlagen: ' . json_encode($client));
}
$verifier = bin2hex(random_bytes(32));
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$code = \local_coursepilot\oauth_lib::issue_code($client['client_id'], (int) $user->id, 'http://127.0.0.1/callback', $challenge);
$tokens = \local_coursepilot\oauth_lib::exchange_code($code, $client['client_id'], 'http://127.0.0.1/callback', $verifier);
if (empty($tokens['access_token'])) {
    verify_native_artifact_fail('OAuth-Code-Einloesung lieferte kein Access-Token.');
}

$headers = ['origin' => null, 'pathinfo' => '', 'method' => 'POST'];
$mcp = static function(string $method, array $params, ?string $token) use ($headers): array {
    $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params];
    return \local_coursepilot\dispatcher::handle($request, $token, $headers);
};

$unauthorized = $mcp('tools/list', [], null);
if ($unauthorized['status'] !== 401) {
    verify_native_artifact_fail("MCP ohne Token lieferte HTTP {$unauthorized['status']} statt 401.");
}

$discover = $mcp('server/discover', [], $tokens['access_token']);
$serverinfo = $discover['body']['result']['serverInfo'] ?? null;
if ($discover['status'] !== 200 || ($serverinfo['name'] ?? null) !== $component) {
    verify_native_artifact_fail('MCP server/discover fehlgeschlagen: ' . json_encode($discover['body']));
}

$list = $mcp('tools/list', [], $tokens['access_token']);
$toolnames = array_column($list['body']['result']['tools'] ?? [], 'name');
if ($list['status'] !== 200 || !in_array('coursepilot_get_version_info', $toolnames, true)) {
    verify_native_artifact_fail('MCP tools/list enthaelt coursepilot_get_version_info nicht.');
}

$call = $mcp('tools/call', ['name' => 'coursepilot_get_version_info', 'arguments' => []], $tokens['access_token']);
$info = $call['body']['result']['structuredContent'] ?? null;
if ($call['status'] !== 200 || !empty($call['body']['result']['isError']) || !is_array($info)) {
    verify_native_artifact_fail('MCP tools/call fehlgeschlagen: ' . json_encode($call['body']));
}
// Versionskonsistenz: Handshake, Werkzeugantwort und installierte Version
// nennen denselben Stand wie version.php des installierten ZIPs.
$plugin = new stdClass();
require($CFG->dirroot . '/local/coursepilot/version.php');
if ($serverinfo['version'] !== $plugin->release || $info['plugin_release'] !== $plugin->release
        || (int) $info['plugin_version'] !== (int) $plugin->version
        || (int) $info['plugin_version_db'] !== (int) $plugin->version) {
    verify_native_artifact_fail('Versionsangaben widersprechen version.php: ' . json_encode([$serverinfo, $info]));
}

fwrite(STDOUT, "OK: MCP ueber OAuth - server/discover, tools/list ("
    . count($toolnames) . " Werkzeuge), tools/call coursepilot_get_version_info -> "
    . "{$info['plugin_release']} ({$info['plugin_version']}), Moodle {$info['moodle_release']}.\n");
exit(0);
