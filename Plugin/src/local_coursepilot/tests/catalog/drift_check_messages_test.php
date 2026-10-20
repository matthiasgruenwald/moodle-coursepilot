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

/**
 * The exact violations the drift check reports for a divergent catalog.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The exact violations the drift check reports for a divergent catalog: table columns, callable
 * sources, required constants and fields that write options reference outside the catalog.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(drift_check::class)]
final class drift_check_messages_test extends \advanced_testcase {
    /**
     * Checks catalog definition data against the real label table.
     *
     * @param mixed[] $config
     * @return string[]
     */
    private function violations(array $config): array {
        return drift_check::check_definition(
            'label',
            $config['fields'] ?? label::fields(),
            $config['pseudofields'] ?? [],
            $config['blocklist'] ?? label::blocklist(),
            $config['constants'] ?? [],
            $config['write_options'] ?? [],
            $config['locks'] ?? []
        );
    }

    /**
     * A catalog that matches the table has no violations, and an overlap between fields and blocklist does not matter.
     */
    public function test_matching_catalog_and_blocklist_overlap_have_no_violations(): void {
        $this->assertSame([], $this->violations([]));
        $this->assertSame([], $this->violations(['blocklist' => array_merge(label::blocklist(), ['name'])]));
        $pseudo = new field('only_pseudo', 'PARAM_RAW', 'x', false, null, null, null, 'test');
        $this->assertSame([], $this->violations([
            'pseudofields' => [$pseudo],
            'blocklist' => array_merge(label::blocklist(), ['only_pseudo']),
        ]));
    }

    /**
     * Columns missing from the catalog and entries missing from the table are named, each in its own clause.
     */
    public function test_column_differences_are_named(): void {
        $withoutintro = array_values(array_filter(label::fields(), static fn (field $f): bool => $f->name !== 'intro'));
        $extra = new field('ghost', 'PARAM_RAW', 'x', false, null, null, null, 'test');

        $this->assertSame(
            ['Columns of table "label" differ from the catalog (new in the table, unknown to the catalog: intro).'],
            $this->violations(['fields' => $withoutintro])
        );
        $this->assertSame(
            ['Columns of table "label" differ from the catalog (listed in the catalog, no longer present in the table: ghost).'],
            $this->violations(['fields' => array_merge(label::fields(), [$extra])])
        );
        $this->assertSame(
            ['Columns of table "label" differ from the catalog (new in the table, unknown to the catalog: intro; '
                . 'listed in the catalog, no longer present in the table: ghost).'],
            $this->violations(['fields' => array_merge($withoutintro, [$extra])])
        );
        $this->assertSame(
            ['Columns of table "label" differ from the catalog (listed in the catalog, no longer present in the table: '
                . 'ghost, ghost2).'],
            $this->violations(['fields' => array_merge(label::fields(), [
                $extra, new field('ghost2', 'PARAM_RAW', 'x', false, null, null, null, 'test'),
            ])])
        );
    }

    /**
     * Label fields with the callable source of one field replaced.
     *
     * @param string $name
     * @param string $callable
     * @return field[]
     */
    private function label_fields_with_callable(string $name, string $callable): array {
        return array_map(static fn (field $f): field => $f->name === $name
            ? new field($f->name, $f->type, $f->meaning, $f->required, $f->default, $f->values, $callable, $f->source)
            : $f, label::fields());
    }

    /**
     * Missing callable sources are reported by name, for functions and static methods.
     */
    public function test_missing_callable_sources_are_reported(): void {
        $this->assertSame([], $this->violations(['fields' => $this->label_fields_with_callable('intro', 'clean_param()')]));
        $this->assertSame([], $this->violations(['fields' => $this->label_fields_with_callable(
            'intro',
            'local_coursepilot\catalog\label::modname()'
        )]));
        $this->assertSame(
            ['Callable source "no_such_function_a()" no longer exists on this instance.'],
            $this->violations(['fields' => $this->label_fields_with_callable('intro', 'no_such_function_a()')])
        );
        $this->assertSame(
            ['Callable source "no_such_class_x::method()" no longer exists on this instance.'],
            $this->violations(['fields' => $this->label_fields_with_callable('intro', 'no_such_class_x::method()')])
        );
        $this->assertSame(
            ['Callable source "local_coursepilot\catalog\label::no_such_method()" no longer exists on '
                . 'this instance.'],
            $this->violations(['fields' => $this->label_fields_with_callable(
                'intro',
                'local_coursepilot\catalog\label::no_such_method()'
            )])
        );
        $this->assertSame(
            ['Callable source "local_coursepilot\catalog\label::modname::extra()" no longer exists on this instance.'],
            $this->violations(['fields' => $this->label_fields_with_callable(
                'intro',
                'local_coursepilot\catalog\label::modname::extra()'
            )])
        );
    }

    /**
     * The same missing source on several fields, or in a pseudofield, is one violation each.
     */
    public function test_a_missing_callable_source_is_reported_once_across_fields(): void {
        $fields = array_map(static fn (field $f): field => in_array($f->name, ['intro', 'introformat'], true)
            ? new field(
                $f->name,
                $f->type,
                $f->meaning,
                $f->required,
                $f->default,
                $f->values,
                'no_such_function_a()',
                $f->source
            )
            : $f, label::fields());
        $pseudo = new field('p1', 'PARAM_RAW', 'x', false, null, null, 'no_such_function_p()', 'test');

        $this->assertSame(
            [
                'Callable source "no_such_function_a()" no longer exists on this instance.',
                'Callable source "no_such_function_p()" no longer exists on this instance.',
            ],
            $this->violations([
                'fields' => $fields, 'pseudofields' => [$pseudo], 'blocklist' => array_merge(label::blocklist(), ['p1']),
            ])
        );
    }

    /**
     * Missing constants are reported once each, whether the catalog lists a constant twice or not.
     */
    public function test_missing_constants_are_reported_once_each(): void {
        $this->assertSame(
            [
                'Constant "NO_SUCH_CONSTANT_A" no longer exists on this instance.',
                'Constant "NO_SUCH_CONSTANT_B" no longer exists on this instance.',
            ],
            $this->violations(['constants' => ['NO_SUCH_CONSTANT_A', 'FORMAT_HTML', 'NO_SUCH_CONSTANT_A', 'NO_SUCH_CONSTANT_B']])
        );
    }

    /**
     * Every kind of write option naming a field is checked against the catalog; each unknown name once, in order.
     */
    public function test_fields_referenced_by_write_options_must_be_in_the_catalog(): void {
        $options = [
            'material_reference_fields' => ['u_material' => ['component' => 'x']],
            'missing_form_values' => ['u_missing' => 1],
            'admin_default_fields' => ['u_admin' => 'x'],
            'scalar_to_repeated' => ['u_scalar_key' => 'u_scalar_value'],
            'editor_content' => ['u_editor_key' => ['u_editor_a', 'u_editor_b']],
            'read_fields' => ['u_read'],
            'patch_blocked_fields' => ['u_patch', 'u_read'],
            'intro_image_field' => 'u_intro_image',
            'settings_aliases' => ['u_alias' => 'password'],
            'parallel_array_lengths' => [['reference' => 'u_par_ref', 'field' => 'u_par_field']],
            'date_order_rules' => [['reference' => 'u_date_ref', 'field' => 'u_date_field', 'mode' => 'not_before']],
            'side_effect_triggers' => ['u_trigger' => 'x'],
            'repeated_group' => ['g' => ['fields' => ['u_group_a' => 'a', 'u_group_b' => 'b']]],
        ];

        $violations = $this->violations(['write_options' => $options, 'locks' => ['u_lock' => ['op' => 'equals', 'value' => 1]]]);

        $this->assertSame(array_map(
            static fn (string $name): string => 'Field "' . $name . '" is referenced outside the catalog.',
            ['u_material', 'u_missing', 'u_admin', 'u_scalar_key', 'u_scalar_value', 'u_editor_key', 'u_editor_a',
                'u_editor_b', 'u_read', 'u_patch', 'u_intro_image', 'u_alias', 'u_lock', 'u_par_ref', 'u_par_field',
                'u_date_ref', 'u_date_field', 'u_trigger', 'u_group_a', 'u_group_b']
        ), $violations);
        $this->assertSame([
            'Field "u_scalar_key" is referenced outside the catalog.',
            'Field "u_scalar_value" is referenced outside the catalog.',
            'Field "u_read" is referenced outside the catalog.',
        ], $this->violations(['write_options' => [
            'scalar_to_repeated' => ['u_scalar_key' => 'u_scalar_value'],
            'read_fields' => ['u_scalar_key' => 'u_read'],
        ]]));
        $this->assertSame([
            'Field "ghost" is referenced outside the catalog.',
        ], $this->violations(['write_options' => ['read_fields' => ['intro', 'ghost']]]));
    }

    /**
     * Options that name only catalog fields, or none at all, are clean.
     */
    public function test_write_options_naming_catalog_fields_are_clean(): void {
        $this->assertSame([], $this->violations(['write_options' => [
            'read_fields' => ['intro'],
            'date_order_rules' => [['reference' => 'intro', 'field' => 'introformat', 'mode' => 'not_before']],
            'repeated_group' => ['g' => ['fields' => ['intro' => 'a']]],
            'editor_content' => [],
        ]]));
    }
}
