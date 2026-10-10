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

/**
 * Machine-checkable catalog validation against the running Moodle instance
 * (Spec 0015 §11, ADR 0017, Ticket #399): table columns, callable sources
 * and required constants. Shared by repository tests and runtime write_gate
 * checks, as in privacy_surface.
 *
 * Copied enumerations, combination rules and side effects still require
 * manual review per major release (module_catalog::reviewed_up_to_major()).
 * Read-only database metadata and PHP introspection; no writes.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class drift_check {
    /**
     * Libraries defining catalog callable sources that core does not load
     * for every request. weblib.php and moodlelib.php are already loaded by
     * lib/setup.php. Use the same libraries as catalog contract tests to
     * avoid mistaking an unloaded function for a removed function.
     *
     * @var array<string, string[]> Module name to paths with {dirroot}/{libdir} placeholders.
     */
    private const REQUIRE_FILES = [
        'page' => ['{libdir}/resourcelib.php'],
        'url' => ['{libdir}/resourcelib.php', '{dirroot}/mod/url/locallib.php'],
        'folder' => ['{dirroot}/mod/folder/lib.php'],
        'resource' => ['{libdir}/resourcelib.php'],
        'choice' => [],
        'forum' => ['{dirroot}/mod/forum/lib.php', '{dirroot}/rating/lib.php'],
        'assign' => ['{dirroot}/mod/assign/locallib.php'],
        'quiz' => [
            '{dirroot}/mod/quiz/lib.php',
            '{dirroot}/mod/quiz/locallib.php',
            '{dirroot}/mod/quiz/classes/access_manager.php',
            '{dirroot}/question/engine/lib.php',
        ],
        'label' => [],
    ];

    /**
     * All violations for an activity type, empty if the catalog passes
     * (automatically checked or reviewed).
     *
     * @param string $modname
     * @return string[] English violation descriptions, empty without drift.
     */
    public static function check(string $modname): array {
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            return ["Unknown activity type \"$modname\" - no catalog maintained."];
        }

        return self::check_catalog($modname, $catalogclass);
    }

    /**
     * Like check(), taking the catalog class directly instead of resolving
     * through registry::for(). Tests can inject a divergent catalog without
     * changing the registry.
     *
     * @param string $modname Table/module name to check.
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    public static function check_catalog(string $modname, string $catalogclass): array {
        self::require_known_libraries($modname);

        return array_merge(
            self::column_violations($modname, $catalogclass),
            self::callable_violations($catalogclass),
            self::constant_violations($catalogclass),
            self::write_option_violations($catalogclass)
        );
    }

    /**
     * Requires known libraries.
     *
     * @param string $modname
     * @return void
     */
    private static function require_known_libraries(string $modname): void {
        global $CFG;

        foreach (self::REQUIRE_FILES[$modname] ?? [] as $path) {
            $resolved = str_replace(['{dirroot}', '{libdir}'], [$CFG->dirroot, $CFG->libdir], $path);
            if (is_readable($resolved)) {
                require_once($resolved);
            }
        }
    }

    /**
     * Column parity: fields(), real-column blocklist entries, applicable
     * shared_block entries and id must exactly match the table columns.
     * Exclude pseudofields from blocked columns, as in folder/resource
     * contract tests (e.g. files, blocked until Spec 0018).
     *
     * @param string $modname
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function column_violations(string $modname, string $catalogclass): array {
        global $DB;

        $realcolumns = array_keys($DB->get_columns($modname));
        sort($realcolumns);

        $pseudofieldnames = array_map(static fn (field $f): string => $f->name, $catalogclass::pseudofields());
        $blockedrealcolumns = array_diff($catalogclass::blocklist(), $pseudofieldnames);

        $known = array_merge(
            ['id'],
            array_map(static fn (field $f): string => $f->name, $catalogclass::fields()),
            $blockedrealcolumns,
            array_intersect(shared_block::BLOCKLIST, $realcolumns)
        );
        $known = array_values(array_unique($known));
        sort($known);

        if ($known === $realcolumns) {
            return [];
        }

        $added = array_values(array_diff($realcolumns, $known));
        $removed = array_values(array_diff($known, $realcolumns));
        $detail = [];
        if ($added) {
            $detail[] = 'new in the table, unknown to the catalog: ' . implode(', ', $added);
        }
        if ($removed) {
            $detail[] = 'listed in the catalog, no longer present in the table: ' . implode(', ', $removed);
        }
        return ['Columns of table "' . $modname . '" differ from the catalog (' . implode('; ', $detail) . ').'];
    }

    /**
     * Check callable sources referenced by fields and pseudofields,
     * including functions and static methods.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function callable_violations(string $catalogclass): array {
        $fields = array_merge(
            shared_block::fields(),
            shared_block::pseudofields(),
            $catalogclass::fields(),
            $catalogclass::pseudofields()
        );

        $callables = array_unique(array_filter(array_map(
            static fn (field $f): ?string => $f->sourcecallable,
            $fields
        )));

        $violations = [];
        foreach ($callables as $callable) {
            $bare = rtrim($callable, '()');
            $exists = str_contains($bare, '::')
                ? method_exists(...explode('::', $bare, 2))
                : function_exists($bare);
            if (!$exists) {
                $violations[] = "Callable source \"$callable\" no longer exists on this instance.";
            }
        }
        return $violations;
    }

    /**
     * Check constants referenced by module_catalog::checked_constants()
     * and shared_block::checked_constants().
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function constant_violations(string $catalogclass): array {
        $constants = array_unique(array_merge(
            shared_block::checked_constants(),
            $catalogclass::checked_constants()
        ));

        $violations = [];
        foreach ($constants as $constname) {
            if (!defined($constname)) {
                $violations[] = "Constant \"$constname\" no longer exists on this instance.";
            }
        }
        return $violations;
    }

    /**
     * Each field-related write option must name a catalog field. This also
     * catches newly read or written fields that would bypass the catalog.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @return string[]
     */
    private static function write_option_violations(string $catalogclass): array {
        $known = array_column(array_merge(
            shared_block::fields(),
            $catalogclass::fields(),
            $catalogclass::pseudofields()
        ), 'name');
        $options = $catalogclass::write_options();
        $referenced = array_merge(
            array_keys($options['material_reference_fields'] ?? []),
            array_keys($options['missing_form_values'] ?? []),
            array_keys($options['admin_default_fields'] ?? []),
            array_keys($options['scalar_to_repeated'] ?? []),
            array_values($options['scalar_to_repeated'] ?? []),
            array_keys($options['editor_content'] ?? []),
            array_merge(...array_values($options['editor_content'] ?? [[]])),
            $options['read_fields'] ?? [],
            $options['patch_blocked_fields'] ?? [],
            isset($options['intro_image_field']) ? [$options['intro_image_field']] : [],
            array_keys($options['settings_aliases'] ?? []),
            array_keys($catalogclass::learner_locks())
        );
        foreach (array_merge($options['parallel_array_lengths'] ?? [], $options['date_order_rules'] ?? []) as $rule) {
            $referenced[] = $rule['reference'];
            $referenced[] = $rule['field'];
        }
        $referenced = array_merge($referenced, array_keys($options['side_effect_triggers'] ?? []));
        foreach ($options['repeated_group'] ?? [] as $spec) {
            $referenced = array_merge($referenced, array_keys($spec['fields'] ?? []));
        }
        $unknown = array_values(array_diff(array_unique($referenced), $known));
        return array_map(
            static fn(string $field): string => 'Field "' . $field . '" is referenced outside the catalog.',
            $unknown
        );
    }
}
