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
 * Upgrade-Schritte. Das Plugin war bereits installiert (#309/#312/#334),
 * bevor die OAuth-Client-Tabelle (#335) hinzukam - Moodle diff't install.xml
 * nicht automatisch gegen ein bestehendes Plugin, deshalb legen wir sie hier
 * an. Gleiches Muster fuer die Code-/Token-Tabellen aus #336.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
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
        // Aenderungsverlauf (#385): Beobachter schreibt ab jetzt, kein
        // Massen-Backfill bestehender Aktivitaeten hier - der erste
        // course_module_updated je cmid legt Version 1 an.
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
        // Aufbewahrung/Loeschfrist (#387): courseid noetig fuer die Kurs-Kaskade -
        // course_modules ist beim course_deleted-Event bereits geloescht, die
        // Zuordnung cmid->courseid muss also schon in der Verlaufszeile stehen.
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

        // Bestandszeilen (aus #385/#386) tragen courseid=0 - kein Massen-Backfill,
        // gleiche Linie wie #386. Sie fallen bis zur naechsten Handaenderung
        // dieser cmid einfach aus der Kurs-Kaskade heraus (Rand-fall aus dem
        // kurzen Zeitraum vor #387).
        upgrade_plugin_savepoint(true, 2026082801, 'local', 'coursepilot');
    }

    if ($oldversion < 2026082903) {
        // Anordnungs-Stand (#396): nur fuer quiz befuellt, sonst NULL - Bestands-
        // zeilen (aus #385/#386/#387) bleiben mit arrangement_json=NULL zurueck,
        // kein Massen-Backfill (gleiche Linie wie #386/#387).
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
        // Umzug des Kontextbereichs auf Moodles Private Files (#407, Spec 0016
        // Abschnitt 3.1): Der Altbestand wird kopiert, nicht verschoben - er
        // bleibt als Rueckweg liegen, und die Lehrkraft raeumt ihn selbst in
        // "Meine Dateien". Kollision = ueberspringen und ins Upgrade-Log.
        $migrated = \local_coursepilot\context_files::migrate_legacy_files();
        mtrace('local_coursepilot: ' . $migrated . ' Kontextdatei(en) nach "Meine Dateien" kopiert.');

        upgrade_plugin_savepoint(true, 2026083100, 'local', 'coursepilot');
    }

    if ($oldversion < 2026090108) {
        // Klonen (#421, Spec 0017 §7.5): Quell-Modul-ID eines geklonten Standes
        // (source=geklont) - NULL fuer alle anderen Ursprungsarten, kein
        // Massen-Backfill (gleiche Linie wie #386/#387/#396).
        $versiontable = new xmldb_table('local_coursepilot_cm_version');
        $sourcecmidfield = new xmldb_field('sourcecmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'source');
        if (!$dbman->field_exists($versiontable, $sourcecmidfield)) {
            $dbman->add_field($versiontable, $sourcecmidfield);
        }

        upgrade_plugin_savepoint(true, 2026090108, 'local', 'coursepilot');
    }

    if ($oldversion < 2026090202) {
        // Schema-Drift der OAuth-Tabellen (#424 Nachlauf 3): install.xml wurde
        // waehrend #335/#336 nachgezogen, der Bestand nicht - eine
        // Neuinstallation verhielt sich anders als eine hochgezogene Instanz.
        local_coursepilot_repair_oauth_schema_drift($dbman);

        upgrade_plugin_savepoint(true, 2026090202, 'local', 'coursepilot');
    }

    if ($oldversion < 2026091101) {
        // Markierungsgedaechtnis (#493, Spec #486 §6): nur das Bit "markiert
        // ja/nein" je Kontextdatei, Schluessel aus Pfad, Groesse,
        // Aenderungszeit und ETag - siehe local_coursepilot\mark_memory.
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
        // Einmal-Downloadticket fuer Werkbankdateien (#501, Spec #486 §13):
        // gebunden an Person, Pfad und contenthash, 15 Minuten gueltig,
        // gespeichert wird nur der Hash des Tickets - siehe
        // local_coursepilot\werkbank_ticket.
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

    return true;
}
