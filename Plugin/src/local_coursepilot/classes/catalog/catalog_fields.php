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

use moodle_exception;

/**
 * Shared field validation of the module catalog.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class catalog_fields {
    /**
     * Validates a field specification exclusively against the given catalog.
     *
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param array $values
     * @param bool $patch True if the form patch path is used.
     * @return void
     */
    public static function validate(string $catalogclass, array $values, bool $patch = false): void {
        $modname = $catalogclass::modname();
        $blocklist = array_unique(array_merge(shared_block::BLOCKLIST, $catalogclass::blocklist()));
        $fields = array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());
        $byname = [];
        foreach ($fields as $field) {
            $byname[$field->name] = $field;
        }

        foreach ($values as $fieldname => $value) {
            field::assert_name($fieldname);
            if (in_array($fieldname, $blocklist, true)) {
                shared_block::assert_not_completion_field($fieldname);
                throw new moodle_exception('blockedfield', 'local_coursepilot', '', ['field' => $fieldname, 'modname' => $modname]);
            }
            if ($patch && in_array($fieldname, $catalogclass::write_options()['patch_blocked_fields'] ?? [], true)) {
                throw new moodle_exception('folderfilespatchunsupported', 'local_coursepilot');
            }
            shared_block::assert_not_read_only_vocabulary($fieldname, $modname);
            $lookup = array_key_exists($fieldname, $byname) ? $fieldname : self::template_name($fieldname);
            if (!isset($byname[$lookup])) {
                throw new moodle_exception('unknownfield', 'local_coursepilot', '', ['field' => $fieldname, 'modname' => $modname]);
            }
            if ($byname[$lookup]->values !== null && !in_array($value, $byname[$lookup]->values, false)) {
                throw new moodle_exception('invalidfieldvalue', 'local_coursepilot', '', [
                    'field' => $fieldname, 'modname' => $modname, 'value' => json_encode($value),
                ]);
            }
        }
    }

    /**
     * Provides template name.
     *
     * @param string $fieldname The fieldname.
     * @return string
     */
    private static function template_name(string $fieldname): string {
        return preg_match('/^(parameter|variable)_\d+$/', $fieldname) === 1
            ? preg_replace('/_\d+$/', '_N', $fieldname)
            : $fieldname;
    }
}
