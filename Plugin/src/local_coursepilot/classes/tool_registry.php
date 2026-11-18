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

/**
 * The single registration of every Coursepilot tool.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class tool_registry {
    /**
     * Registered tools. Write tools (see is_write()) must declare `destructive` (can delete or overwrite
     * existing content) and `idempotent` (a repeated call with the same arguments changes nothing more);
     * both become MCP annotations; reading tools must not declare them. `external` marks tools that can read
     * from or write to an external WebDAV location (context area, or material files via `location`).
     * Idempotent means the same call leaves the same Moodle state; plugin history entries do not count.
     *
     * @var array<string, array{classname: class-string, descriptionkey: string, destructive?: bool,
     *     idempotent?: bool, external?: bool}>
     */
    private const TOOLS = [
        'coursepilot_list_courses' => [
            'classname' => 'local_coursepilot\external\list_courses',
            'descriptionkey' => 'tool_list_courses',
        ],
        'coursepilot_get_course_catalog' => [
            'classname' => 'local_coursepilot\external\get_course_catalog',
            'descriptionkey' => 'tool_get_course_catalog',
        ],
        'coursepilot_get_modules' => [
            'classname' => 'local_coursepilot\external\get_modules',
            'descriptionkey' => 'tool_get_modules',
        ],
        'coursepilot_get_module_settings' => [
            'classname' => 'local_coursepilot\external\get_module_settings',
            'descriptionkey' => 'tool_get_module_settings',
        ],
        'coursepilot_list_activity_versions' => [
            'classname' => 'local_coursepilot\external\list_activity_versions',
            'descriptionkey' => 'tool_list_activity_versions',
        ],
        'coursepilot_compare_activity_versions' => [
            'classname' => 'local_coursepilot\external\compare_activity_versions',
            'descriptionkey' => 'tool_compare_activity_versions',
        ],
        'coursepilot_restore_activity_version' => [
            'classname' => 'local_coursepilot\external\restore_activity_version',
            'descriptionkey' => 'tool_restore_activity_version',
            // Overwrites the current activity state with an earlier one; a repeated call finds nothing left to
            // change. Plugin history entries do not count as an effect.
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_update_module_settings' => [
            'classname' => 'local_coursepilot\external\update_module_settings',
            'descriptionkey' => 'tool_update_module_settings',
            'destructive' => true,
            'idempotent' => true,
            'external' => true,
        ],
        'coursepilot_create_module' => [
            'classname' => 'local_coursepilot\external\create_module',
            'descriptionkey' => 'tool_create_module',
            'destructive' => false,
            'idempotent' => false,
            'external' => true,
        ],
        'coursepilot_create_quiz' => [
            'classname' => 'local_coursepilot\external\create_quiz',
            'descriptionkey' => 'tool_create_quiz',
            'destructive' => false,
            'idempotent' => false,
        ],
        'coursepilot_update_quiz_settings' => [
            'classname' => 'local_coursepilot\external\update_quiz_settings',
            'descriptionkey' => 'tool_update_quiz_settings',
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_set_completion' => [
            'classname' => 'local_coursepilot\external\set_completion',
            'descriptionkey' => 'tool_set_completion',
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_set_restriction' => [
            'classname' => 'local_coursepilot\external\set_restriction',
            'descriptionkey' => 'tool_set_restriction',
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_ensure_section' => [
            'classname' => 'local_coursepilot\external\ensure_section',
            'descriptionkey' => 'tool_ensure_section',
            // Renames an existing section to the given name; otherwise creates it.
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_update_section' => [
            'classname' => 'local_coursepilot\external\update_section',
            'descriptionkey' => 'tool_update_section',
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_move_section' => [
            'classname' => 'local_coursepilot\external\move_section',
            'descriptionkey' => 'tool_move_section',
            // Repositions only, but sections are addressed by position: a repeated call moves the section that
            // moved up into that place.
            'destructive' => false,
            'idempotent' => false,
        ],
        'coursepilot_move_module' => [
            'classname' => 'local_coursepilot\external\move_module',
            'descriptionkey' => 'tool_move_module',
            // Repositions only; no content is lost and the old position is restorable.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_get_sections' => [
            'classname' => 'local_coursepilot\external\get_sections',
            'descriptionkey' => 'tool_get_sections',
        ],
        'coursepilot_ensure_question_bank' => [
            'classname' => 'local_coursepilot\external\ensure_question_bank',
            'descriptionkey' => 'tool_ensure_question_bank',
            // Finds or creates; never changes an existing bank.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_ensure_question_category' => [
            'classname' => 'local_coursepilot\external\ensure_question_category',
            'descriptionkey' => 'tool_ensure_question_category',
            // Finds or creates; never changes an existing category.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_update_question_category' => [
            'classname' => 'local_coursepilot\external\update_question_category',
            'descriptionkey' => 'tool_update_question_category',
            // Renames or reparents an existing category.
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_move_question' => [
            'classname' => 'local_coursepilot\external\move_question',
            'descriptionkey' => 'tool_move_question',
            // Repositions only; questions and versions stay intact.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_create_mc_question' => [
            'classname' => 'local_coursepilot\external\create_mc_question',
            'descriptionkey' => 'tool_create_mc_question',
            'destructive' => false,
            'idempotent' => false,
        ],
        'coursepilot_update_mc_question' => [
            'classname' => 'local_coursepilot\external\update_mc_question',
            'descriptionkey' => 'tool_update_mc_question',
            // Every call writes a new Moodle question version.
            'destructive' => true,
            'idempotent' => false,
            'external' => true,
        ],
        'coursepilot_import_questions_xml' => [
            'classname' => 'local_coursepilot\external\import_questions_xml',
            'descriptionkey' => 'tool_import_questions_xml',
            // Re-importing a question with the same idnumber in the target category adds a new version of the
            // existing question; otherwise it adds questions, so a repeated call adds them again.
            'destructive' => true,
            'idempotent' => false,
            'external' => true,
        ],
        'coursepilot_export_questions_xml' => [
            'classname' => 'local_coursepilot\external\export_questions_xml',
            'descriptionkey' => 'tool_export_questions_xml',
            // Writes the XML file into the material store and replaces a file of the same name.
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_get_question_categories' => [
            'classname' => 'local_coursepilot\external\get_question_categories',
            'descriptionkey' => 'tool_get_question_categories',
        ],
        'coursepilot_ensure_quiz_question_categories' => [
            'classname' => 'local_coursepilot\external\ensure_quiz_question_categories',
            'descriptionkey' => 'tool_ensure_quiz_question_categories',
            // Initializes missing categories; existing ones stay as they are.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_plan_question_category_cleanup' => [
            'classname' => 'local_coursepilot\external\get_question_category_cleanup_plan',
            'descriptionkey' => 'tool_plan_question_category_cleanup',
        ],
        'coursepilot_get_question' => [
            'classname' => 'local_coursepilot\external\get_question',
            'descriptionkey' => 'tool_get_question',
        ],
        'coursepilot_plan_quiz_cleanup' => [
            'classname' => 'local_coursepilot\external\get_quiz_cleanup_plan',
            'descriptionkey' => 'tool_plan_quiz_cleanup',
        ],
        'coursepilot_add_glossary_entries' => [
            'classname' => 'local_coursepilot\\external\\add_glossary_entries',
            'descriptionkey' => 'tool_add_glossary_entries',
            'destructive' => false,
            'idempotent' => false,
            'external' => true,
        ],
        'coursepilot_add_questions_to_quiz' => [
            'classname' => 'local_coursepilot\external\add_questions_to_quiz',
            'descriptionkey' => 'tool_add_questions_to_quiz',
            // Only appends; a question already in the quiz gets no second slot. Preview attempts are cleared as in
            // the quiz editor.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_get_version_info' => [
            'classname' => 'local_coursepilot\external\get_version_info',
            'descriptionkey' => 'tool_get_version_info',
        ],
        'coursepilot_list_context_files' => [
            'classname' => 'local_coursepilot\external\list_context_files',
            'descriptionkey' => 'tool_list_context_files',
            'external' => true,
        ],
        'coursepilot_describe_module_fields' => [
            'classname' => 'local_coursepilot\external\describe_module_fields',
            'descriptionkey' => 'tool_describe_module_fields',
        ],
        'coursepilot_read_context_file' => [
            'classname' => 'local_coursepilot\external\read_context_file',
            'descriptionkey' => 'tool_read_context_file',
            'external' => true,
        ],
        'coursepilot_write_context_file' => [
            'classname' => 'local_coursepilot\external\write_context_file',
            'descriptionkey' => 'tool_write_context_file',
            // Creates or fully overwrites the file.
            'destructive' => true,
            'idempotent' => true,
            'external' => true,
        ],
        'coursepilot_append_context_file' => [
            'classname' => 'local_coursepilot\external\append_context_file',
            'descriptionkey' => 'tool_append_context_file',
            // A repeated call appends the content again.
            'destructive' => false,
            'idempotent' => false,
            'external' => true,
        ],
        'coursepilot_list_material_files' => [
            'classname' => 'local_coursepilot\external\list_material_files',
            'descriptionkey' => 'tool_list_material_files',
            'external' => true,
        ],
        'coursepilot_upload_material_file' => [
            'classname' => 'local_coursepilot\external\upload_material_file',
            'descriptionkey' => 'tool_upload_material_file',
            // Creates or fully overwrites the file.
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_preview_material_file' => [
            'classname' => 'local_coursepilot\external\preview_material_file',
            'descriptionkey' => 'tool_preview_material_file',
            'external' => true,
        ],
        'coursepilot_crop_material_file' => [
            'classname' => 'local_coursepilot\external\crop_material_file',
            'descriptionkey' => 'tool_crop_material_file',
            // Replaces an existing target file.
            'destructive' => true,
            'idempotent' => true,
            'external' => true,
        ],
        'coursepilot_compose_material_file' => [
            'classname' => 'local_coursepilot\external\compose_material_file',
            'descriptionkey' => 'tool_compose_material_file',
            // Replaces an existing target file.
            'destructive' => true,
            'idempotent' => true,
            'external' => true,
        ],
        'coursepilot_report_loose_material_files' => [
            'classname' => 'local_coursepilot\external\report_loose_material_files',
            'descriptionkey' => 'tool_report_loose_material_files',
        ],
        'coursepilot_delete_material_files' => [
            'classname' => 'local_coursepilot\external\delete_material_files',
            'descriptionkey' => 'tool_delete_material_files',
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_clone_activity' => [
            'classname' => 'local_coursepilot\external\clone_activity',
            'descriptionkey' => 'tool_clone_activity',
            'destructive' => false,
            'idempotent' => false,
        ],
        'coursepilot_create_activity_from_xml' => [
            'classname' => 'local_coursepilot\external\create_activity_from_xml',
            'descriptionkey' => 'tool_create_activity_from_xml',
            // With replaces_cmid the existing activity is hidden.
            'destructive' => true,
            'idempotent' => false,
            'external' => true,
        ],
        'coursepilot_export_activity_backup' => [
            'classname' => 'local_coursepilot\external\export_activity_backup',
            'descriptionkey' => 'tool_export_activity_backup',
        ],
        'coursepilot_export_default_activity' => [
            'classname' => 'local_coursepilot\external\export_default_activity',
            'descriptionkey' => 'tool_export_default_activity',
            // Creates a hidden throwaway activity and removes it again; nothing is left behind.
            'destructive' => false,
            'idempotent' => true,
        ],
        'coursepilot_report_clone_lineage' => [
            'classname' => 'local_coursepilot\external\report_clone_lineage',
            'descriptionkey' => 'tool_report_clone_lineage',
        ],
        'coursepilot_list_skills' => [
            'classname' => 'local_coursepilot\external\list_skills',
            'descriptionkey' => 'tool_list_skills',
        ],
        'coursepilot_start_context' => [
            'classname' => 'local_coursepilot\external\start_context',
            'descriptionkey' => 'tool_start_context',
            'external' => true,
        ],
        'coursepilot_get_skill' => ['classname' => 'local_coursepilot\external\get_skill', 'descriptionkey' => 'tool_get_skill'],
        'coursepilot_dismiss_pending_entry' => [
            'classname' => 'local_coursepilot\external\dismiss_pending_entry',
            'descriptionkey' => 'tool_dismiss_pending_entry',
            // Removes the note entry for good.
            'destructive' => true,
            'idempotent' => true,
        ],
        'coursepilot_create_workbench_download_links' => [
            'classname' => 'local_coursepilot\external\create_workbench_download_links',
            'descriptionkey' => 'tool_create_workbench_download_links',
            // Classified as reading by design: it only creates download tickets (no teacher-facing state change),
            // but hands out one-time download URLs that work without login.
        ],
        'coursepilot_dismiss_previous_location' => [
            'classname' => 'local_coursepilot\external\dismiss_previous_location',
            'descriptionkey' => 'tool_dismiss_previous_location',
            // Closes the legacy context holdings for good; files are never touched.
            'destructive' => true,
            'idempotent' => true,
        ],
    ];

    /**
     * Provides allowed tools.
     *
     * @return array<string, string>
     */
    public static function allowed_tools(): array {
        return array_map(static fn(array $tool): string => self::function_name($tool['classname']), self::TOOLS);
    }

    /**
     * Provides descriptions.
     *
     * @return array<string, string>
     */
    public static function descriptions(): array {
        return array_map(static fn(array $tool): string => get_string($tool['descriptionkey'], 'local_coursepilot'), self::TOOLS);
    }

    /**
     * Derives MCP input properties and required names from each registered tool's Moodle parameters.
     *
     * @return array<string, array{properties: array<string, array<string, mixed>>, required?: string[]}>
     *         Input schema fragments keyed by MCP tool name.
     */
    public static function schemas(): array {
        $schemas = [];
        foreach (self::TOOLS as $name => $tool) {
            $classname = $tool['classname'];
            $schemas[$name] = external_schema_converter::from_parameters($classname::execute_parameters());
        }
        return $schemas;
    }

    /**
     * MCP tool annotations per tool, derived from the read/write classification of the registry.
     *
     * Read tools carry only readOnlyHint and openWorldHint: destructiveHint and idempotentHint have
     * no meaning for them. Write tools must declare both values in their registry entry.
     *
     * @return array<string, array<string, bool>> ToolAnnotations keyed by MCP tool name.
     */
    public static function annotations(): array {
        $annotations = [];
        foreach (self::TOOLS as $name => $tool) {
            $annotations[$name] = self::annotation_for($name, $tool, self::is_write_class($tool['classname']));
        }
        return $annotations;
    }

    /**
     * Builds one tool's annotations from its registry entry.
     *
     * @param string $name MCP tool name.
     * @param mixed[] $tool Registry entry.
     * @param bool $iswrite Whether the tool is classified as writing.
     * @return bool[]
     * @throws \coding_exception A write tool lacks its declarations or a read tool carries them.
     */
    private static function annotation_for(string $name, array $tool, bool $iswrite): array {
        $declared = isset($tool['destructive']) || isset($tool['idempotent']);
        if ($iswrite && !isset($tool['destructive'], $tool['idempotent'])) {
            throw new \coding_exception("Write tool {$name} must declare destructive and idempotent.");
        }
        if (!$iswrite && $declared) {
            throw new \coding_exception("Read tool {$name} must not declare destructive or idempotent.");
        }
        // A reading tool never destroys and repeats harmlessly; stated explicitly because clients that
        // read only destructiveHint would otherwise assume the MCP default (true).
        return [
            'readOnlyHint' => !$iswrite,
            'destructiveHint' => $tool['destructive'] ?? false,
            'idempotentHint' => $tool['idempotent'] ?? true,
            'openWorldHint' => $tool['external'] ?? false,
        ];
    }

    /**
     * Provides service functions.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function service_functions(): array {
        $functions = [];
        foreach (self::TOOLS as $tool) {
            $functions[self::function_name($tool['classname'])] = [
                'classname' => $tool['classname'],
                'description' => get_string($tool['descriptionkey'], 'local_coursepilot'),
                'type' => self::is_write_class($tool['classname']) ? 'write' : 'read',
                'ajax' => false,
            ];
        }
        return $functions;
    }

    /**
     * Classifies a registered MCP tool as a write operation for access logging.
     *
     * @param string $toolname Registered MCP tool name.
     * @return bool Whether the registered action is classified as writing data.
     */
    public static function is_write(string $toolname): bool {
        return self::is_write_class(self::TOOLS[$toolname]['classname']);
    }

    /**
     * Provides service function names.
     *
     * @return string[]
     */
    public static function service_function_names(): array {
        return array_values(self::allowed_tools());
    }

    /**
     * Provides function name.
     *
     * @param string $classname The classname.
     * @return string
     */
    private static function function_name(string $classname): string {
        return 'local_coursepilot_' . substr($classname, strrpos($classname, '\\') + 1);
    }

    /**
     * Classifies an external-function class by its action prefix, with explicit read-only exceptions.
     *
     * @param string $classname External-function class name from the tool registry.
     * @return bool Whether the registered action is classified as writing data.
     */
    private static function is_write_class(string $classname): bool {
        // Read-only despite the "export_" prefix: hands out XML, writes nothing.
        if (
            in_array($classname, [
            'local_coursepilot\\external\\create_workbench_download_links',
            'local_coursepilot\\external\\export_activity_backup',
            ], true)
        ) {
            return false;
        }
        return preg_match(
            '/\\\\(?:restore|update|create|set|ensure|move|import|export|add|write|append|upload|crop|compose|delete|clone|'
                . 'dismiss)_/',
            $classname
        ) === 1;
    }
}
