<?php
// Synthetic processes for the native history capture/cleanup concurrency regression.
require_once(__DIR__ . '/phpunit_process_bootstrap.php');
if ($argv[1] === 'capture') {
    \core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[3]], '*', MUST_EXIST));
    echo \local_coursepilot\history\version_writer::capture((int) $argv[2], (int) $argv[3]);
} else {
    \local_coursepilot\history\retention::enforce();
    echo 'cleaned';
}
