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

namespace local_coursepilot\external;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use local_coursepilot\external\add_glossary_entries;
use local_coursepilot\external\add_questions_to_quiz;
use local_coursepilot\external\append_context_file;
use local_coursepilot\external\clone_activity;
use local_coursepilot\external\compare_activity_versions;
use local_coursepilot\external\compose_material_file;
use local_coursepilot\external\create_activity_from_xml;
use local_coursepilot\external\create_mc_question;
use local_coursepilot\external\create_module;
use local_coursepilot\external\create_quiz;
use local_coursepilot\external\create_workbench_download_links;
use local_coursepilot\external\crop_material_file;
use local_coursepilot\external\delete_material_files;
use local_coursepilot\external\describe_module_fields;
use local_coursepilot\external\dismiss_pending_entry;
use local_coursepilot\external\dismiss_previous_location;
use local_coursepilot\external\ensure_question_bank;
use local_coursepilot\external\ensure_question_category;
use local_coursepilot\external\ensure_quiz_question_categories;
use local_coursepilot\external\ensure_section;
use local_coursepilot\external\export_activity_backup;
use local_coursepilot\external\export_default_activity;
use local_coursepilot\external\export_questions_xml;
use local_coursepilot\external\get_course_catalog;
use local_coursepilot\external\get_module_settings;
use local_coursepilot\external\get_modules;
use local_coursepilot\external\get_question;
use local_coursepilot\external\get_question_categories;
use local_coursepilot\external\get_question_category_cleanup_plan;
use local_coursepilot\external\get_quiz_cleanup_plan;
use local_coursepilot\external\get_sections;
use local_coursepilot\external\get_skill;
use local_coursepilot\external\get_version_info;
use local_coursepilot\external\import_questions_xml;
use local_coursepilot\external\list_activity_versions;
use local_coursepilot\external\list_context_files;
use local_coursepilot\external\list_courses;
use local_coursepilot\external\list_material_files;
use local_coursepilot\external\list_skills;
use local_coursepilot\external\move_module;
use local_coursepilot\external\move_question;
use local_coursepilot\external\move_section;
use local_coursepilot\external\preview_material_file;
use local_coursepilot\external\read_context_file;
use local_coursepilot\external\report_clone_lineage;
use local_coursepilot\external\report_loose_material_files;
use local_coursepilot\external\restore_activity_version;
use local_coursepilot\external\set_completion;
use local_coursepilot\external\set_restriction;
use local_coursepilot\external\update_mc_question;
use local_coursepilot\external\update_module_settings;
use local_coursepilot\external\update_question_category;
use local_coursepilot\external\update_quiz_settings;
use local_coursepilot\external\update_section;
use local_coursepilot\external\upload_material_file;
use local_coursepilot\external\write_context_file;

/**
 * Pins the web service signature of every external function: the parameters a client may send
 * (names, types, descriptions, required/default), the shape of what it gets back, and the PHP
 * signature of execute(). A client or an AI reads exactly these descriptions, so any change must
 * be deliberate and show up as a fixture diff.
 *
 * Regenerate after an intended change with `COURSEPILOT_UPDATE_EXTERNAL_SNAPSHOTS=1` (the run
 * then fails on purpose after writing `tests/fixtures/external/<function>.json`; review the diff,
 * run again).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(add_glossary_entries::class)]
#[CoversClass(add_questions_to_quiz::class)]
#[CoversClass(append_context_file::class)]
#[CoversClass(clone_activity::class)]
#[CoversClass(compare_activity_versions::class)]
#[CoversClass(compose_material_file::class)]
#[CoversClass(create_activity_from_xml::class)]
#[CoversClass(create_mc_question::class)]
#[CoversClass(create_module::class)]
#[CoversClass(create_quiz::class)]
#[CoversClass(create_workbench_download_links::class)]
#[CoversClass(crop_material_file::class)]
#[CoversClass(delete_material_files::class)]
#[CoversClass(describe_module_fields::class)]
#[CoversClass(dismiss_pending_entry::class)]
#[CoversClass(dismiss_previous_location::class)]
#[CoversClass(ensure_question_bank::class)]
#[CoversClass(ensure_question_category::class)]
#[CoversClass(ensure_quiz_question_categories::class)]
#[CoversClass(ensure_section::class)]
#[CoversClass(export_activity_backup::class)]
#[CoversClass(export_default_activity::class)]
#[CoversClass(export_questions_xml::class)]
#[CoversClass(get_course_catalog::class)]
#[CoversClass(get_module_settings::class)]
#[CoversClass(get_modules::class)]
#[CoversClass(get_question::class)]
#[CoversClass(get_question_categories::class)]
#[CoversClass(get_question_category_cleanup_plan::class)]
#[CoversClass(get_quiz_cleanup_plan::class)]
#[CoversClass(get_sections::class)]
#[CoversClass(get_skill::class)]
#[CoversClass(get_version_info::class)]
#[CoversClass(import_questions_xml::class)]
#[CoversClass(list_activity_versions::class)]
#[CoversClass(list_context_files::class)]
#[CoversClass(list_courses::class)]
#[CoversClass(list_material_files::class)]
#[CoversClass(list_skills::class)]
#[CoversClass(move_module::class)]
#[CoversClass(move_question::class)]
#[CoversClass(move_section::class)]
#[CoversClass(preview_material_file::class)]
#[CoversClass(read_context_file::class)]
#[CoversClass(report_clone_lineage::class)]
#[CoversClass(report_loose_material_files::class)]
#[CoversClass(restore_activity_version::class)]
#[CoversClass(set_completion::class)]
#[CoversClass(set_restriction::class)]
#[CoversClass(update_mc_question::class)]
#[CoversClass(update_module_settings::class)]
#[CoversClass(update_question_category::class)]
#[CoversClass(update_quiz_settings::class)]
#[CoversClass(update_section::class)]
#[CoversClass(upload_material_file::class)]
#[CoversClass(write_context_file::class)]
final class external_signature_snapshot_test extends \advanced_testcase {
    /**
     * Every external function class.
     *
     * @return array<string, array{string}>
     */
    public static function functions(): array {
        $names = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob(__DIR__ . '/../../classes/external/*.php')
        );
        return array_combine($names, array_map(static fn (string $n): array => [$n], $names));
    }

    /**
     * The described signature of a function equals its recorded fixture.
     *
     * @param string $name
     */
    #[DataProvider('functions')]
    public function test_signature_matches_the_fixture(string $name): void {
        $class = __NAMESPACE__ . '\\' . $name;
        $execute = new \ReflectionMethod($class, 'execute');
        $actual = [
            'execute' => [
                'public' => $execute->isPublic(),
                'static' => $execute->isStatic(),
                'returns' => (string) $execute->getReturnType(),
                'parameters' => array_map(static fn (\ReflectionParameter $p): array => [
                    'name' => $p->getName(),
                    'type' => (string) $p->getType(),
                    'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : '(none)',
                ], $execute->getParameters()),
            ],
            'parameters' => self::normalise($class::execute_parameters()),
            'returns' => self::normalise($class::execute_returns()),
        ];
        $path = __DIR__ . "/../fixtures/external/{$name}.json";
        if (getenv('COURSEPILOT_UPDATE_EXTERNAL_SNAPSHOTS')) {
            $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            file_put_contents($path, json_encode($actual, $flags) . "\n");
            $this->fail("Snapshot {$name} rewritten; review the diff and run again without the variable.");
        }

        $expected = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            $expected,
            json_decode(json_encode($actual), true),
            "External function '{$name}' no longer matches its fixture."
        );
    }

    /**
     * Turns an external description tree into plain arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalise(mixed $value): mixed {
        if (is_object($value)) {
            return ['class' => get_class($value)] + array_map(static fn ($v) => self::normalise($v), get_object_vars($value));
        }
        return is_array($value) ? array_map(static fn ($v) => self::normalise($v), $value) : $value;
    }
}
