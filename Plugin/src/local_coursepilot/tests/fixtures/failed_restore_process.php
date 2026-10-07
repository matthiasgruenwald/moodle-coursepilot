<?php
// Synthetic separate DB process for the restore concurrency regression.
require_once(__DIR__ . '/phpunit_process_bootstrap.php');
\core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[2]], '*', MUST_EXIST));
try {
    \local_coursepilot\activity_backup::restore((int) $argv[1], 1, file_get_contents($argv[3]));
    fwrite(STDERR, 'Expected restore failure');
    exit(1);
} catch (\Throwable $e) {
    echo $e->getMessage();
}
