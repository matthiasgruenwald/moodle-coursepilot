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

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/coursepilot/db/upgradelib.php');

/**
 * Schema-drift repair (#424 follow-up 3).
 *
 * Fresh PHPUnit installs never encounter upgraded-instance drift.
 * Reproduce all five check_database_schema.php discrepancies, repair them
 * and verify with Moodle’s own schema checker.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class upgradelib_test extends \advanced_testcase {
    /** @var string[] Tables affected by schema drift. */
    private const TABLES = [
        'local_coursepilot_oauth_client',
        'local_coursepilot_oauth_code',
        'local_coursepilot_oauth_token',
    ];

    /**
     * Moodle’s checker reports OAuth schema discrepancies before repair
     * and none afterward, ensuring this test actually detects drift.
     */
    public function test_repair_removes_oauth_schema_drift(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        try {
            $this->introduce_drift($dbman);

            $before = $this->schema_errors();
            $this->assertNotEmpty($before, 'The artificial drift was not detected by the schema check.');
        } finally {
            // Repair even if assertions fail: resetAfterTest() does not undo DDL
            // and unrepaired schema changes would break later tests.
            local_coursepilot_repair_oauth_schema_drift($dbman);
        }

        $this->assertSame([], $this->schema_errors());
    }

    /**
     * A second repair on a clean schema changes nothing and does not throw.
     * The upgrade works on fresh as well as upgraded installations.
     */
    public function test_repair_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();

        local_coursepilot_repair_oauth_schema_drift($DB->get_manager());
        local_coursepilot_repair_oauth_schema_drift($DB->get_manager());

        $this->assertSame([], $this->schema_errors());
    }

    /**
     * Remove rows lacking refresh-token hashes rather than filling NOT NULL
     * with a placeholder resembling a valid token.
     */
    public function test_repair_drops_token_rows_without_refresh_token(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        try {
            $this->introduce_drift($dbman);

            $DB->insert_record('local_coursepilot_oauth_token', (object) [
                'accesstokenhash' => hash('sha256', 'kaputt'),
                'refreshtokenhash' => null,
                'clientid' => 'client',
                'userid' => 1,
                'expires' => time() + 3600,
                'refreshexpires' => time() + 3600,
                'revoked' => 0,
                'timecreated' => time(),
            ]);
        } finally {
            local_coursepilot_repair_oauth_schema_drift($dbman);
        }

        $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_token', ['accesstokenhash' => hash('sha256', 'kaputt')]));
        $this->assertSame([], $this->schema_errors());
    }

    /**
     * Hash existing secrets before removing plaintext fields. Connections
     * and refresh rotation remain usable without tokens in database dumps.
     */
    public function test_hash_upgrade_preserves_existing_connection_without_retaining_cleartext(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $tokentable = new \xmldb_table('local_coursepilot_oauth_token');
        $accesshashindex = new \xmldb_index('accesstokenhash', XMLDB_INDEX_UNIQUE, ['accesstokenhash']);
        $refreshhashindex = new \xmldb_index('refreshtokenhash', XMLDB_INDEX_UNIQUE, ['refreshtokenhash']);
        $accesshash = new \xmldb_field('accesstokenhash');
        $refreshhash = new \xmldb_field('refreshtokenhash');
        $dbman->drop_index($tokentable, $accesshashindex);
        $dbman->drop_index($tokentable, $refreshhashindex);
        $dbman->drop_field($tokentable, $accesshash);
        $dbman->drop_field($tokentable, $refreshhash);

        $access = oauth_lib::random_token(32);
        $refresh = oauth_lib::random_token(32);
        $accessfield = new \xmldb_field('accesstoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'id');
        $refreshfield = new \xmldb_field('refreshtoken', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'accesstoken');
        $dbman->add_field($tokentable, $accessfield);
        $dbman->add_field($tokentable, $refreshfield);
        $dbman->add_index($tokentable, new \xmldb_index('accesstoken', XMLDB_INDEX_UNIQUE, ['accesstoken']));
        $dbman->add_index($tokentable, new \xmldb_index('refreshtoken', XMLDB_INDEX_UNIQUE, ['refreshtoken']));
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'accesstoken' => $access,
            'refreshtoken' => $refresh,
            'clientid' => 'legacy-client',
            'userid' => $user->id,
            'expires' => time() + oauth_lib::ACCESS_TOKEN_TTL,
            'refreshexpires' => time() + oauth_lib::REFRESH_TOKEN_TTL,
            'revoked' => 0,
            'timecreated' => time(),
        ]);

        local_coursepilot_hash_oauth_tokens($dbman);
        local_coursepilot_migrate_oauth_connections($dbman);

        $this->assertFalse($dbman->field_exists($tokentable, $accessfield));
        $this->assertFalse($dbman->field_exists($tokentable, $refreshfield));
        $this->assertSame((int) $user->id, oauth_lib::authenticate_access_token($access));
        $this->assertNotNull(oauth_lib::rotate_refresh_token($refresh, 'legacy-client'));
        $this->assertSame([], $this->schema_errors());
    }

    /**
     * Translate German history sources to English; preserve other values (#602).
     */
    public function test_history_sources_are_migrated_to_english(): void {
        global $DB;

        $this->resetAfterTest();
        foreach (['vorgefunden', 'geklont', 'moodle'] as $index => $source) {
            $DB->insert_record('local_coursepilot_cm_version', (object) [
                'cmid' => 100 + $index, 'courseid' => 1, 'version' => 1, 'source' => $source, 'userid' => 2,
                'moduleinfo_json' => '{}', 'coursemodule_json' => '{}', 'timecreated' => time(),
            ]);
        }

        local_coursepilot_migrate_history_sources();
        local_coursepilot_migrate_history_sources();

        $sources = $DB->get_fieldset_sql('SELECT source FROM {local_coursepilot_cm_version} ORDER BY cmid');
        $this->assertSame(['discovered', 'cloned', 'moodle'], $sources);
    }

    /**
     * Rename and translate old context pointers and pending notes;
     * remove the old files (#602).
     */
    public function test_anchor_files_are_renamed_and_translated(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $fs = get_file_storage();
        $record = ['contextid' => $context->id, 'component' => 'user', 'filearea' => 'private', 'itemid' => 0,
            'filepath' => '/coursepilot/'];
        $fs->create_file_from_string($record + ['filename' => '.coursepilot-ort.json'], json_encode([
            'kontextbereich' => ['ort' => 'extern', 'instanzid' => 3, 'pfad' => 'Kontext',
                'pruefmerkmal' => ['server' => 'cloud.example', 'basispfad' => 'dav', 'konto' => 'lea']],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material'],
            'ortsverlauf' => [['datum' => 5, 'ziel' => 'kontextbereich', 'von' => 'a', 'nach' => 'b']],
        ]));
        $fs->create_file_from_string($record + ['filename' => '.coursepilot-ausstand.json'], json_encode([
            'ABC' => ['zeitpunkt' => 7, 'pfad' => 'plan.md', 'vorgang' => 'überschreiben',
                'fehlerklasse' => 'Speicher voll', 'kursid' => 4],
        ]));

        $broken = $this->getDataGenerator()->create_user();
        $brokencontext = \context_user::instance($broken->id);
        $fs->create_file_from_string(['contextid' => $brokencontext->id] + $record + ['filename' => '.coursepilot-ort.json'], '{kaputt');

        local_coursepilot_migrate_anchor_files();
        local_coursepilot_migrate_anchor_files();

        $this->assertTrue($fs->file_exists($brokencontext->id, 'user', 'private', 0, '/coursepilot/', '.coursepilot-ort.json'));

        $this->assertFalse($fs->file_exists($context->id, 'user', 'private', 0, '/coursepilot/', '.coursepilot-ort.json'));
        $this->assertFalse($fs->file_exists($context->id, 'user', 'private', 0, '/coursepilot/', '.coursepilot-ausstand.json'));
        $pointer = json_decode($fs->get_file($context->id, 'user', 'private', 0, '/coursepilot/', '.coursepilot-location.json')
            ->get_content(), true);
        $this->assertSame([
            'context_area' => ['location' => 'external', 'instanceid' => 3, 'path' => 'Kontext',
                'fingerprint' => ['server' => 'cloud.example', 'basepath' => 'dav', 'account' => 'lea']],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
            'location_history' => [['date' => 5, 'target' => 'context_area', 'from_text' => 'a', 'to_text' => 'b']],
        ], $pointer);
        $pending = json_decode($fs->get_file($context->id, 'user', 'private', 0, '/coursepilot/', '.coursepilot-pending.json')
            ->get_content(), true);
        $this->assertSame(['ABC' => ['timestamp' => 7, 'path' => 'plan.md', 'operation' => 'overwrite',
            'error_class' => 'storage_full', 'course_id' => 4]], $pending);
    }

    /**
     * Reproduce Spike drift: clientid shortened to 64, codechallengemethod
     * present and refreshtokenhash nullable.
     *
     * @param \database_manager $dbman
     * @return void
     */
    private function introduce_drift(\database_manager $dbman): void {
        // As in repair, remove the unique clientid index before changing its column.
        $clientindex = new \xmldb_index('clientid', XMLDB_INDEX_UNIQUE, ['clientid']);
        foreach (self::TABLES as $tablename) {
            $table = new \xmldb_table($tablename);
            $clientid = new \xmldb_field('clientid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $hadindex = $dbman->index_exists($table, $clientindex);
            if ($hadindex) {
                $dbman->drop_index($table, $clientindex);
            }
            $dbman->change_field_precision($table, $clientid);
            if ($hadindex) {
                $dbman->add_index($table, $clientindex);
            }
        }

        $codetable = new \xmldb_table('local_coursepilot_oauth_code');
        $challengemethod = new \xmldb_field(
            'codechallengemethod',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            null,
            null,
            null,
            'codechallenge'
        );
        $dbman->add_field($codetable, $challengemethod);

        $tokentable = new \xmldb_table('local_coursepilot_oauth_token');
        $refreshtoken = new \xmldb_field('refreshtokenhash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $refreshindex = new \xmldb_index('refreshtokenhash', XMLDB_INDEX_UNIQUE, ['refreshtokenhash']);
        $dbman->drop_index($tokentable, $refreshindex);
        $dbman->change_field_notnull($tokentable, $refreshtoken);
        $dbman->add_index($tokentable, $refreshindex);
    }

    /**
     * Run Moodle’s schema checker on OAuth tables only.
     *
     * @return array<string, string[]>
     */
    private function schema_errors(): array {
        global $DB;

        $dbman = $DB->get_manager();
        $errors = $dbman->check_database_schema($dbman->get_install_xml_schema());

        return array_intersect_key($errors, array_flip(self::TABLES));
    }
}
