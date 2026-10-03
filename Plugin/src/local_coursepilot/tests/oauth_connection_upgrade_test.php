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

/** Bounded, resumable native migration of synthetic legacy pairs and tickets. */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class oauth_connection_upgrade_test extends \advanced_testcase {
    public function test_interrupted_backfill_resumes_without_guessing_historical_families(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/coursepilot/db/upgradelib.php');
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        $user = $this->getDataGenerator()->create_user();
        $ids = [];
        for ($i = 0; $i < 101; $i++) {
            $ids[] = $DB->insert_record('local_coursepilot_oauth_token', (object) [
                'accesstokenhash' => hash('sha256', 'access638-' . $i),
                'refreshtokenhash' => hash('sha256', 'refresh638-' . $i),
                'userid' => $user->id, 'clientid' => 'legacy', 'expires' => time() + 3600,
                'refreshexpires' => time() + 86400, 'revoked' => 0, 'timecreated' => time() - 60,
            ]);
        }
        $unknown = $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'accesstokenhash' => hash('sha256', 'historical-access'),
            'refreshtokenhash' => hash('sha256', 'overwritten-historical-refresh'),
            'userid' => $user->id, 'clientid' => 'legacy', 'expires' => time() + 3600,
            'refreshexpires' => time() + 86400, 'revoked' => 1, 'timecreated' => time() - 60,
        ]);
        $expires = time() + 400;
        $ticket = $DB->insert_record(workbench_ticket::TABLE, (object) [
            'userid' => $user->id, 'path' => 'synthetic.txt', 'contenthash' => sha1('synthetic'),
            'tickethash' => hash('sha256', 'synthetic-ticket'), 'oauthtokenid' => $ids[0],
            'expires' => $expires, 'timecreated' => time() - 60,
        ]);
        $trigger = 'cp638' . bin2hex(random_bytes(8));
        $table = $DB->get_prefix() . 'local_coursepilot_oauth_token';
        $cfg = $DB->export_dbconfig();
        // Moodle's execute() rejects trigger-body semicolons; use the native DDL connection.
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli($cfg->dbhost, $cfg->dbuser, $cfg->dbpass, $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null);
        $ddl->query("CREATE TRIGGER $trigger BEFORE UPDATE ON $table FOR EACH ROW BEGIN
            IF NEW.id = $ids[100] AND NEW.connectionid IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic interrupted migration';
            END IF;
        END");
        try {
            try {
                local_coursepilot_migrate_oauth_connections($DB->get_manager());
                $this->fail('The injected migration interruption must fail.');
            } catch (\dml_exception $e) {
                $this->assertStringContainsString('Synthetic interrupted migration', $e->debuginfo);
            }
            $this->assertSame(100, $DB->count_records('local_coursepilot_oauth_grant'));
            $this->assertSame(100, $DB->count_records_select('local_coursepilot_oauth_token', 'connectionid IS NOT NULL'));
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $trigger");
            $ddl->close();
        }
        local_coursepilot_migrate_oauth_connections($DB->get_manager());
        local_coursepilot_migrate_oauth_connections($DB->get_manager());
        $this->assertSame(101, $DB->count_records('local_coursepilot_oauth_grant'));
        $this->assertNull($DB->get_field('local_coursepilot_oauth_token', 'connectionid', ['id' => $unknown]));
        $ticketrecord = $DB->get_record(workbench_ticket::TABLE, ['id' => $ticket], '*', MUST_EXIST);
        $this->assertSame($expires, (int) $ticketrecord->expires);
        $this->assertSame((int) $DB->get_field('local_coursepilot_oauth_token', 'connectionid', ['id' => $ids[0]]),
            (int) $ticketrecord->oauthconnectionid);
        $this->assertSame((int) $user->id, oauth_lib::authenticate_access_token('access638-0'));
        $this->assertNotNull(oauth_lib::rotate_refresh_token('refresh638-0', 'legacy'));
        $errors = $DB->get_manager()->check_database_schema($DB->get_manager()->get_install_xml_schema());
        $this->assertSame([], array_intersect_key($errors, array_flip([
            'local_coursepilot_oauth_grant', 'local_coursepilot_oauth_token', workbench_ticket::TABLE,
        ])));
    }
}
