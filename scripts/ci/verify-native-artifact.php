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
//
// Aufruf: php admin/cli/verify-native-artifact.php (aus dem Moodle-Root,
// diese Datei wird vom Workflow dorthin kopiert - kein Bestandteil des
// installierten Plugins).

define('CLI_SCRIPT', true);
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
exit(0);
