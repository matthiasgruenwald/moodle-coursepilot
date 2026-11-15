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

namespace local_coursepilot\external;

use local_coursepilot\question_import;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The XML resource ceiling also applies when the interpreter imposes no upload limit.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(question_import::class)]
#[CoversClass(import_questions_xml::class)]
#[RunTestsInSeparateProcesses]
final class question_import_upload_limit_test extends \advanced_testcase {
    /** @var string|false Original interpreter configuration search path. */
    private static string|false $previousscan = false;

    /** @var string Only this test's temporary interpreter configuration directory. */
    private static string $inidirectory = '';

    /**
     * Configure the real child interpreter; PHP cannot change upload limits with ini_set().
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$previousscan = getenv('PHP_INI_SCAN_DIR');
        self::$inidirectory = sys_get_temp_dir() . '/coursepilot-upload-limit-' . uniqid();
        if (!mkdir(self::$inidirectory, 0700)) {
            throw new \RuntimeException('Could not create the upload-limit interpreter configuration directory.');
        }
        if (file_put_contents(self::$inidirectory . '/limit.ini', "upload_max_filesize=0M\npost_max_size=0M\n") === false) {
            throw new \RuntimeException('Could not write the upload-limit interpreter configuration.');
        }
        $previous = self::$previousscan === false ? '' : self::$previousscan;
        putenv('PHP_INI_SCAN_DIR=' . $previous . PATH_SEPARATOR . self::$inidirectory);
    }

    /**
     * Restore the parent interpreter's environment and remove only this class's fixture.
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

    /**
     * Unlimited PHP uploads never authorize importing a question above the plugin's own byte ceiling.
     */
    public function test_unlimited_server_uploads_still_refuse_oversized_question_xml(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame(0, get_max_upload_file_size(), 'The isolated interpreter must have unlimited uploads.');
        $course = $this->getDataGenerator()->create_course();
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => \context_module::instance($bank->cmid)->id]);
        $xml = '<quiz><!--' . str_repeat('x', 2 * question_import::MAX_XML_BYTES) . '-->'
            . '<question type="description"><name><text>Oversized question</text></name>'
            . '<questiontext format="plain_text"><text>Keep this question outside the bank.</text></questiontext>'
            . '</question></quiz>';
        try {
            import_questions_xml::execute((int) $category->id, $xml);
            $this->fail('The oversized XML must not create a question even with unlimited server uploads.');
        } catch (\invalid_parameter_exception $error) {
            $this->assertStringStartsWith('The XML is too large (', $error->debuginfo);
            $this->assertStringContainsString('limit 5.0 MB', $error->debuginfo);
        }
        $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $category->id]));
    }
}
