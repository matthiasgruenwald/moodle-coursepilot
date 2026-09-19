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
 * Schema-Drift-Reparatur (#424 Nachlauf 3).
 *
 * PHPUnit installiert immer frisch aus install.xml und sieht die Drift
 * hochgezogener Instanzen deshalb nie. Der Test stellt sie darum selbst her
 * (genau die fuenf Abweichungen aus admin/cli/check_database_schema.php),
 * laesst die Reparatur laufen und prueft gegen Moodles eigene
 * Schema-Pruefung.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class upgradelib_test extends \advanced_testcase {

    /** @var string[] Die von der Drift betroffenen Tabellen. */
    private const TABLES = [
        'local_coursepilot_oauth_client',
        'local_coursepilot_oauth_code',
        'local_coursepilot_oauth_token',
    ];

    /**
     * Nach der Reparatur meldet Moodles Schema-Pruefung fuer die
     * OAuth-Tabellen keine Abweichung mehr - und vorher meldet sie welche
     * (sonst pruefte der Test nichts).
     */
    public function test_repair_removes_oauth_schema_drift(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        try {
            $this->introduce_drift($dbman);

            $before = $this->schema_errors();
            $this->assertNotEmpty($before, 'Die kuenstliche Drift wurde von der Schema-Pruefung nicht gesehen.');
        } finally {
            // Die Reparatur laeuft auch bei fehlgeschlagener Zusicherung -
            // ein DDL-Eingriff wird von resetAfterTest() nicht zurueckgenommen
            // und wuerde sonst alle folgenden Tests des Laufs vergiften.
            local_coursepilot_repair_oauth_schema_drift($dbman);
        }

        $this->assertSame([], $this->schema_errors());
    }

    /**
     * Ein zweiter Lauf auf bereits sauberem Schema aendert nichts und wirft
     * nicht - der Upgrade-Schritt muss auf einer Neuinstallation genauso
     * laufen wie auf einer hochgezogenen Instanz.
     */
    public function test_repair_is_idempotent(): void {
        global $DB;

        $this->resetAfterTest();

        local_coursepilot_repair_oauth_schema_drift($DB->get_manager());
        local_coursepilot_repair_oauth_schema_drift($DB->get_manager());

        $this->assertSame([], $this->schema_errors());
    }

    /**
     * Eine Zeile ohne Refresh-Token laesst sich nicht auf NOT NULL ziehen -
     * sie wird entfernt statt mit einem Platzhalter gefuellt, der wie ein
     * gueltiges Token aussaehe.
     */
    public function test_repair_drops_token_rows_without_refresh_token(): void {
        global $DB;

        $this->resetAfterTest();
        $dbman = $DB->get_manager();

        try {
            $this->introduce_drift($dbman);

            $DB->insert_record('local_coursepilot_oauth_token', (object) [
                'accesstoken' => 'kaputt',
                'refreshtoken' => null,
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

        $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_token', ['accesstoken' => 'kaputt']));
        $this->assertSame([], $this->schema_errors());
    }

    /**
     * Stellt genau die Abweichungen her, die auf der Spike-Instanz gemessen
     * wurden: clientid auf 64 verkuerzt, codechallengemethod vorhanden,
     * refreshtoken nullable.
     *
     * @param \database_manager $dbman
     * @return void
     */
    private function introduce_drift(\database_manager $dbman): void {
        // Wie in der Reparatur: eine indizierte Spalte laesst Moodle nicht
        // aendern, der eindeutige Index auf clientid muss also weichen.
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
        $refreshtoken = new \xmldb_field('refreshtoken', XMLDB_TYPE_CHAR, '64', null, null, null, null);
        $refreshindex = new \xmldb_index('refreshtoken', XMLDB_INDEX_UNIQUE, ['refreshtoken']);
        $dbman->drop_index($tokentable, $refreshindex);
        $dbman->change_field_notnull($tokentable, $refreshtoken);
        $dbman->add_index($tokentable, $refreshindex);
    }

    /**
     * Moodles eigene Schema-Pruefung, eingegrenzt auf die OAuth-Tabellen.
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
