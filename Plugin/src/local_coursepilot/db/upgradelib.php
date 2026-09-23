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
 * Hilfsfunktionen fuer db/upgrade.php - ausgelagert, damit sie ohne die
 * Savepoint-Maschinerie eines echten Upgrade-Laufs testbar sind.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Zieht den Bestand der OAuth-Tabellen auf db/install.xml (#424 Nachlauf 3).
 *
 * Waehrend der OAuth-Arbeit (#335/#336) wurde install.xml mehrfach
 * nachgezogen, ohne dass ein Upgrade-Schritt den Bestand mitgenommen haette.
 * Ergebnis: `admin/cli/check_database_schema.php` meldet fuenf Abweichungen,
 * und eine Neuinstallation verhaelt sich anders als eine hochgezogene
 * Instanz - der unangenehmste Zustand, weil kein Test ihn sieht (PHPUnit
 * installiert immer frisch aus install.xml).
 *
 * Idempotent: auf einer Neuinstallation ist bereits alles so, wie es sein
 * soll, und die Funktion aendert nichts Sichtbares.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_coursepilot_repair_oauth_schema_drift(database_manager $dbman): void {
    global $DB;

    // clientid: 64 Zeichen reichten fuer per DCR vergebene client_ids, nicht
    // fuer CIMD, wo die client_id die URL selbst ist (install.xml: 255).
    //
    // local_coursepilot_oauth_client traegt einen eindeutigen Index auf der
    // Spalte; Moodles database_manager weigert sich, eine indizierte Spalte
    // zu aendern (ddl_dependency_exception), deshalb Index ab, Spalte
    // aendern, Index zurueck.
    foreach ([
        'local_coursepilot_oauth_client' => new xmldb_index('clientid', XMLDB_INDEX_UNIQUE, ['clientid']),
        'local_coursepilot_oauth_code' => null,
        'local_coursepilot_oauth_token' => null,
    ] as $tablename => $index) {
        $table = new xmldb_table($tablename);
        $clientid = new xmldb_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        if (!$dbman->field_exists($table, $clientid)) {
            continue;
        }
        $hadindex = $index !== null && $dbman->index_exists($table, $index);
        if ($hadindex) {
            $dbman->drop_index($table, $index);
        }
        $dbman->change_field_precision($table, $clientid);
        if ($hadindex) {
            $dbman->add_index($table, $index);
        }
    }

    // codechallengemethod: PKCE ist auf S256 festgelegt (oauth_lib weist
    // jede andere Methode ab), der gespeicherte Wert wurde nie gelesen.
    // Die Spalte ist deshalb aus install.xml verschwunden - hier faellt sie
    // im Bestand nach.
    $codetable = new xmldb_table('local_coursepilot_oauth_code');
    $challengemethod = new xmldb_field('codechallengemethod');
    if ($dbman->field_exists($codetable, $challengemethod)) {
        $dbman->drop_field($codetable, $challengemethod);
    }

    // refreshtokenhash: NOT NULL laut install.xml. Eine Zeile ohne
    // Refresh-Token-Hash ist unbrauchbar (die Rotation aus #336 kann sie
    // nicht erneuern) - sie wird entfernt statt mit einem Platzhalter
    // gefuellt, der als gueltiger Hash aussaehe.
    $tokentable = new xmldb_table('local_coursepilot_oauth_token');
    $refreshtokenhash = new xmldb_field('refreshtokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    if ($dbman->field_exists($tokentable, $refreshtokenhash)) {
        $DB->delete_records_select('local_coursepilot_oauth_token', 'refreshtokenhash IS NULL');

        $refreshindex = new xmldb_index('refreshtokenhash', XMLDB_INDEX_UNIQUE, ['refreshtokenhash']);
        $hadindex = $dbman->index_exists($tokentable, $refreshindex);
        if ($hadindex) {
            $dbman->drop_index($tokentable, $refreshindex);
        }
        $dbman->change_field_notnull($tokentable, $refreshtokenhash);
        if ($hadindex) {
            $dbman->add_index($tokentable, $refreshindex);
        }
    }
}

/**
 * Ersetzt Klartext-OAuth-Tokens durch SHA-256-Hashes (#534).
 *
 * Die Werte werden erst gehasht, bevor Klartextfelder und ihre Indexe entfernt
 * werden. Bereits ausgestellte Verbindungen bleiben dadurch bis zu Ablauf,
 * Rotation oder Widerruf nutzbar; nach erfolgreichem Upgrade bleibt kein
 * Geheimnis in der Datenbank zurueck.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_coursepilot_hash_oauth_tokens(database_manager $dbman): void {
    global $DB;

    $table = new xmldb_table('local_coursepilot_oauth_token');
    $access = new xmldb_field('accesstoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    $refresh = new xmldb_field('refreshtoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    if (!$dbman->field_exists($table, $access) || !$dbman->field_exists($table, $refresh)) {
        return;
    }

    $accesshash = new xmldb_field('accesstokenhash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'accesstoken');
    $refreshhash = new xmldb_field('refreshtokenhash', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'accesstokenhash');
    if (!$dbman->field_exists($table, $accesshash)) {
        $dbman->add_field($table, $accesshash);
    }
    if (!$dbman->field_exists($table, $refreshhash)) {
        $dbman->add_field($table, $refreshhash);
    }

    $records = $DB->get_records_sql('SELECT id, accesstoken, refreshtoken FROM {local_coursepilot_oauth_token}');
    foreach ($records as $record) {
        $DB->update_record('local_coursepilot_oauth_token', (object) [
            'id' => $record->id,
            'accesstokenhash' => hash('sha256', $record->accesstoken),
            'refreshtokenhash' => hash('sha256', $record->refreshtoken),
        ]);
    }

    $accesshash = new xmldb_field('accesstokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    $refreshhash = new xmldb_field('refreshtokenhash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    $dbman->change_field_notnull($table, $accesshash);
    $dbman->change_field_notnull($table, $refreshhash);
    $accesshashindex = new xmldb_index('accesstokenhash', XMLDB_INDEX_UNIQUE, ['accesstokenhash']);
    $refreshhashindex = new xmldb_index('refreshtokenhash', XMLDB_INDEX_UNIQUE, ['refreshtokenhash']);
    $dbman->add_index($table, $accesshashindex);
    $dbman->add_index($table, $refreshhashindex);

    $accessindex = new xmldb_index('accesstoken', XMLDB_INDEX_UNIQUE, ['accesstoken']);
    $refreshindex = new xmldb_index('refreshtoken', XMLDB_INDEX_UNIQUE, ['refreshtoken']);
    if ($dbman->index_exists($table, $accessindex)) {
        $dbman->drop_index($table, $accessindex);
    }
    if ($dbman->index_exists($table, $refreshindex)) {
        $dbman->drop_index($table, $refreshindex);
    }
    $dbman->drop_field($table, $access);
    $dbman->drop_field($table, $refresh);
}
