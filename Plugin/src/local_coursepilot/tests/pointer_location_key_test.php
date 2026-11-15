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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exact comparison keys of pointer locations (Issue #495, Spec #486 §2 check 7).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pointer_location::class)]
final class pointer_location_key_test extends \basic_testcase {
    /**
     * Locations, subpaths and the key they must produce.
     *
     * @return array<string, array{pointer_location, string, string}>
     */
    public static function keys(): array {
        $fingerprint = ['server' => 'Cloud.Example.TEST', 'basepath' => '/Base/', 'account' => 'User'];
        return [
            'moodle path' => [pointer_location::moodle('/a/b/'), '', 'moodle|/a/b/'],
            'moodle path with subpath' => [pointer_location::moodle('/a/b/'), 'c/d', 'moodle|/a/b/c/d/'],
            'moodle subpath with slashes' => [pointer_location::moodle('/a/'), '/c/', 'moodle|/a/c/'],
            'moodle root' => [pointer_location::moodle('/'), '', 'moodle|/'],
            'moodle root with subpath' => [pointer_location::moodle('/'), 's', 'moodle|/s/'],
            'moodle path without slashes' => [pointer_location::moodle('a'), '', 'moodle|/a/'],
            'external lower-cases only the server' => [pointer_location::external(4, 'rel/ative/', $fingerprint), '',
                'external|cloud.example.test|User|/Base/rel/ative/'],
            'external with subpath' => [
                pointer_location::external(4, '/rel/', $fingerprint),
                '/sub/',
                'external|cloud.example.test|User|/Base/rel/sub/',
            ],
            'external without base path' => [pointer_location::external(4, 'rel', ['server' => 's', 'account' => 'a']), '',
                'external|s|a|/rel/'],
            'external root' => [pointer_location::external(4, '', []), '', 'external|||/'],
            'external root with subpath' => [pointer_location::external(4, '', ['basepath' => '/']), 'x', 'external|||/x/'],
        ];
    }

    #[DataProvider('keys')]
    public function test_comparison_key_is_exact(pointer_location $location, string $subpath, string $expected): void {
        $this->assertSame($expected, $location->comparison_key($subpath));
    }

    public function test_equal_places_in_different_spelling_share_one_key_and_different_places_do_not(): void {
        $a = pointer_location::external(1, 'x/y', ['server' => 'H', 'basepath' => 'b', 'account' => 'u']);
        $b = pointer_location::external(2, '/x/y/', ['server' => 'h', 'basepath' => '/b/', 'account' => 'u']);
        $other = pointer_location::external(1, 'x/y', ['server' => 'h', 'basepath' => 'b', 'account' => 'U']);

        $this->assertSame($a->comparison_key(), $b->comparison_key());
        $this->assertNotSame($a->comparison_key(), $other->comparison_key());
        $this->assertNotSame(
            pointer_location::moodle('/b/x/y/')->comparison_key(),
            pointer_location::external(1, 'x/y', ['basepath' => 'b'])->comparison_key()
        );
    }
}
