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
 * Pins the delivered definition of every activity catalog (Spec 0015): what
 * `describe_module_fields` tells a teacher or an AI client - field names, types,
 * meanings, defaults, allowed values, sources, write options, bundles, rules - is
 * the contract. Any change must be deliberate and shows up as a fixture diff.
 *
 * Regenerate after an intended catalog change with
 * `COURSEPILOT_UPDATE_CATALOG_SNAPSHOTS=1` (the run then fails on purpose after
 * writing `tests/fixtures/catalog/<modname>.json`; review the diff, run again).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(assign::class)]
#[CoversClass(choice::class)]
#[CoversClass(folder::class)]
#[CoversClass(forum::class)]
#[CoversClass(label::class)]
#[CoversClass(page::class)]
#[CoversClass(quiz::class)]
#[CoversClass(resource::class)]
#[CoversClass(url::class)]
#[CoversClass(field::class)]
final class catalog_definition_snapshot_test extends \advanced_testcase {
    /** Descriptive, argument-free catalog methods that make up the delivered definition. */
    private const SECTIONS = [
        'modname', 'fields', 'write_options', 'common_field_names', 'pseudofields', 'blocklist', 'combination_rules',
        'side_effects', 'bundles', 'write_route', 'checked_constants', 'learner_locks', 'reviewed_up_to_major', 'grade_origin',
    ];

    /**
     * Every cataloged module type on Moodle 5.1, plus the branches where a catalog reacts to the
     * Moodle version (assign markers from 5.2 and 5.3, forum from 5.2, quiz date rules from 5.3).
     *
     * @return array<string, array{string, int}>
     */
    public static function catalogs(): array {
        $cases = [];
        foreach (registry::known_modnames() as $modname) {
            $cases["$modname 5.1"] = [$modname, 501];
        }
        foreach ([['assign', 502], ['assign', 503], ['forum', 502], ['quiz', 503]] as [$modname, $branch]) {
            $cases["$modname $branch"] = [$modname, $branch];
        }
        return $cases;
    }

    /**
     * The delivered definition of a catalog equals its recorded fixture.
     *
     * @param string $modname
     * @param int $branch Moodle branch the catalog is evaluated for.
     */
    #[DataProvider('catalogs')]
    public function test_catalog_definition_matches_the_fixture(string $modname, int $branch): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->branch = (string) $branch;
        $class = registry::for($modname);
        $actual = [];
        foreach (self::SECTIONS as $section) {
            if (method_exists($class, $section)) {
                $actual[$section] = self::normalise($class::$section());
            }
        }
        $path = __DIR__ . '/../fixtures/catalog/' . ($branch === 501 ? $modname : "{$modname}-{$branch}") . '.json';
        if (getenv('COURSEPILOT_UPDATE_CATALOG_SNAPSHOTS')) {
            $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            file_put_contents($path, json_encode($actual, $flags) . "\n");
            $this->fail("Snapshot {$modname} rewritten; review the diff and run again without the variable.");
        }

        $expected = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            $expected,
            json_decode(json_encode($actual), true),
            "Catalog '{$modname}' (branch {$branch}) no longer matches its fixture."
        );
    }

    /**
     * Fields become their delivered array shape, everything else stays as it is.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalise(mixed $value): mixed {
        if ($value instanceof field) {
            return $value->to_array();
        }
        return is_array($value) ? array_map(static fn ($v) => self::normalise($v), $value) : $value;
    }
}
