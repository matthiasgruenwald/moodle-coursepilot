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
 * Helper functions for db/upgrade.php - extracted so they are testable without the
 * savepoint machinery of a real upgrade run.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

/**
 * Brings the existing OAuth tables in line with db/install.xml (#424 follow-up 3).
 *
 * During the OAuth work (#335/#336) install.xml was adjusted several times
 * without an upgrade step carrying the existing data along.
 * Result: `admin/cli/check_database_schema.php` reports five deviations,
 * and a fresh installation behaves differently from an upgraded
 * instance - the most unpleasant state, because no test sees it (PHPUnit
 * always installs fresh from install.xml).
 *
 * Idempotent: on a fresh installation everything is already as it
 * should be, and the function changes nothing visible.
 *
 * @param database_manager $dbman
 * @return void
 */
function local_coursepilot_repair_oauth_schema_drift(database_manager $dbman): void {
    global $DB;

    // Note: clientid: 64 characters were enough for client_ids issued via DCR, not
    // for CIMD, where the client_id is the URL itself (install.xml: 255).
    //
    // local_coursepilot_oauth_client carries a unique index on the
    // column; Moodle's database_manager refuses to change an indexed column
    // (ddl_dependency_exception), hence drop the index, change the column,
    // restore the index.
    // aendern, Index zurueck.
    foreach (
        [
        'local_coursepilot_oauth_client' => new xmldb_index('clientid', XMLDB_INDEX_UNIQUE, ['clientid']),
        'local_coursepilot_oauth_code' => null,
        'local_coursepilot_oauth_token' => null,
        ] as $tablename => $index
    ) {
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

    // Note: codechallengemethod: PKCE is fixed to S256 (oauth_lib rejects
    // every other method), the stored value was never read.
    // The column has therefore disappeared from install.xml - here it is dropped
    // from the existing data.
    $codetable = new xmldb_table('local_coursepilot_oauth_code');
    $challengemethod = new xmldb_field('codechallengemethod');
    if ($dbman->field_exists($codetable, $challengemethod)) {
        $dbman->drop_field($codetable, $challengemethod);
    }

    // Note: refreshtokenhash: NOT NULL according to install.xml. A row without a
    // refresh token hash is unusable (the rotation from #336 cannot
    // renew it) - it is removed instead of being filled with a placeholder
    // that would look like a valid hash.
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
 * Replaces plaintext OAuth tokens with SHA-256 hashes (#534).
 *
 * The values are hashed first, before plaintext fields and their indexes are removed.
 * Connections already issued thereby remain usable until expiry,
 * rotation or revocation; after a successful upgrade no
 * secret remains in the database.
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

/**
 * Rewrites the German source keys of the change history to the
 * English ones (#602, ADR 0024): "vorgefunden" -> "discovered",
 * "geklont" -> "cloned". Idempotent.
 * @return void
 */
function local_coursepilot_migrate_history_sources(): void {
    global $DB;

    foreach (['vorgefunden' => 'discovered', 'geklont' => 'cloned'] as $old => $new) {
        $DB->set_field('local_coursepilot_cm_version', 'source', $new, ['source' => $old]);
    }
}

/**
 * Renames the storage files at the anchor to English and translates their content
 * (#602, ADR 0024): context pointer ".coursepilot-ort.json" ->
 * ".coursepilot-location.json" (keys via
 * {@see \local_coursepilot\context_pointer::normalise()}), pending note
 * ".coursepilot-ausstand.json" -> ".coursepilot-pending.json" (keys,
 * operations and WebDAV error classes in English). If the new file already
 * exists, it wins and the old one is dropped. Idempotent.
 * @return void
 */
function local_coursepilot_migrate_anchor_files(): void {
    global $DB;

    $renames = [
        '.coursepilot-ort.json' => '.coursepilot-location.json',
        '.coursepilot-ausstand.json' => '.coursepilot-pending.json',
    ];
    $fs = get_file_storage();
    foreach ($renames as $oldname => $newname) {
        $records = $DB->get_records('files', [
            'component' => 'user', 'filearea' => 'private', 'itemid' => 0, 'filename' => $oldname,
        ]);
        foreach ($records as $record) {
            $old = $fs->get_file_instance($record);
            $decoded = json_decode($old->get_content(), true);
            if (!is_array($decoded) || array_is_list($decoded)) {
                // Unreadable: leave in place instead of silently losing the location selection.
                continue;
            }
            if (!$fs->file_exists($record->contextid, 'user', 'private', 0, $record->filepath, $newname)) {
                $translated = $oldname === '.coursepilot-ort.json'
                    ? \local_coursepilot\context_pointer::normalise($decoded)
                    : local_coursepilot_translate_pending_entries($decoded);
                $fs->create_file_from_string([
                    'contextid' => $record->contextid, 'component' => 'user', 'filearea' => 'private',
                    'itemid' => 0, 'filepath' => $record->filepath, 'filename' => $newname,
                    'userid' => $record->userid,
                ], json_encode($translated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $old->delete();
        }
    }
}

/**
 * Translates entries of a pending note from before #602 (German keys,
 * operations and WebDAV error classes) into the English form.
 *
 * @param array $entries Identifier => entry.
 * @return array
 */
function local_coursepilot_translate_pending_entries(array $entries): array {
    $keys = ['zeitpunkt' => 'timestamp', 'pfad' => 'path', 'vorgang' => 'operation',
        'fehlerklasse' => 'error_class', 'kursid' => 'course_id'];
    $values = [
        'anlegen' => 'create', 'überschreiben' => 'overwrite', 'ueberschreiben' => 'overwrite',
        'anhängen' => 'append', 'anhaengen' => 'append', 'unbekannt' => 'unknown',
        'unklar/gedrosselt' => 'unclear', 'nicht gefunden' => 'not_found', 'Anmeldung abgelehnt' => 'auth_rejected',
        'nicht erreichbar' => 'unreachable', 'Speicher voll' => 'storage_full', 'Konflikt' => 'conflict',
        'gesperrt' => 'blocked', 'Weiterleitung abgelehnt' => 'redirected',
    ];
    $result = [];
    foreach ($entries as $identifier => $entry) {
        $translated = [];
        foreach ((array) $entry as $key => $value) {
            $key = $keys[$key] ?? $key;
            if (in_array($key, ['operation', 'error_class'], true) && is_string($value)) {
                $value = $values[$value] ?? $value;
            }
            $translated[$key] = $value;
        }
        $result[$identifier] = $translated;
    }
    return $result;
}

/**
 * Add stable grants and backfill in bounded, independently committed batches.
 * Unknown historical families stay NULL; never group by user/client or guess.
 *
 * @param database_manager $dbman The dbman.
 */
function local_coursepilot_migrate_oauth_connections(database_manager $dbman): void {
    global $DB;
    $grant = new xmldb_table('local_coursepilot_oauth_grant');
    $grant->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
    $grant->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $grant->add_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
    $grant->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
    $grant->add_field('statehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
    $grant->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
    $grant->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $grant->add_index('userid_revoked', XMLDB_INDEX_NOTUNIQUE, ['userid', 'revoked']);
    if (!$dbman->table_exists($grant)) {
        $dbman->create_table($grant);
    }
    foreach (
        ['local_coursepilot_oauth_token' => 'connectionid',
            'local_coursepilot_workbench_ticket' => 'oauthconnectionid'] as $name => $column
    ) {
        $table = new xmldb_table($name);
        $field = new xmldb_field($column, XMLDB_TYPE_INTEGER, '10');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index($column, XMLDB_INDEX_NOTUNIQUE, [$column]);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
    }
    while (
        $records = $DB->get_records_select(
            'local_coursepilot_oauth_token',
            'connectionid IS NULL AND revoked = 0',
            [],
            'id',
            '*',
            0,
            100
        )
    ) {
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($records as $record) {
                $id = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
                    'userid' => $record->userid, 'clientid' => $record->clientid, 'revoked' => 0,
                    'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $record->timecreated,
                ]);
                $DB->set_field('local_coursepilot_oauth_token', 'connectionid', $id, ['id' => $record->id]);
                $DB->set_field(
                    'local_coursepilot_workbench_ticket',
                    'oauthconnectionid',
                    $id,
                    ['oauthtokenid' => $record->id, 'userid' => $record->userid]
                );
            }
        } catch (Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
    }
}

/**
 * Indexes of the bounded OAuth cleanup (#644), keyed by table.
 *
 * @return array<string, xmldb_index>
 */
function local_coursepilot_oauth_cleanup_indexes(): array {
    return [
        'local_coursepilot_oauth_client' => new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']),
        'local_coursepilot_oauth_code' => new xmldb_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']),
        'local_coursepilot_oauth_grant' => new xmldb_index('revoked_clientid', XMLDB_INDEX_NOTUNIQUE, ['revoked', 'clientid']),
        'local_coursepilot_workbench_ticket' => new xmldb_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']),
    ];
}

/**
 * Add the OAuth cleanup indexes where missing; safe to repeat.
 *
 * @param database_manager $dbman
 */
function local_coursepilot_add_oauth_cleanup_indexes(database_manager $dbman): void {
    foreach (local_coursepilot_oauth_cleanup_indexes() as $name => $index) {
        $table = new xmldb_table($name);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
    }
}
