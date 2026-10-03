<?php
// Shared bootstrap for synthetic child processes on Moodle 5.0 and 5.1.
$moodleroot = dirname(__DIR__, 4);
$vendorroot = is_file($moodleroot . '/vendor/autoload.php') ? $moodleroot : dirname($moodleroot);
require_once($vendorroot . '/vendor/autoload.php');
define('PHPUNIT_UTIL', true);
require_once($moodleroot . '/lib/phpunit/bootstrap.php');
