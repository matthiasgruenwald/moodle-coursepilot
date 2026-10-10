<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
// Synthetic separate DB process for the successful default-export concurrency regression.

require_once(__DIR__ . '/phpunit_process_bootstrap.php');
\core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[2]], '*', MUST_EXIST));
echo json_encode(\local_coursepilot\external\export_default_activity::execute((int) $argv[1], 'book'));
