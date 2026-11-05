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
 * Which registered tools write data: the classification drives the access log and the write gate.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(tool_registry::class)]
final class tool_registry_classification_test extends \advanced_testcase {
    /**
     * Tools that only read or plan.
     */
    private const READING = [
        'coursepilot_list_courses', 'coursepilot_get_course_catalog', 'coursepilot_get_modules',
        'coursepilot_get_module_settings', 'coursepilot_list_activity_versions', 'coursepilot_compare_activity_versions',
        'coursepilot_get_sections', 'coursepilot_get_question_categories', 'coursepilot_plan_question_category_cleanup',
        'coursepilot_get_question', 'coursepilot_plan_quiz_cleanup', 'coursepilot_get_version_info',
        'coursepilot_list_context_files', 'coursepilot_describe_module_fields', 'coursepilot_read_context_file',
        'coursepilot_list_material_files', 'coursepilot_preview_material_file', 'coursepilot_report_loose_material_files',
        'coursepilot_report_clone_lineage', 'coursepilot_list_skills', 'coursepilot_get_skill',
        // Read-only despite the "export_" and "create_" prefixes: they hand out XML or download links.
        'coursepilot_export_activity_backup', 'coursepilot_create_workbench_download_links',
    ];

    /**
     * Tools that change data in Moodle or in the personal area.
     */
    private const WRITING = [
        'coursepilot_restore_activity_version', 'coursepilot_update_module_settings', 'coursepilot_create_module',
        'coursepilot_create_quiz', 'coursepilot_update_quiz_settings', 'coursepilot_set_completion',
        'coursepilot_set_restriction', 'coursepilot_ensure_section', 'coursepilot_update_section',
        'coursepilot_move_section', 'coursepilot_move_module', 'coursepilot_ensure_question_bank',
        'coursepilot_ensure_question_category', 'coursepilot_update_question_category', 'coursepilot_move_question',
        'coursepilot_create_mc_question', 'coursepilot_update_mc_question', 'coursepilot_import_questions_xml',
        'coursepilot_export_questions_xml', 'coursepilot_ensure_quiz_question_categories',
        'coursepilot_add_glossary_entries', 'coursepilot_add_questions_to_quiz', 'coursepilot_write_context_file',
        'coursepilot_append_context_file', 'coursepilot_upload_material_file', 'coursepilot_crop_material_file',
        'coursepilot_compose_material_file', 'coursepilot_delete_material_files', 'coursepilot_clone_activity',
        'coursepilot_create_activity_from_xml', 'coursepilot_export_default_activity',
        'coursepilot_dismiss_pending_entry', 'coursepilot_dismiss_previous_location',
    ];

    public function test_every_registered_tool_is_classified_as_reading_or_writing(): void {
        $registered = array_keys(tool_registry::allowed_tools());
        $classified = array_merge(self::READING, self::WRITING);

        sort($registered);
        sort($classified);
        $this->assertSame($registered, $classified);
        foreach (self::READING as $name) {
            $this->assertFalse(tool_registry::is_write($name), $name);
        }
        foreach (self::WRITING as $name) {
            $this->assertTrue(tool_registry::is_write($name), $name);
        }
    }

    public function test_service_functions_carry_their_description_and_access_type(): void {
        $functions = tool_registry::service_functions();

        foreach (tool_registry::allowed_tools() as $name => $function) {
            $this->assertSame(
                in_array($name, self::WRITING, true) ? 'write' : 'read',
                $functions[$function]['type'],
                $function
            );
            $this->assertFalse($functions[$function]['ajax'], $function);
            $this->assertNotSame('', $functions[$function]['description'], $function);
        }
    }
}
