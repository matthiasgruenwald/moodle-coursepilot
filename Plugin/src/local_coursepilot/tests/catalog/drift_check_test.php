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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runtime deep checks (#399, ADR 0017) reuse the catalog contract-test
 * logic through the class used by {@see \local_coursepilot\write_gate}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(drift_check::class)]
#[CoversClass(\local_coursepilot\catalog\field::class)]
final class drift_check_test extends \advanced_testcase {
    /**
     * All nine registered types satisfy the same contracts already tested
     * individually, here through the runtime entry point.
     */
    #[DataProvider('known_modname_provider')]
    public function test_every_registered_catalog_is_currently_drift_free(string $modname): void {
        $this->resetAfterTest();

        $this->assertSame([], drift_check::check($modname), "$modname sollte auf dieser Instanz driftfrei sein.");
    }

    /**
     * @return array<string, string[]>
     */
    public static function known_modname_provider(): array {
        return array_combine(registry::known_modnames(), array_map(
            static fn (string $modname): array => [$modname],
            registry::known_modnames()
        ));
    }

    /**
     * Unknown activity types produce a violation, not an empty result.
     */
    public function test_unknown_modname_is_reported_as_violation(): void {
        $violations = drift_check::check('unbekannteart');

        $this->assertNotEmpty($violations);
    }

    /**
     * Detect catalogs naming nonexistent columns, as after an upgrade
     * removes or renames columns.
     */
    public function test_column_drift_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_column::class);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('Columns', $violations[0]);
    }

    /**
     * Detect nonexistent callable sources.
     */
    public function test_missing_callable_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_callable::class);

        $this->assertNotEmpty($violations);
        $joined = implode(' ', $violations);
        $this->assertStringContainsString('nicht_existierende_funktion_xyz()', $joined);
    }

    /**
     * Detect nonexistent constant sources.
     */
    public function test_missing_constant_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_constant::class);

        $this->assertNotEmpty($violations);
        $joined = implode(' ', $violations);
        $this->assertStringContainsString('NICHT_EXISTIERENDE_KONSTANTE_XYZ', $joined);
    }

    /**
     * Write options must use catalog sources for field access, not
     * introduce special-case vocabulary.
     */
    public function test_field_referenced_outside_the_catalog_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_write_field::class);

        $this->assertStringContainsString('am_katalog_vorbei', implode(' ', $violations));
    }

    /**
     * Read paths must not introduce separate field vocabulary either.
     */
    public function test_field_read_outside_the_catalog_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_read_field::class);

        $this->assertStringContainsString('am_katalog_vorbei_gelesen', implode(' ', $violations));
    }

    /**
     * Every catalog declares its major-version scope as a positive integer (#399).
     */
    #[DataProvider('known_modname_provider')]
    public function test_every_catalog_declares_reviewed_up_to_major(string $modname): void {
        $catalogclass = registry::for($modname);
        $this->assertGreaterThanOrEqual(500, $catalogclass::reviewed_up_to_major());
    }
}

/**
 * Test double claiming a column absent from label.
 */
final class drift_check_test_fake_catalog_with_bad_column implements module_catalog {
    public static function modname(): string {
        return 'label';
    }
    public static function fields(): array {
        return [
            new field('nichtexistierendespalte', 'PARAM_RAW', 'x', false, null, null, null, 'test'),
        ];
    }
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return [];
    }
    public static function write_options(): array {
        return [];
    }
    public static function common_field_names(): array {
        return [];
    }
    public static function pseudofields(): array {
        return [];
    }
    public static function blocklist(): array {
        return ['name'];
    }
    public static function combination_rules(): array {
        return [];
    }
    public static function side_effects(): array {
        return [];
    }
    public static function bundles(): array {
        return [];
    }
    public static function write_route(): ?string {
        return null;
    }
    public static function checked_constants(): array {
        return [];
    }
    public static function learner_locks(): array {
        return [];
    }
    public static function grade_origin(int $instanceid = 0): string {
        return learner_locks::GRADE_NONE;
    }
    public static function reviewed_up_to_major(): int {
        return 500;
    }
}

/**
 * Test double referencing a nonexistent callable source.
 */
final class drift_check_test_fake_catalog_with_bad_callable implements module_catalog {
    public static function modname(): string {
        return 'label';
    }
    public static function fields(): array {
        return [
            new field('intro', 'PARAM_RAW', 'x', true, null, null, null, 'test'),
            new field(
                'introformat',
                'PARAM_INT',
                'x',
                false,
                0,
                null,
                'nicht_existierende_funktion_xyz()',
                'test'
            ),
        ];
    }
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return [];
    }
    public static function write_options(): array {
        return [];
    }
    public static function common_field_names(): array {
        return [];
    }
    public static function pseudofields(): array {
        return [];
    }
    public static function blocklist(): array {
        return ['name'];
    }
    public static function combination_rules(): array {
        return [];
    }
    public static function side_effects(): array {
        return [];
    }
    public static function bundles(): array {
        return [];
    }
    public static function write_route(): ?string {
        return null;
    }
    public static function checked_constants(): array {
        return [];
    }
    public static function learner_locks(): array {
        return [];
    }
    public static function grade_origin(int $instanceid = 0): string {
        return learner_locks::GRADE_NONE;
    }
    public static function reviewed_up_to_major(): int {
        return 500;
    }
}

/**
 * Test double referencing a nonexistent constant.
 */
class drift_check_test_fake_catalog_with_bad_constant implements module_catalog {
    public static function modname(): string {
        return 'label';
    }
    public static function fields(): array {
        return [
            new field('intro', 'PARAM_RAW', 'x', true, null, null, null, 'test'),
            new field('introformat', 'PARAM_INT', 'x', false, 0, null, null, 'test'),
        ];
    }
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return [];
    }
    public static function write_options(): array {
        return [];
    }
    public static function common_field_names(): array {
        return [];
    }
    public static function pseudofields(): array {
        return [];
    }
    public static function blocklist(): array {
        return ['name'];
    }
    public static function combination_rules(): array {
        return [];
    }
    public static function side_effects(): array {
        return [];
    }
    public static function bundles(): array {
        return [];
    }
    public static function write_route(): ?string {
        return null;
    }
    public static function checked_constants(): array {
        return ['NICHT_EXISTIERENDE_KONSTANTE_XYZ'];
    }
    public static function learner_locks(): array {
        return [];
    }
    public static function grade_origin(int $instanceid = 0): string {
        return learner_locks::GRADE_NONE;
    }
    public static function reviewed_up_to_major(): int {
        return 500;
    }
}

final class drift_check_test_fake_catalog_with_bad_write_field extends drift_check_test_fake_catalog_with_bad_constant {
    public static function write_options(): array {
        return ['material_reference_fields' => ['am_katalog_vorbei' => []]];
    }
}

final class drift_check_test_fake_catalog_with_bad_read_field extends drift_check_test_fake_catalog_with_bad_constant {
    public static function write_options(): array {
        return ['read_fields' => ['am_katalog_vorbei_gelesen']];
    }
}
