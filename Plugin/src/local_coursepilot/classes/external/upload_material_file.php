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

namespace local_coursepilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\material_area;
use local_coursepilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates or fully overwrites a material file for the calling teacher
 * (Spec 0018 §2/§4.2/§8.1, #428). Single entry point for all sources,
 * including chat attachments and later crops.
 *
 * Validate path, extension, server size, concurrency and quota before
 * performing exactly one write.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class upload_material_file extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'File path relative to the material store, e.g. "screenshot.png"'),
            'content_base64' => new external_value(PARAM_RAW, 'Base64-encoded file content'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash from the last listing; mismatch aborts the operation',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * @param string $path
     * @param string $contentbase64
     * @param string $expectedcontenthash
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialfiledisallowedtype,
     *         materialfiletoolarge, materialfilechanged, materialquotaexceeded
     * @throws \invalid_parameter_exception invalid base64
     * @throws \required_capability_exception without moodle/user:manageownfiles
     */
    public static function execute(string $path, string $contentbase64, string $expectedcontenthash = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content_base64' => $contentbase64,
            'expected_contenthash' => $expectedcontenthash,
        ]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        $content = base64_decode($params['content_base64'], true);
        if ($content === false) {
            throw new \invalid_parameter_exception('content_base64 is not valid base64.');
        }
        self::guard_server_size_limit(strlen($content));

        // Path, extension, concurrency, quota and persistence sequencing belong
        // to the anchor (#539, material_area::write() through storage_port),
        // not this tool.
        return self::build_response(material_area::write($params['path'], $content, $params['expected_contenthash']));
    }

    /**
     * Builds the response from {@see material_area::write()} (#539, formerly
     * #523), extracted from execute() to keep functions below 50 lines.
     *
     * @param array{path: string, created: bool, size: int, oldsize: int, warning: ?string} $written
     * @return array
     */
    private static function build_response(array $written): array {
        $message = $written['created']
            ? get_string('materialfilecreated', 'local_coursepilot', $written['path'])
            : get_string('materialfileoverwritten', 'local_coursepilot', (object) [
                'path' => $written['path'],
                'before' => $written['oldsize'],
                'after' => $written['size'],
            ]);
        if ($written['warning'] !== null) {
            $message .= ' ' . $written['warning'];
        }

        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'message' => $message,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved file path relative to the material store'),
            'created' => new external_value(PARAM_BOOL, 'true if the file was newly created'),
            'size' => new external_value(PARAM_INT, 'New file size in bytes'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message, including any quota warning'),
        ]);
    }

    /**
     * Rejects uploads above the server limit (Spec 0018 §8.1), without a
     * separate plugin size limit. Follows Spec 0017 §9 and the testable-core/
     * server-setting split of import_questions_xml::guard_server_size_limit().
     *
     * @param int $bytes
     * @return void
     */
    private static function guard_server_size_limit(int $bytes): void {
        self::guard_size_against_limit($bytes, get_max_upload_file_size());
    }

    /**
     * Testable core of {@see self::guard_server_size_limit()}. Caller supplies
     * maxbytes so tests need not change the container's PHP ini settings.
     *
     * @param int $bytes
     * @param int $maxbytes
     * @return void
     * @throws \moodle_exception materialfiletoolarge
     */
    private static function guard_size_against_limit(int $bytes, int $maxbytes): void {
        if ($maxbytes <= 0 || $bytes <= $maxbytes) {
            return;
        }
        throw new \moodle_exception('materialfiletoolarge', 'local_coursepilot', '', (object) [
            'size' => $bytes,
            'max' => $maxbytes,
        ]);
    }
}
