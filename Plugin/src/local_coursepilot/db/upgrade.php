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
 * Upgrade steps. The plugin existed (#309/#312/#334) before the OAuth
 * client table (#335). Moodle does not diff install.xml on existing
 * installations, so create new tables here, including codes/tokens (#336).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

/**
 * Provides xmldb local coursepilot upgrade.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_coursepilot_upgrade(int $oldversion): bool {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/local/coursepilot/db/upgradelib.php');

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082001) {
        $table = new xmldb_table('local_coursepilot_oauth_client');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('clientname', XMLDB_TYPE_CHAR, '255');
        $table->add_field('redirecturis', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('tokenendpointauthmethod', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'none');
        $table->add_field('clientsecret', XMLDB_TYPE_CHAR, '128');
        $table->add_field('source', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'dcr');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('clientid', XMLDB_INDEX_UNIQUE, ['clientid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026082001, 'local', 'coursepilot');
    }

    if ($oldversion < 2026082002) {
        $codetable = new xmldb_table('local_coursepilot_oauth_code');
        $codetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $codetable->add_field('code', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $codetable->add_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $codetable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $codetable->add_field('redirecturi', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $codetable->add_field('codechallenge', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL);
        $codetable->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $codetable->add_field('used', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $codetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $codetable->add_index('code', XMLDB_INDEX_UNIQUE, ['code']);
        if (!$dbman->table_exists($codetable)) {
            $dbman->create_table($codetable);
        }

        $tokentable = new xmldb_table('local_coursepilot_oauth_token');
        $tokentable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $tokentable->add_field('accesstoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $tokentable->add_field('refreshtoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $tokentable->add_field('clientid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $tokentable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tokentable->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tokentable->add_field('refreshexpires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tokentable->add_field('revoked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $tokentable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tokentable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $tokentable->add_index('accesstoken', XMLDB_INDEX_UNIQUE, ['accesstoken']);
        $tokentable->add_index('refreshtoken', XMLDB_INDEX_UNIQUE, ['refreshtoken']);
        if (!$dbman->table_exists($tokentable)) {
            $dbman->create_table($tokentable);
        }

        upgrade_plugin_savepoint(true, 2026082002, 'local', 'coursepilot');
    }

    if ($oldversion < 2026082701) {
        // Change history (#385): the observer starts recording now. No bulk
        // backfill; the first course_module_updated event per cmid creates version 1.
        $versiontable = new xmldb_table('local_coursepilot_cm_version');
        $versiontable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $versiontable->add_field('cmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versiontable->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versiontable->add_field('source', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'moodle');
        $versiontable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versiontable->add_field('moduleinfo_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $versiontable->add_field('coursemodule_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $versiontable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versiontable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $versiontable->add_index('cmid_version', XMLDB_INDEX_UNIQUE, ['cmid', 'version']);
        if (!$dbman->table_exists($versiontable)) {
            $dbman->create_table($versiontable);
        }

        $filetable = new xmldb_table('local_coursepilot_cm_file');
        $filetable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $filetable->add_field('pathnamehash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL);
        $filetable->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL);
        $filetable->add_field('component', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $filetable->add_field('filearea', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL);
        $filetable->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $filetable->add_field('filepath', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $filetable->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $filetable->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $filetable->add_field('mimetype', XMLDB_TYPE_CHAR, '100');
        $filetable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $filetable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $filetable->add_index('pathnamehash_contenthash', XMLDB_INDEX_UNIQUE, ['pathnamehash', 'contenthash']);
        if (!$dbman->table_exists($filetable)) {
            $dbman->create_table($filetable);
        }

        $versionfiletable = new xmldb_table('local_coursepilot_cm_version_file');
        $versionfiletable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $versionfiletable->add_field('versionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versionfiletable->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $versionfiletable->add_field('gap', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $versionfiletable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $versionfiletable->add_index('versionid', XMLDB_INDEX_NOTUNIQUE, ['versionid']);
        if (!$dbman->table_exists($versionfiletable)) {
            $dbman->create_table($versionfiletable);
        }

        upgrade_plugin_savepoint(true, 2026082701, 'local', 'coursepilot');
    }

    if ($oldversion < 2026082801) {
        // Retention (#387): record courseid for cascading course deletion.
        // course_modules is already deleted by course_deleted, so history rows
        // must retain the cmid/courseid mapping.
        $versiontable = new xmldb_table('local_coursepilot_cm_version');
        $courseidfield = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cmid');
        if (!$dbman->field_exists($versiontable, $courseidfield)) {
            $dbman->add_field($versiontable, $courseidfield);
        }

        $courseidindex = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($versiontable, $courseidindex)) {
            $dbman->add_index($versiontable, $courseidindex);
        }

        $cmidtimecreatedindex = new xmldb_index('cmid_timecreated', XMLDB_INDEX_NOTUNIQUE, ['cmid', 'timecreated']);
        if (!$dbman->index_exists($versiontable, $cmidtimecreatedindex)) {
            $dbman->add_index($versiontable, $cmidtimecreatedindex);
        }

        // Existing rows (#385/#386) retain courseid=0 without bulk backfill,
        // as in #386. They are outside the course cascade until the next manual
        // change, an edge case from the short period before #387.
        upgrade_plugin_savepoint(true, 2026082801, 'local', 'coursepilot');
    }

    if ($oldversion < 2026082903) {
        // Arrangement state (#396): populated only for quiz, otherwise NULL.
        // Existing rows (#385/#386/#387) retain NULL without bulk backfill.
        $versiontable = new xmldb_table('local_coursepilot_cm_version');
        $arrangementfield = new xmldb_field(
            'arrangement_json',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'coursemodule_json'
        );
        if (!$dbman->field_exists($versiontable, $arrangementfield)) {
            $dbman->add_field($versiontable, $arrangementfield);
        }

        upgrade_plugin_savepoint(true, 2026082903, 'local', 'coursepilot');
    }

    if ($oldversion < 2026083100) {
        // Move context storage to Private Files (#407, Spec 0016 §3.1). Copy
        // legacy files rather than moving them, preserving a fallback for teachers
        // to clear manually. Skip collisions and report them in the upgrade log.
        $migrated = \local_coursepilot\context_files::migrate_legacy_files();
        mtrace('local_coursepilot: ' . $migrated . ' context file(s) copied to Private Files.');

        upgrade_plugin_savepoint(true, 2026083100, 'local', 'coursepilot');
    }

    if ($oldversion < 2026090108) {
        // Cloning (#421, Spec 0017 §7.5): source module ID for cloned states,
        // NULL for other sources. No bulk backfill, as in #386/#387/#396.
        $versiontable = new xmldb_table('local_coursepilot_cm_version');
        $sourcecmidfield = new xmldb_field('sourcecmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'source');
        if (!$dbman->field_exists($versiontable, $sourcecmidfield)) {
            $dbman->add_field($versiontable, $sourcecmidfield);
        }

        upgrade_plugin_savepoint(true, 2026090108, 'local', 'coursepilot');
    }

    if ($oldversion < 2026090202) {
        // OAuth schema drift (#424 follow-up 3): install.xml was updated during
        // #335/#336 without migrating existing instances, causing behavior to
        // differ between new and upgraded installations.
        local_coursepilot_repair_oauth_schema_drift($dbman);

        upgrade_plugin_savepoint(true, 2026090202, 'local', 'coursepilot');
    }

    if ($oldversion < 2026091101) {
        // Marking memory (#493, Spec #486 §6): marked/unmarked bit per context
        // file, keyed by path, size, modification time and ETag. See mark_memory.
        $marktable = new xmldb_table('local_coursepilot_context_mark');
        $marktable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $marktable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $marktable->add_field('path', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $marktable->add_field('pathhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL);
        $marktable->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $marktable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $marktable->add_field('etag', XMLDB_TYPE_CHAR, '255');
        $marktable->add_field('ismarked', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $marktable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $marktable->add_index('userid_pathhash', XMLDB_INDEX_UNIQUE, ['userid', 'pathhash']);
        if (!$dbman->table_exists($marktable)) {
            $dbman->create_table($marktable);
        }

        upgrade_plugin_savepoint(true, 2026091101, 'local', 'coursepilot');
    }

    if ($oldversion < 2026091202) {
        // Single-use workbench ticket (#501, Spec #486 §13), tied to user,
        // path and contenthash, valid for 15 minutes. Store only its hash;
        // see workbench_ticket (named werkbank_ticket before #602).
        $tickettable = new xmldb_table('local_coursepilot_werkbank_ticket');
        $tickettable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $tickettable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tickettable->add_field('path', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $tickettable->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL);
        $tickettable->add_field('tickethash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $tickettable->add_field('oauthtokenid', XMLDB_TYPE_INTEGER, '10');
        $tickettable->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tickettable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $tickettable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $tickettable->add_index('tickethash', XMLDB_INDEX_UNIQUE, ['tickethash']);
        if (!$dbman->table_exists($tickettable)) {
            $dbman->create_table($tickettable);
        }

        upgrade_plugin_savepoint(true, 2026091202, 'local', 'coursepilot');
    }

    if ($oldversion < 2026092301) {
        local_coursepilot_hash_oauth_tokens($dbman);

        upgrade_plugin_savepoint(true, 2026092301, 'local', 'coursepilot');
    }

    if ($oldversion < 2026100200) {
        // Issue #602 (ADR 0024): English history source keys.
        local_coursepilot_migrate_history_sources();

        upgrade_plugin_savepoint(true, 2026100200, 'local', 'coursepilot');
    }

    if ($oldversion < 2026100201) {
        // Issue #602 (ADR 0024): English workbench ticket table, anchor filenames
        // and stored keys.
        $oldtable = new xmldb_table('local_coursepilot_werkbank_ticket');
        if ($dbman->table_exists($oldtable)
                && !$dbman->table_exists(new xmldb_table('local_coursepilot_workbench_ticket'))) {
            $dbman->rename_table($oldtable, 'local_coursepilot_workbench_ticket');
        }
        local_coursepilot_migrate_anchor_files();

        upgrade_plugin_savepoint(true, 2026100201, 'local', 'coursepilot');
    }

    if ($oldversion < 2026100300) {
        local_coursepilot_migrate_oauth_connections($dbman);
        upgrade_plugin_savepoint(true, 2026100300, 'local', 'coursepilot');
    }

    if ($oldversion < 2026100340) {
        // Issue #640: indexes for the scheduled history retention and metadata sweep.
        $retentionindexes = ['local_coursepilot_cm_version' => 'timecreated', 'local_coursepilot_cm_version_file' => 'fileid'];
        foreach ($retentionindexes as $tablename => $field) {
            $index = new xmldb_index($field, XMLDB_INDEX_NOTUNIQUE, [$field]);
            $table = new xmldb_table($tablename);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        upgrade_plugin_savepoint(true, 2026100340, 'local', 'coursepilot');
    }

    if ($oldversion < 2026100342) {
        // Issue #642: windowed budgets for anonymous OAuth registration and CIMD.
        $table = new xmldb_table('local_coursepilot_oauth_budget');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('scope', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sourcekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('expires', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('hits', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('scope_source_expires', XMLDB_INDEX_UNIQUE, ['scope', 'sourcekey', 'expires']);
        $table->add_index('expires', XMLDB_INDEX_NOTUNIQUE, ['expires']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026100342, 'local', 'coursepilot');
    }

    if ($oldversion < 2026100344) {
        // Issue #644: indexes of the bounded OAuth cleanup; the task is renamed to oauth_cleanup.
        local_coursepilot_add_oauth_cleanup_indexes($dbman);
        upgrade_plugin_savepoint(true, 2026100344, 'local', 'coursepilot');
    }

    return true;
}
