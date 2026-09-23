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
 * Die Laufzeit-Tiefenpruefung (Ticket #399, ADR 0017): dieselbe
 * maschinell pruefbare Logik wie die Repo-Vertragstests
 * (tests/catalog/*_contract_test.php), jetzt als wiederverwendbare Klasse,
 * die {@see \local_coursepilot\write_gate} zur Laufzeit einsetzt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(drift_check::class)]
final class drift_check_test extends \advanced_testcase {

    /**
     * Jede real registrierte Aktivitaetsart ist auf dieser Instanz gruen -
     * derselbe Vertrag, den die einzelnen *_catalog_contract_test.php-Dateien
     * bereits pruefen, hier fuer alle neun auf einmal ueber den
     * Laufzeit-Einstiegspunkt.
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
     * Unbekannte Aktivitaetsart -> ein Verstoss, kein leeres Ergebnis.
     */
    public function test_unknown_modname_is_reported_as_violation(): void {
        $violations = drift_check::check('unbekannteart');

        $this->assertNotEmpty($violations);
    }

    /**
     * Eine Katalogklasse, die eine nicht existierende Spalte behauptet, faellt
     * als Spaltendrift auf - der Fall, den ein Moodle-Upgrade ausloesen
     * wuerde (Spalte entfernt/umbenannt).
     */
    public function test_column_drift_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_column::class);

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('Spalten', $violations[0]);
    }

    /**
     * Eine Katalogklasse, die eine nicht existierende aufrufbare Quelle
     * referenziert, faellt auf.
     */
    public function test_missing_callable_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_callable::class);

        $this->assertNotEmpty($violations);
        $joined = implode(' ', $violations);
        $this->assertStringContainsString('nicht_existierende_funktion_xyz()', $joined);
    }

    /**
     * Eine Katalogklasse, die eine nicht existierende Konstante referenziert,
     * faellt auf.
     */
    public function test_missing_constant_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_constant::class);

        $this->assertNotEmpty($violations);
        $joined = implode(' ', $violations);
        $this->assertStringContainsString('NICHT_EXISTIERENDE_KONSTANTE_XYZ', $joined);
    }

    /**
     * Ein Feldzugriff in einer Schreiboption braucht dieselbe Katalogquelle
     * wie Schreiben und Lesen; ein Sonderfall darf kein eigenes Vokabular
     * einschmuggeln.
     */
    public function test_field_referenced_outside_the_catalog_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_write_field::class);

        $this->assertStringContainsString('am_katalog_vorbei', implode(' ', $violations));
    }

    /**
     * Auch ein Leseweg darf kein eigenes Feldvokabular einfuehren.
     */
    public function test_field_read_outside_the_catalog_is_detected(): void {
        $this->resetAfterTest();

        $violations = drift_check::check_catalog('label', drift_check_test_fake_catalog_with_bad_read_field::class);

        $this->assertStringContainsString('am_katalog_vorbei_gelesen', implode(' ', $violations));
    }

    /**
     * Jede Katalogklasse erklaert ihren Geltungsbereich pro Major-Version
     * (Abnahmekriterium #399) - eine positive Ganzzahl.
     */
    #[DataProvider('known_modname_provider')]
    public function test_every_catalog_declares_reviewed_up_to_major(string $modname): void {
        $catalogclass = registry::for($modname);
        $this->assertGreaterThanOrEqual(500, $catalogclass::reviewed_up_to_major());
    }
}

/**
 * Test-Doppelgaenger: behauptet eine Spalte, die "label" nicht hat.
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
    public static function schreibweg(): ?string {
        return null;
    }
    public static function checked_constants(): array {
        return [];
    }
    public static function reviewed_up_to_major(): int {
        return 500;
    }
}

/**
 * Test-Doppelgaenger: referenziert eine nicht existierende aufrufbare Quelle.
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
    public static function schreibweg(): ?string {
        return null;
    }
    public static function checked_constants(): array {
        return [];
    }
    public static function reviewed_up_to_major(): int {
        return 500;
    }
}

/**
 * Test-Doppelgaenger: referenziert eine nicht existierende Konstante.
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
    public static function schreibweg(): ?string {
        return null;
    }
    public static function checked_constants(): array {
        return ['NICHT_EXISTIERENDE_KONSTANTE_XYZ'];
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
