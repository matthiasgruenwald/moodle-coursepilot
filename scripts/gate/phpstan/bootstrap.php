<?php
// Registriert den Moodle-Autoloader (ohne Datenbankzugriff), damit PHPStan
// Moodle-Klassen und umbenannte Klassen aufloesen kann.
define('CLI_SCRIPT', true);
define('ABORT_AFTER_CONFIG', true);
require '/var/www/html/config.php';
// Konstanten (MINSECS, FORMAT_*, ...) und Funktionen der Kern-Bibliotheken.
foreach (['moodlelib', 'weblib', 'accesslib', 'datalib', 'filelib', 'dmllib', 'enrollib', 'grouplib', 'questionlib'] as $lib) {
    $file = $CFG->libdir . '/' . $lib . '.php';
    if (is_file($file)) {
        require_once($file);
    }
}
defined('SYSCONTEXTID') || define('SYSCONTEXTID', 1);
// Testbasisklassen (nur in der PHPUnit-Umgebung automatisch geladen).
require_once('/var/www/html/vendor/autoload.php');
foreach (['base_testcase', 'basic_testcase', 'advanced_testcase'] as $class) {
    require_once($CFG->libdir . '/phpunit/classes/' . $class . '.php');
}
