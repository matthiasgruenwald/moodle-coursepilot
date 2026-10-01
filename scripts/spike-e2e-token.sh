#!/usr/bin/env bash
# Stellt ein OAuth-Zugriffstoken fuer die Spike-Instanz aus und gibt es aus.
#
# Ersetzt den interaktiven Browser-Flow (DCR -> /authorize -> /token) fuer
# Testlaeufe: Client registrieren, Code ausstellen, Code einloesen - alles
# per CLI im Spike-Container. Damit braucht ein E2E-Lauf gegen mcp.php keine
# manuell kopierten Zugangsdaten.
#
#   bash scripts/spike-e2e-token.sh            # Nutzer aus .env.e2e.spike
#   bash scripts/spike-e2e-token.sh grw        # anderer Nutzername
#
# ponytail: Token wird nicht zwischengespeichert - es lebt eine Stunde, und
# neu ausstellen ist billiger als eine Cache-Datei zu verwalten.
set -euo pipefail

CONTAINER="${KURSPILOT_SPIKE_CONTAINER:-moodle-kurspilot-spike-webserver-1}"
USERNAME="${1:-${KURSPILOT_SPIKE_USERNAME:-teacher_edit}}"

docker exec -i "$CONTAINER" php -- "$USERNAME" <<'PHP'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$username = $argv[1] ?? '';
$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id', MUST_EXIST);

$redirecturi = 'http://127.0.0.1/callback';
$client = \local_coursepilot\oauth_lib::register_client([
    'redirect_uris' => [$redirecturi],
    'client_name' => 'kurspilot-e2e',
]);
if (!empty($client['error'])) {
    fwrite(STDERR, 'Client-Registrierung fehlgeschlagen: ' . $client['error'] . PHP_EOL);
    exit(1);
}

$verifier = \local_coursepilot\oauth_lib::random_token(32);
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$code = \local_coursepilot\oauth_lib::issue_code($client['client_id'], (int) $user->id, $redirecturi, $challenge);
$pair = \local_coursepilot\oauth_lib::exchange_code($code, $client['client_id'], $redirecturi, $verifier);
if ($pair === null) {
    fwrite(STDERR, 'Code-Einloesung fehlgeschlagen.' . PHP_EOL);
    exit(1);
}
echo $pair['access_token'] . PHP_EOL;
PHP
