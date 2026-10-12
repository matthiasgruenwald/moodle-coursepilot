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

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Exercises Moodle's real upgrade entry, schema transitions and savepoints.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversFunction('xmldb_local_coursepilot_upgrade')]
final class upgrade_test extends \advanced_testcase {
    public function test_upgrade_from_pre_oauth_install_matches_the_current_install_schema(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/coursepilot/db/upgrade.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $file = new \xmldb_file($CFG->dirroot . '/local/coursepilot/db/install.xml');
        $file->loadXMLStructure();
        $tables = $file->getStructure()->getTables();
        try {
            foreach ($tables as $table) {
                $dbman->drop_table($table);
            }
            set_config('version', 0, 'local_coursepilot');
            $this->expectOutputRegex('/context file\(s\) copied to Private Files/');
            $this->assertTrue(\xmldb_local_coursepilot_upgrade(0));
            $this->assertSame('2026100344', get_config('local_coursepilot', 'version'));
            $errors = $dbman->check_database_schema($dbman->get_install_xml_schema());
            $names = array_map(static fn(\xmldb_table $table): string => $table->getName(), $tables);
            $this->assertSame([], array_intersect_key($errors, array_flip($names)));
            $this->assertFalse($dbman->table_exists(new \xmldb_table('local_coursepilot_werkbank_ticket')));
        } finally {
            // DDL is not rolled back by resetAfterTest; always restore the current schema.
            foreach (array_merge($tables, [new \xmldb_table('local_coursepilot_werkbank_ticket')]) as $table) {
                if ($dbman->table_exists($table)) {
                    $dbman->drop_table($table);
                }
            }
            $dbman->install_from_xmldb_file($CFG->dirroot . '/local/coursepilot/db/install.xml');
        }
    }
    public function test_late_upgrade_keeps_existing_connections_and_recreates_the_budget_table_once(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/coursepilot/db/upgrade.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = connection::issue_code('retained-client', (int) $USER->id, 'https://client.example/cb', $challenge);
        $tokens = connection::exchange_code($code, 'retained-client', 'https://client.example/cb', $verifier);
        $this->assertSame((int) $USER->id, connection::authenticate_access_token($tokens['access_token']));
        $dbman = $DB->get_manager();
        $file = new \xmldb_file($CFG->dirroot . '/local/coursepilot/db/install.xml');
        $file->loadXMLStructure();
        $budgettable = $file->getStructure()->getTable('local_coursepilot_oauth_budget');
        $dbman->drop_table($budgettable);
        set_config('version', 2026100339, 'local_coursepilot');
        try {
            $this->assertTrue(\xmldb_local_coursepilot_upgrade(2026100339));
            $this->assertTrue($dbman->table_exists($budgettable));
            $this->assertSame((int) $USER->id, connection::authenticate_access_token($tokens['access_token']));
            $rotated = connection::rotate_refresh_token($tokens['refresh_token'], 'retained-client');
            $this->assertNotNull($rotated);
            $this->assertTrue(\xmldb_local_coursepilot_upgrade(2026100344));
            $this->assertSame('2026100344', get_config('local_coursepilot', 'version'));
            $this->assertSame((int) $USER->id, connection::authenticate_access_token($rotated['access_token']));
        } finally {
            if (!$dbman->table_exists($budgettable)) {
                $dbman->create_table($budgettable);
            }
        }
    }
}
