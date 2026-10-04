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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Context-pointer v2 value transformations without file or network I/O
 * (#490, Spec #486 §2). v1 remains in Moodle; v2 selects Moodle or
 * external storage per target, with completeness and segment checks.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_pointer::class)]
#[CoversClass(\local_coursepilot\pointer_location::class)]
final class context_pointer_test extends \advanced_testcase {

    public function test_legacy_pointer_resolves_both_fields_as_moodle(): void {
        $decoded = ['kontextbereich' => 'custom-context', 'materialordner' => 'custom-material'];

        $kontext = context_pointer::resolve_target($decoded, 'context_area');
        $this->assertSame(pointer_location::MOODLE, $kontext->kind);
        $this->assertSame('/custom-context/', $kontext->path);

        $material = context_pointer::resolve_target($decoded, 'material_store');
        $this->assertSame(pointer_location::MOODLE, $material->kind);
        $this->assertSame('/custom-material/', $material->path);
    }

    /**
     * Legacy German pointer keys and values remain readable (#602, ADR 0024).
     */
    public function test_german_v2_pointer_is_normalised_and_resolves(): void {
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => 3,
                'pfad' => 'Kontext',
                'pruefmerkmal' => ['server' => 'cloud.example.test', 'basispfad' => 'dav', 'konto' => 'lea'],
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
            'vorheriger_ort' => ['ort' => 'moodle', 'pfad' => 'alt'],
        ];

        $location = context_pointer::resolve_target($decoded, 'context_area');
        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
        $this->assertSame('lea', $location->fingerprint['account']);
        $this->assertSame('/mein-material/', context_pointer::resolve_target($decoded, 'material_store')->path);
        $this->assertSame(['location' => 'moodle', 'path' => 'alt'], context_pointer::normalise($decoded)['previous_location']);
    }

    public function test_legacy_pointer_missing_field_is_incomplete(): void {
        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target(['context_area' => 'custom-context'], 'context_area');
    }

    /**
     * validate_path() directly rejects empty folder names (#509), formerly
     * checked indirectly by storage_anchor::write_pointer() before its removal.
     */
    public function test_validate_path_rejects_empty_value(): void {
        $this->expectException(\moodle_exception::class);
        context_pointer::validate_path('');
    }

    public function test_v2_pointer_resolves_moodle_target(): void {
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'mein-kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'context_area');
        $this->assertSame(pointer_location::MOODLE, $location->kind);
        $this->assertSame('/mein-kontext/', $location->path);
    }

    public function test_v2_pointer_resolves_extern_target_for_context(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 3,
                'path' => 'Unterricht/Kontext',
                'fingerprint' => ['server' => 'cloud.example.test', 'basepath' => 'Coursepilot', 'account' => 'lehrerin'],
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'context_area');

        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
        $this->assertSame(3, $location->instanceid);
        $this->assertSame('Unterricht/Kontext', $location->relativepath);
        $this->assertSame(
            ['server' => 'cloud.example.test', 'basepath' => 'Coursepilot', 'account' => 'lehrerin', 'iserv' => false],
            $location->fingerprint
        );
    }

    /**
     * Inventory replaces the old material-folder term (Spec §2). The area
     * passes through its historical pointer key; map it to the new field here.
     */
    public function test_v2_pointer_resolves_material_store_target(): void {
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'mein-kontext'],
            'material_store' => [
                'location' => 'external',
                'instanceid' => 7,
                'path' => 'Faecher',
                'fingerprint' => ['server' => 'nc.example.test', 'basepath' => 'Material', 'account' => 'lehrerin'],
            ],
        ];

        $location = context_pointer::resolve_target($decoded, 'material_store');

        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
        $this->assertSame(7, $location->instanceid);
        $this->assertSame('Faecher', $location->relativepath);
    }

    public function test_v2_pointer_missing_target_is_incomplete(): void {
        $decoded = ['context_area' => ['location' => 'moodle', 'path' => 'mein-kontext']];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'context_area');
    }

    public function test_v2_pointer_unknown_ort_value_is_incomplete(): void {
        $decoded = [
            'context_area' => ['location' => 'irgendwo', 'path' => 'x'],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'context_area');
    }

    public function test_v2_pointer_extern_target_missing_fingerprint_is_incomplete(): void {
        $decoded = [
            'context_area' => ['location' => 'external', 'instanceid' => 3, 'path' => 'Kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'context_area');
    }

    public function test_v2_pointer_extern_target_rejects_traversal_segment(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 3,
                'path' => '../etc',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k'],
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'context_area');
    }

    /**
     * Resolution check 7: inventory must not lie below the context area
     * (#495, Spec #486 §2); test a Moodle subfolder.
     */
    public function test_material_inside_context_is_rejected(): void {
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot/material'],
        ];

        try {
            context_pointer::resolve_target($decoded, 'material_store');
            $this->fail('materialstoreincontext haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialstoreincontext', $e->errorcode);
        }
    }

    /**
     * Resolution check 7 also rejects the same folder.
     */
    public function test_material_in_the_same_folder_as_context_is_rejected(): void {
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'geteilt'],
            'material_store' => ['location' => 'moodle', 'path' => 'geteilt'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'material_store');
    }

    /**
     * The reverse nesting is allowed: context may lie within inventory
     * (CONTEXT.md, Material inventory).
     */
    public function test_context_inside_material_is_allowed(): void {
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'material_store');

        $this->assertSame('/material/', $location->path);
    }

    /**
     * Resolution check 7 cannot find overlap between Moodle context and
     * external inventory: their location comparison keys differ.
     */
    public function test_external_material_never_overlaps_a_moodle_context(): void {
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => [
                'location' => 'external',
                'instanceid' => 3,
                'path' => 'coursepilot',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k'],
            ],
        ];

        $location = context_pointer::resolve_target($decoded, 'material_store');

        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
    }

    /**
     * Two instances with the same server/account but different base paths
     * are different locations. Include base paths in overlap checks to avoid
     * false positives for identical relative paths (#518, Spec #486 §2).
     */
    public function test_extern_targets_with_different_base_paths_are_not_falsely_flagged_as_overlapping(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 1,
                'path' => 'Ordner1',
                'fingerprint' => ['server' => 's', 'basepath' => 'TeamA', 'account' => 'k'],
            ],
            'material_store' => [
                'location' => 'external',
                'instanceid' => 2,
                'path' => 'Ordner1',
                'fingerprint' => ['server' => 's', 'basepath' => 'TeamB', 'account' => 'k'],
            ],
        ];

        $location = context_pointer::resolve_target($decoded, 'material_store');

        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
    }

    /**
     * Instances rooted at Team + A/B and Team/A + B resolve to the same
     * physical path. Detect nesting despite different instance IDs (#518).
     */
    public function test_extern_targets_nested_via_different_base_paths_are_rejected(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 1,
                'path' => 'A/B',
                'fingerprint' => ['server' => 's', 'basepath' => 'Team', 'account' => 'k'],
            ],
            'material_store' => [
                'location' => 'external',
                'instanceid' => 2,
                'path' => 'B',
                'fingerprint' => ['server' => 's', 'basepath' => 'Team/A', 'account' => 'k'],
            ],
        ];

        try {
            context_pointer::resolve_target($decoded, 'material_store');
            $this->fail('materialstoreincontext haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialstoreincontext', $e->errorcode);
        }
    }

    /**
     * Resolution check 8: recognized IServ instances are accessible only
     * below Files/ (#497, Spec #486 §2/§5), using the iserv marker without I/O.
     */
    public function test_iserv_path_outside_files_is_rejected_without_network(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 3,
                'path' => 'Groups/Klasse7a',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k', 'iserv' => true],
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        try {
            context_pointer::resolve_target($decoded, 'context_area');
            $this->fail('webdaviservfilesonly haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdaviservfilesonly', $e->errorcode);
        }
    }

    /**
     * Recognized IServ instances remain accessible below Files/.
     */
    public function test_iserv_path_under_files_is_allowed(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 3,
                'path' => 'Files/Unterricht/Kontext',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k', 'iserv' => true],
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'context_area');

        $this->assertSame('Files/Unterricht/Kontext', $location->relativepath);
    }

    /**
     * Pointers predating #497 have no iserv marker; treat them as false
     * without breaking existing content.
     */
    public function test_missing_iserv_field_defaults_to_no_restriction(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 3,
                'path' => 'Beliebig',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k'],
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $location = context_pointer::resolve_target($decoded, 'context_area');

        $this->assertSame('Beliebig', $location->relativepath);
    }

    public function test_v2_pointer_extern_target_rejects_zero_instance_id(): void {
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 0,
                'path' => 'Kontext',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k'],
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'mein-material'],
        ];

        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_target($decoded, 'context_area');
    }

    // --- resolve_previous() (Issue #498, Spec #486 §9) ---

    public function test_resolve_previous_moodle_value(): void {
        $location = context_pointer::resolve_previous(['location' => 'moodle', 'path' => 'alter-kontext']);

        $this->assertSame(pointer_location::MOODLE, $location->kind);
        $this->assertSame('/alter-kontext/', $location->path);
    }

    public function test_resolve_previous_extern_value(): void {
        $location = context_pointer::resolve_previous([
            'location' => 'external',
            'instanceid' => 3,
            'path' => 'Unterricht/Alt',
            'fingerprint' => ['server' => 'cloud.example.test', 'basepath' => 'Coursepilot', 'account' => 'lehrerin'],
        ]);

        $this->assertSame(pointer_location::EXTERNAL, $location->kind);
        $this->assertSame(3, $location->instanceid);
        $this->assertSame('Unterricht/Alt', $location->relativepath);
    }

    /**
     * All resolution checks also apply to the previous location (#498);
     * test IServ check 8.
     */
    public function test_resolve_previous_enforces_iserv_files_only(): void {
        try {
            context_pointer::resolve_previous([
                'location' => 'external',
                'instanceid' => 3,
                'path' => 'Groups/Alt',
                'fingerprint' => ['server' => 's', 'basepath' => 'b', 'account' => 'k', 'iserv' => true],
            ]);
            $this->fail('webdaviservfilesonly haette geworfen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdaviservfilesonly', $e->errorcode);
        }
    }

    public function test_resolve_previous_incomplete_value_is_incomplete(): void {
        $this->expectException(\moodle_exception::class);
        context_pointer::resolve_previous(['location' => 'moodle']);
    }
}
