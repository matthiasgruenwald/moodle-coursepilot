<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/

namespace local_coursepilot;

/**
 * The single registration of every Coursepilot tool.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class tool_registry {
    /** @var array<string, array{classname: class-string, descriptionkey: string}> */
    private const TOOLS = [
        'coursepilot_list_courses' => ['classname' => 'local_coursepilot\external\list_courses', 'descriptionkey' => 'tool_list_courses'],
        'coursepilot_get_course_catalog' => ['classname' => 'local_coursepilot\external\get_course_catalog', 'descriptionkey' => 'tool_get_course_catalog'],
        'coursepilot_get_modules' => ['classname' => 'local_coursepilot\external\get_modules', 'descriptionkey' => 'tool_get_modules'],
        'coursepilot_get_module_settings' => ['classname' => 'local_coursepilot\external\get_module_settings', 'descriptionkey' => 'tool_get_module_settings'],
        'coursepilot_list_activity_versions' => ['classname' => 'local_coursepilot\external\list_activity_versions', 'descriptionkey' => 'tool_list_activity_versions'],
        'coursepilot_compare_activity_versions' => ['classname' => 'local_coursepilot\external\compare_activity_versions', 'descriptionkey' => 'tool_compare_activity_versions'],
        'coursepilot_restore_activity_version' => ['classname' => 'local_coursepilot\external\restore_activity_version', 'descriptionkey' => 'tool_restore_activity_version'],
        'coursepilot_update_module_settings' => ['classname' => 'local_coursepilot\external\update_module_settings', 'descriptionkey' => 'tool_update_module_settings'],
        'coursepilot_create_module' => ['classname' => 'local_coursepilot\external\create_module', 'descriptionkey' => 'tool_create_module'],
        'coursepilot_create_quiz' => ['classname' => 'local_coursepilot\external\create_quiz', 'descriptionkey' => 'tool_create_quiz'],
        'coursepilot_update_quiz_settings' => ['classname' => 'local_coursepilot\external\update_quiz_settings', 'descriptionkey' => 'tool_update_quiz_settings'],
        'coursepilot_set_completion' => ['classname' => 'local_coursepilot\external\set_completion', 'descriptionkey' => 'tool_set_completion'],
        'coursepilot_set_restriction' => ['classname' => 'local_coursepilot\external\set_restriction', 'descriptionkey' => 'tool_set_restriction'],
        'coursepilot_ensure_section' => ['classname' => 'local_coursepilot\external\ensure_section', 'descriptionkey' => 'tool_ensure_section'],
        'coursepilot_update_section' => ['classname' => 'local_coursepilot\external\update_section', 'descriptionkey' => 'tool_update_section'],
        'coursepilot_move_section' => ['classname' => 'local_coursepilot\external\move_section', 'descriptionkey' => 'tool_move_section'],
        'coursepilot_move_module' => ['classname' => 'local_coursepilot\external\move_module', 'descriptionkey' => 'tool_move_module'],
        'coursepilot_get_sections' => ['classname' => 'local_coursepilot\external\get_sections', 'descriptionkey' => 'tool_get_sections'],
        'coursepilot_ensure_question_bank' => ['classname' => 'local_coursepilot\external\ensure_question_bank', 'descriptionkey' => 'tool_ensure_question_bank'],
        'coursepilot_ensure_question_category' => ['classname' => 'local_coursepilot\external\ensure_question_category', 'descriptionkey' => 'tool_ensure_question_category'],
        'coursepilot_update_question_category' => ['classname' => 'local_coursepilot\external\update_question_category', 'descriptionkey' => 'tool_update_question_category'],
        'coursepilot_move_question' => ['classname' => 'local_coursepilot\external\move_question', 'descriptionkey' => 'tool_move_question'],
        'coursepilot_create_mc_question' => ['classname' => 'local_coursepilot\external\create_mc_question', 'descriptionkey' => 'tool_create_mc_question'],
        'coursepilot_update_mc_question' => ['classname' => 'local_coursepilot\external\update_mc_question', 'descriptionkey' => 'tool_update_mc_question'],
        'coursepilot_import_questions_xml' => ['classname' => 'local_coursepilot\external\import_questions_xml', 'descriptionkey' => 'tool_import_questions_xml'],
        'coursepilot_export_questions_xml' => ['classname' => 'local_coursepilot\external\export_questions_xml', 'descriptionkey' => 'tool_export_questions_xml'],
        'coursepilot_get_question_categories' => ['classname' => 'local_coursepilot\external\get_question_categories', 'descriptionkey' => 'tool_get_question_categories'],
        'coursepilot_get_question' => ['classname' => 'local_coursepilot\external\get_question', 'descriptionkey' => 'tool_get_question'],
        'coursepilot_plan_quiz_cleanup' => ['classname' => 'local_coursepilot\external\get_quiz_cleanup_plan', 'descriptionkey' => 'tool_plan_quiz_cleanup'],
        'coursepilot_add_questions_to_quiz' => ['classname' => 'local_coursepilot\external\add_questions_to_quiz', 'descriptionkey' => 'tool_add_questions_to_quiz'],
        'coursepilot_get_version_info' => ['classname' => 'local_coursepilot\external\get_version_info', 'descriptionkey' => 'tool_get_version_info'],
        'coursepilot_list_context_files' => ['classname' => 'local_coursepilot\external\list_context_files', 'descriptionkey' => 'tool_list_context_files'],
        'coursepilot_describe_module_fields' => ['classname' => 'local_coursepilot\external\describe_module_fields', 'descriptionkey' => 'tool_describe_module_fields'],
        'coursepilot_read_context_file' => ['classname' => 'local_coursepilot\external\read_context_file', 'descriptionkey' => 'tool_read_context_file'],
        'coursepilot_write_context_file' => ['classname' => 'local_coursepilot\external\write_context_file', 'descriptionkey' => 'tool_write_context_file'],
        'coursepilot_append_context_file' => ['classname' => 'local_coursepilot\external\append_context_file', 'descriptionkey' => 'tool_append_context_file'],
        'coursepilot_list_material_files' => ['classname' => 'local_coursepilot\external\list_material_files', 'descriptionkey' => 'tool_list_material_files'],
        'coursepilot_upload_material_file' => ['classname' => 'local_coursepilot\external\upload_material_file', 'descriptionkey' => 'tool_upload_material_file'],
        'coursepilot_preview_material_file' => ['classname' => 'local_coursepilot\external\preview_material_file', 'descriptionkey' => 'tool_preview_material_file'],
        'coursepilot_crop_material_file' => ['classname' => 'local_coursepilot\external\crop_material_file', 'descriptionkey' => 'tool_crop_material_file'],
        'coursepilot_report_loose_material_files' => ['classname' => 'local_coursepilot\external\report_loose_material_files', 'descriptionkey' => 'tool_report_loose_material_files'],
        'coursepilot_delete_material_files' => ['classname' => 'local_coursepilot\external\delete_material_files', 'descriptionkey' => 'tool_delete_material_files'],
        'coursepilot_clone_activity' => ['classname' => 'local_coursepilot\external\clone_activity', 'descriptionkey' => 'tool_clone_activity'],
        'coursepilot_report_clone_lineage' => ['classname' => 'local_coursepilot\external\report_clone_lineage', 'descriptionkey' => 'tool_report_clone_lineage'],
        'coursepilot_list_skills' => ['classname' => 'local_coursepilot\external\list_skills', 'descriptionkey' => 'tool_list_skills'],
        'coursepilot_get_skill' => ['classname' => 'local_coursepilot\external\get_skill', 'descriptionkey' => 'tool_get_skill'],
        'coursepilot_dismiss_ausstand' => ['classname' => 'local_coursepilot\external\dismiss_ausstand', 'descriptionkey' => 'tool_dismiss_ausstand'],
        'coursepilot_create_werkbank_download_links' => ['classname' => 'local_coursepilot\external\create_werkbank_download_links', 'descriptionkey' => 'tool_create_werkbank_download_links'],
        'coursepilot_dismiss_altbestand' => ['classname' => 'local_coursepilot\external\dismiss_altbestand', 'descriptionkey' => 'tool_dismiss_altbestand'],
    ];

    /** @return array<string, string> */
    public static function allowed_tools(): array {
        return array_map(static fn(array $tool): string => self::function_name($tool['classname']), self::TOOLS);
    }

    /** @return array<string, string> */
    public static function descriptions(): array {
        return array_map(static fn(array $tool): string => get_string($tool['descriptionkey'], 'local_coursepilot'), self::TOOLS);
    }

    /** @return array<string, array{properties: array, required?: array}> */
    public static function schemas(): array {
        $schemas = [];
        foreach (self::TOOLS as $name => $tool) {
            $classname = $tool['classname'];
            $schemas[$name] = external_schema_converter::from_parameters($classname::execute_parameters());
        }
        return $schemas;
    }

    /** @return array<string, array<string, mixed>> */
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

    public static function is_write(string $toolname): bool {
        return self::is_write_class(self::TOOLS[$toolname]['classname']);
    }

    /**
     * Die Klasse zu einem Webservice-Funktionsnamen - für Aufrufer, die nur
     * das brauchen (#568: dispatcher.php je Werkzeugaufruf). Anders als
     * {@see service_functions()} baut das nicht die komplette Tool-Map samt
     * get_string()-Aufruf je Werkzeug neu auf, nur um einen einzigen
     * Klassennamen herauszulesen - das wäre sonst auf dem heißen Pfad
     * jedes einzelnen tools/call-Dispatches unnötige Arbeit.
     *
     * @param string $function
     * @return class-string|null null, wenn kein registriertes Werkzeug diese
     *         Funktion trägt.
     */
    public static function classname_for_function(string $function): ?string {
        foreach (self::TOOLS as $tool) {
            if (self::function_name($tool['classname']) === $function) {
                return $tool['classname'];
            }
        }
        return null;
    }

    /** @return string[] */
    public static function service_function_names(): array {
        return array_values(self::allowed_tools());
    }

    private static function function_name(string $classname): string {
        return 'local_coursepilot_' . substr($classname, strrpos($classname, '\\') + 1);
    }

    private static function is_write_class(string $classname): bool {
        if ($classname === 'local_coursepilot\\external\\create_werkbank_download_links') {
            return false;
        }
        return preg_match('/\\\\(?:restore|update|create|set|ensure|move|import|export|add|write|append|upload|crop|delete|clone|dismiss)_/', $classname) === 1;
    }
}
