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
namespace local_coursepilot\tests;

/**
 * Makes the database refuse a write, as a failing real storage would.
 *
 * MariaDB/MySQL only; other databases skip the test. The trigger is removed in every case.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait failing_database_write {
    /**
     * Runs a call while a write to a table fails inside the database.
     *
     * @param string $table Table name without prefix.
     * @param string $event INSERT or UPDATE.
     * @param string $condition SQL condition on NEW that selects the refused rows, e.g. "NEW.filename = 'x'"; "1" for all.
     * @param callable $call Call under test.
     * @return mixed What the call returned.
     */
    protected function with_failing_write(string $table, string $event, string $condition, callable $call): mixed {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $key = 'cp674f' . bin2hex(random_bytes(8));
        $ddl->query("CREATE TRIGGER $key BEFORE $event ON " . $DB->get_prefix() . "$table FOR EACH ROW
            BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic database failure'; END IF; END");
        try {
            return $call();
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }
    }
}
