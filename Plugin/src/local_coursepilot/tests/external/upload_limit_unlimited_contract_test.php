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

use local_coursepilot\glossary_entry_writer;
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\isolated_php_ini;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Without any upload limit a glossary file of any size below the plugin ceiling is accepted (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(glossary_entry_writer::class)]
#[RunTestsInSeparateProcesses]
final class upload_limit_unlimited_contract_test extends \advanced_testcase {
    use caught_throwable;
    use isolated_php_ini;

    /**
     * Upload limits of the child interpreters.
     *
     * @return string
     */
    protected static function ini_settings(): string {
        return "upload_max_filesize=0M\npost_max_size=0M\n";
    }

    public function test_a_glossary_attachment_is_accepted_when_no_limit_applies(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame(0, get_max_upload_file_size(), 'The isolated interpreter must have unlimited uploads.');
        $course = $this->getDataGenerator()->create_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);
        upload_material_file::execute('worksheet.pdf', base64_encode('payload'));
        $cm = get_coursemodule_from_id('glossary', $glossary->cmid, 0, false, MUST_EXIST);
        global $DB;
        $params = \core_external\external_api::validate_parameters(add_glossary_entries::execute_parameters(), [
            'cmid' => $cm->id,
            'entries' => [['concept' => 'Atom', 'definition' => 'Text', 'attachment_files' => ['worksheet.pdf']]],
        ]);

        $result = $this->succeeded(fn() => glossary_entry_writer::add(
            $params['entries'][0],
            get_course($cm->course),
            $cm,
            $DB->get_record('glossary', ['id' => $cm->instance], '*', MUST_EXIST),
            \context_module::instance($cm->id)
        ));

        $this->assertTrue($result['success'], json_encode($result));
    }
}
