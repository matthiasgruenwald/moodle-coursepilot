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
     * Die vier Aktivitaets-Schreibpfade pruefen Feldnamen ausschliesslich im
     * Katalog statt mit eigenen Literalen.
     */
    public function test_all_activity_field_validators_delegate_to_the_catalog(): void {
        $callers = [
            __DIR__ . '/../../classes/external/create_module.php',
            __DIR__ . '/../../classes/external/update_module_settings.php',
            __DIR__ . '/../../classes/catalog/quiz_write_bridge.php',
        ];

        foreach ($callers as $caller) {
            $source = file_get_contents($caller);
            $this->assertStringContainsString('catalog_fields::validate(', $source, $caller);
        }
    }

    /**
     * Der Fehlertext ist ein englischer Moodle-Sprachstring, kein Literal.
     */
    public function test_invalid_field_name_uses_the_english_language_string(): void {
        $this->assertSame(
            'Field names in felder_json must be strings. Nothing was written.',
            get_string('invalidfieldname', 'local_coursepilot')
        );
    }

    /**
     * Jede nicht-string Feldangabe liefert dieselbe katalogisierte Meldung.
     */
    public function test_invalid_field_names_always_get_the_same_message(): void {
        $messages = [];
        foreach ([0, true, null, []] as $fieldname) {
            try {
                field::assert_name($fieldname);
                $this->fail('Die nicht-string Feldangabe haette abgelehnt werden muessen.');
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidfieldname', $e->errorcode);
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(1, array_unique($messages));
    }
}
