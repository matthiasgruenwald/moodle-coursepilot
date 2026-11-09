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

namespace local_coursepilot;

use core_external\external_api;
use local_coursepilot\external\ensure_question_bank;
use local_coursepilot\external\ensure_question_category;
use local_coursepilot\external\import_questions_xml;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Failure paths of the question import: nested transactions and an empty reparse (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(question_import::class)]
final class question_import_673_contract_test extends \advanced_testcase {
    /**
     * A failed import inside the caller's transaction rolls back only its own level.
     *
     * The outer transaction stays open and marked for rollback, so the caller decides what happens next.
     */
    public function test_a_failed_import_inside_a_caller_transaction_leaves_that_transaction_open(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = external_api::clean_returnvalue(
            ensure_question_bank::execute_returns(),
            ensure_question_bank::execute($course->id, 'Nested')
        );
        $category = external_api::clean_returnvalue(
            ensure_question_category::execute_returns(),
            ensure_question_category::execute('Import', (int) $bank['topcategoryid'])
        );
        $categoryid = (int) $category['id'];
        import_questions_xml::execute($categoryid, self::xml('Saved name', 'stable-id'), true);

        $outer = $DB->start_delegated_transaction();
        try {
            import_questions_xml::execute($categoryid, self::xml('', 'stable-id'), true);
            $this->fail('Expected a round-trip failure.');
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
        }
        $stillopen = $DB->is_transaction_started();
        try {
            $outer->rollback(new \Exception('cleanup'));
        } catch (\Exception $cleanup) {
            $this->assertSame('cleanup', $cleanup->getMessage());
        }

        $this->assertTrue($stillopen);
    }

    /**
     * A quiz without any question element reparses to nothing and is reported as a round-trip parse failure.
     */
    public function test_a_reparse_without_any_question_is_a_round_trip_parse_failure(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $context = \context::instance_by_id($category->contextid);
        // Reflection: reparse() only gets an empty result from hand-made XML, the public import cannot produce one.
        $reparse = new \ReflectionMethod(question_import::class, 'reparse');
        // The core reader warns about the missing question element before it returns nothing.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $reparse->invoke(null, $category, $context, '<?xml version="1.0" encoding="UTF-8"?><quiz><foo/></quiz>');
            $this->fail('Expected a moodle_exception.');
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertSame('parse', $error->a->field);
            $this->assertSame(' (no question in the reparsed XML)', $error->a->detail);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * One native multichoice question with distinct answers and feedback.
     *
     * @param string $name Question name.
     * @param string $idnumber Question idnumber.
     * @return string
     */
    private static function xml(string $name, string $idnumber = ''): string {
        return '<quiz><question type="multichoice"><name><text>' . $name . '</text></name>'
            . '<idnumber>' . $idnumber . '</idnumber><questiontext format="html"><text>Choose four.</text></questiontext>'
            . '<generalfeedback format="html"><text>Compare the numbers.</text></generalfeedback><single>true</single>'
            . '<answer fraction="100"><text>4</text><feedback><text>Correct.</text></feedback></answer>'
            . '<answer fraction="0"><text>5</text><feedback><text>Too high.</text></feedback></answer>'
            . '</question></quiz>';
    }
}
