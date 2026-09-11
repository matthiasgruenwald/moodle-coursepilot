<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Kontextpointer, zweite Fassung (Issue #490, Spec #486 §2) - reine
 * Werteumformung ohne Datei-/Netzzugriff: erste Fassung weiterhin als
 * *in Moodle*, zweite Fassung je Ziel *in Moodle* oder *extern*,
 * Vollstaendigkeits- und Segmentpruefung.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(context_pointer::class)]
final class context_pointer_test extends \advanced_testcase {

    public function test_legacy_pointer_resolves_both_fields_as_moodle(): void {
        $decoded = ['kontextbereich' => 'custom-context', 'materialordner' => 'custom-material'];

        $kontext = context_pointer::resolve_target($decoded, 'kontextbereich');
        $this->assertSame(pointer_location::MOODLE, $kontext->kind);
        $this->assertSame('/custom-context/', $kontext->path);

        $material = context_pointer::resolve_target($decoded, 'materialordner');
        $this->assertSame(pointer_location::MOODLE, $material->kind);
        $this->assertSame('/custom-material/', $material->path);
    }

    public function test_legacy_pointer_missing_field_is_incomplete(): void {
        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target(['kontextbereich' => 'custom-context'], 'kontextbereich');
    }

    public function test_v2_pointer_resolves_moodle_target(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'mein-kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'kontextbereich');
        $this->assertSame(pointer_location::MOODLE, $location->kind);
        $this->assertSame('/mein-kontext/', $location->path);
    }

    public function test_v2_pointer_resolves_extern_target_for_context(): void {
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => 3,
                'pfad' => 'Unterricht/Kontext',
                'pruefmerkmal' => ['server' => 'cloud.example.test', 'basispfad' => 'Kurspilot', 'konto' => 'lehrerin'],
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'kontextbereich');

        $this->assertSame(pointer_location::EXTERN, $location->kind);
        $this->assertSame(3, $location->instanceid);
        $this->assertSame('Unterricht/Kontext', $location->relativepath);
        $this->assertSame(
            ['server' => 'cloud.example.test', 'basispfad' => 'Kurspilot', 'konto' => 'lehrerin'],
            $location->fingerprint
        );
    }

    /**
     * Materialbestand loest den frueheren Begriff "materialordner" ab (Spec
     * §2) - der Bereich reicht seinen historischen Pointer-Schluessel
     * unveraendert durch, die Zuordnung auf das neue Feld passiert hier.
     */
    public function test_v2_pointer_maps_material_pointerkey_to_materialbestand_field(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'mein-kontext'],
            'materialbestand' => [
                'ort' => 'extern',
                'instanzid' => 7,
                'pfad' => 'Faecher',
                'pruefmerkmal' => ['server' => 'nc.example.test', 'basispfad' => 'Material', 'konto' => 'lehrerin'],
            ],
        ];

        $location = context_pointer::resolve_target($decoded, 'materialordner');

        $this->assertSame(pointer_location::EXTERN, $location->kind);
        $this->assertSame(7, $location->instanceid);
        $this->assertSame('Faecher', $location->relativepath);
    }

    public function test_v2_pointer_missing_target_is_incomplete(): void {
        $decoded = ['kontextbereich' => ['ort' => 'moodle', 'pfad' => 'mein-kontext']];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'kontextbereich');
    }

    public function test_v2_pointer_unknown_ort_value_is_incomplete(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'irgendwo', 'pfad' => 'x'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'kontextbereich');
    }

    public function test_v2_pointer_extern_target_missing_fingerprint_is_incomplete(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'extern', 'instanzid' => 3, 'pfad' => 'Kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'kontextbereich');
    }

    public function test_v2_pointer_extern_target_rejects_traversal_segment(): void {
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => 3,
                'pfad' => '../etc',
                'pruefmerkmal' => ['server' => 's', 'basispfad' => 'b', 'konto' => 'k'],
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'kontextbereich');
    }

    /**
     * Aufloesungspruefung 7 (Issue #495, Spec #486 §2): der Materialbestand
     * darf nie im Kontextbereich liegen - hier ein Moodle-Unterordner des
     * Kontextbereichs.
     */
    public function test_material_inside_context_is_rejected(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot/material'],
        ];

        try {
            context_pointer::resolve_target($decoded, 'materialordner');
            $this->fail('materialbestandimkontext haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialbestandimkontext', $e->errorcode);
        }
    }

    /**
     * Auflosungspruefung 7 gilt auch fuer denselben Ordner.
     */
    public function test_material_in_the_same_folder_as_context_is_rejected(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'geteilt'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'geteilt'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'materialordner');
    }

    /**
     * Die umgekehrte Richtung ist erlaubt: der Kontextbereich darf im
     * Materialbestand liegen (CONTEXT.md "Materialbestand").
     */
    public function test_context_inside_material_is_allowed(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'material/kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'materialordner');

        $this->assertSame('/material/', $location->path);
    }

    /**
     * Auflosungspruefung 7 ist ortsunabhaengig: ein Moodle-Kontextbereich und
     * ein extern liegender Materialbestand ueberschneiden sich nie - anderer
     * Ort, anderer Vergleichsschluessel.
     */
    public function test_external_material_never_overlaps_a_moodle_context(): void {
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot'],
            'materialbestand' => [
                'ort' => 'extern',
                'instanzid' => 3,
                'pfad' => 'kurspilot',
                'pruefmerkmal' => ['server' => 's', 'basispfad' => 'b', 'konto' => 'k'],
            ],
        ];

        $location = context_pointer::resolve_target($decoded, 'materialordner');

        $this->assertSame(pointer_location::EXTERN, $location->kind);
    }

    public function test_v2_pointer_extern_target_rejects_zero_instance_id(): void {
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => 0,
                'pfad' => 'Kontext',
                'pruefmerkmal' => ['server' => 's', 'basispfad' => 'b', 'konto' => 'k'],
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'kontextbereich');
    }
}
