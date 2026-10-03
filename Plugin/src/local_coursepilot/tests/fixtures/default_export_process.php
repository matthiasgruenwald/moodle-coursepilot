<?php
// Synthetic separate DB process for the successful default-export concurrency regression.
require_once(__DIR__ . '/phpunit_process_bootstrap.php');
\core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[2]], '*', MUST_EXIST));
echo json_encode(\local_coursepilot\external\export_default_activity::execute((int) $argv[1], 'book'));
