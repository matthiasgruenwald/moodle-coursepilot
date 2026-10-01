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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Art-Tor (Spec 0026, ADR 0028): registry::kind und require_catalogued.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(registry::class)]
#[CoversClass(activity_kind::class)]
final class registry_test extends \advanced_testcase {

    public function test_catalogued_type_carries_its_catalog(): void {
        $kind = registry::kind('page');

        $this->assertSame(activity_kind::CATALOGUED, $kind->kind);
        $this->assertSame(page::class, $kind->catalog);
        $this->assertNull($kind->reasonkey);
    }

    public function test_quiz_with_a_catalog_stays_catalogued(): void {
        $this->assertSame(activity_kind::CATALOGUED, registry::kind('quiz')->kind);
    }

    public function test_installed_type_without_catalog_is_developed(): void {
        $kind = registry::kind('book');

        $this->assertSame(activity_kind::DEVELOPED, $kind->kind);
        $this->assertNull($kind->catalog);
        $this->assertNull($kind->reasonkey);
    }

    public static function excluded_provider(): array {
        return [
            'lesson' => ['lesson', 'kindexcludedquestions'],
            'scorm files' => ['scorm', 'kindexcludedfiles'],
            'imscp files' => ['imscp', 'kindexcludedfiles'],
            'no backup support' => ['nosuchmod', 'kindexcludednobackup'],
        ];
    }

    #[DataProvider('excluded_provider')]
    public function test_excluded_type_names_its_reason(string $modname, string $reasonkey): void {
        $kind = registry::kind($modname);

        $this->assertSame(activity_kind::EXCLUDED, $kind->kind);
        $this->assertSame($reasonkey, $kind->reasonkey);
        $this->assertNotSame("[[$reasonkey]]", get_string($reasonkey, 'local_coursepilot'));
    }

    public function test_require_catalogued_returns_the_catalog_class(): void {
        $this->assertSame(page::class, registry::require_catalogued('page'));
    }

    public function test_require_catalogued_rejects_everything_else_with_unknownmodname(): void {
        foreach (['book', 'lesson', 'nosuchmod'] as $modname) {
            try {
                registry::require_catalogued($modname);
                $this->fail($modname);
            } catch (\moodle_exception $e) {
                $this->assertSame('unknownmodname', $e->errorcode);
                $this->assertStringContainsString($modname, $e->getMessage());
            }
        }
    }
}
