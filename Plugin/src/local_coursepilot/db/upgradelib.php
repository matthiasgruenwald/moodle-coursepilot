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

    // refreshtoken: NOT NULL laut install.xml. Eine Zeile ohne Refresh-Token
    // ist unbrauchbar (die Rotation aus #336 kann sie nicht erneuern) - sie
    // wird entfernt statt mit einem Platzhalter gefuellt, der als gueltiges
    // Token aussaehe.
    $tokentable = new xmldb_table('local_coursepilot_oauth_token');
    $refreshtoken = new xmldb_field('refreshtoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    if ($dbman->field_exists($tokentable, $refreshtoken)) {
        $DB->delete_records_select('local_coursepilot_oauth_token', 'refreshtoken IS NULL');

        $refreshindex = new xmldb_index('refreshtoken', XMLDB_INDEX_UNIQUE, ['refreshtoken']);
        $hadindex = $dbman->index_exists($tokentable, $refreshindex);
        if ($hadindex) {
            $dbman->drop_index($tokentable, $refreshindex);
        }
        $dbman->change_field_notnull($tokentable, $refreshtoken);
        if ($hadindex) {
            $dbman->add_index($tokentable, $refreshindex);
        }
    }
}
