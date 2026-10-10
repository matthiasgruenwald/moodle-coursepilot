<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(field::class)]
final class field_test extends \advanced_testcase {
    /**
     * Field release, date, stealth and learner-lock rules are decided once in
     * the catalog write target (#646); the external adapters keep no second
     * interpretation of them.
     */
    public function test_all_activity_field_validators_delegate_to_the_catalog(): void {
        $core = file_get_contents(__DIR__ . '/../../classes/catalog/write_target.php');
        $this->assertStringContainsString('catalog_fields::validate(', $core);

        $adapters = [
            'classes/external/create_module.php' => 'write_target::create_activity(',
            'classes/external/update_module_settings.php' => 'write_target::update_activity(',
            'classes/external/create_quiz.php' => 'write_target::create(',
            'classes/external/update_quiz_settings.php' => 'write_target::update(',
            'classes/catalog/quiz_write_bridge.php' => null,
        ];
        foreach ($adapters as $file => $entry) {
            $source = file_get_contents(__DIR__ . '/../../' . $file);
            if ($entry !== null) {
                $this->assertStringContainsString($entry, $source, $file);
            }
            foreach (['catalog_fields::validate(', 'date_order_rules', 'allowstealth', 'learner_locks::find'] as $rule) {
                $this->assertStringNotContainsString($rule, $source, $file . ' interprets ' . $rule . ' itself.');
            }
        }
    }

    /**
     * Error text comes from an English Moodle language string.
     */
    public function test_invalid_field_name_uses_the_english_language_string(): void {
        $this->assertSame(
            'Field names in felder_json must be strings. Nothing was written.',
            get_string('invalidfieldname', 'local_coursepilot')
        );
    }

    /**
     * Every non-string field specification returns the same cataloged error.
     */
    public function test_invalid_field_names_always_get_the_same_message(): void {
        $messages = [];
        foreach ([0, true, null, []] as $fieldname) {
            try {
                field::assert_name($fieldname);
                $this->fail('The non-string field specification should have been rejected.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidfieldname', $e->errorcode);
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(1, array_unique($messages));
    }
}
