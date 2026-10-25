<?php
// Infection reflects Moodle classes in its own process before creating mutants.
// Utility bootstrap loads classes without holding the test lock needed by its children.
define('PHPUNIT_UTIL', true);
require_once('/var/www/html/vendor/autoload.php');
require_once('/var/www/html/public/lib/phpunit/bootstrap.php');
