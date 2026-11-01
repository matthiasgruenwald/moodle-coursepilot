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
 * Runs the tests of a class in child interpreters with their own PHP configuration.
 *
 * PHP cannot change upload limits or disabled functions with ini_set(), so the child interpreter gets an
 * extra configuration directory. The using class declares the settings and RunTestsInSeparateProcesses.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait isolated_php_ini {
    /**
     * Lines of the extra configuration file of the child interpreters, e.g. the upload limits.
     *
     * @return string
     */
    abstract protected static function ini_settings(): string;

    /** @var string|false Original interpreter configuration search path. */
    private static string|false $previousscan = false;

    /** @var string Only this class's temporary interpreter configuration directory. */
    private static string $inidirectory = '';

    /**
     * Configures the child interpreters.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$previousscan = getenv('PHP_INI_SCAN_DIR');
        self::$inidirectory = sys_get_temp_dir() . '/coursepilot-upload-limit-' . uniqid();
        if (!mkdir(self::$inidirectory, 0700)) {
            throw new \RuntimeException('Could not create the upload-limit interpreter configuration directory.');
        }
        if (file_put_contents(self::$inidirectory . '/limit.ini', static::ini_settings()) === false) {
            throw new \RuntimeException('Could not write the upload-limit interpreter configuration.');
        }
        $previous = self::$previousscan === false ? '' : self::$previousscan;
        putenv('PHP_INI_SCAN_DIR=' . $previous . PATH_SEPARATOR . self::$inidirectory);
    }

    /**
     * Restores the parent interpreter's environment and removes only this class's fixture.
     */
    public static function tearDownAfterClass(): void {
        putenv(self::$previousscan === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR=' . self::$previousscan);
        if (self::$inidirectory !== '' && is_dir(self::$inidirectory)) {
            if (is_file(self::$inidirectory . '/limit.ini')) {
                unlink(self::$inidirectory . '/limit.ini');
            }
            rmdir(self::$inidirectory);
        }
        parent::tearDownAfterClass();
    }
}
