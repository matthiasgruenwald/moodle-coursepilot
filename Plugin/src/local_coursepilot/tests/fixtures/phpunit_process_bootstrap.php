<?php
// Shared bootstrap for synthetic child processes on Moodle 5.0 and 5.1.
// Moodle bootstrap may enable display_errors even when CLI requested stderr.
// Keep startup diagnostics on stderr so stdout remains the fixture's protocol.
ob_start();
try {
    $moodleroot = dirname(__DIR__, 4);
    $vendorroot = is_file($moodleroot . '/vendor/autoload.php') ? $moodleroot : dirname($moodleroot);
    require_once($vendorroot . '/vendor/autoload.php');
    define('PHPUNIT_UTIL', true);
    require_once($moodleroot . '/lib/phpunit/bootstrap.php');
} finally {
    fwrite(STDERR, ob_get_clean());
}
ini_set('display_errors', 'stderr');
