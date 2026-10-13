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

use context_module;
use core_external\external_api;
use local_coursepilot\availability_privacy;

/**
 * Shared implementation of the catalog read contract.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class module_state {
    /**
     * Read the complete activity state and existing learner locks for an
     * authorised catalog client. Read access needs only local/coursepilot:use,
     * unlike get_moduleinfo_data(), which requires manageactivities.
     *
     * Settings retain raw instance fields and the catalog vocabulary. Draft
     * editor helpers and derived gradebook fields are omitted; quiz passing
     * grades are read from its primary grade item by effective_settings().
     *
     * @param int $cmid Course module ID.
     * @return mixed[] Module identity, JSON settings and existing learner locks.
     */
    public static function read_settings(int $cmid): array {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        external_api::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $data = self::effective_settings($cm);
        $catalogclass = registry::for((string) $cm->modname);

        return [
            'cmid' => (int) $cm->id,
            'modname' => (string) $cm->modname,
            'settings_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'learner_locks' => $catalogclass === null ? [] : learner_locks::existing($catalogclass, $data),
        ];
    }

    /**
     * Full state in catalog vocabulary for read-modify-write. External tools
     * need no knowledge of module tables or pseudofield readers.
     *
     * @param \stdClass $cm
     * @return mixed[]
     */
    public static function effective_settings(\stdClass $cm): array {
        global $CFG, $DB;

        $section = $DB->get_record('course_sections', ['id' => $cm->section], 'section', MUST_EXIST);
        $instance = (array) $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
        $data = array_merge($instance, [
            'coursemodule' => (int) $cm->id,
            'section' => (int) $section->section,
            'visible' => (int) $cm->visible,
            'visibleoncoursepage' => (int) $cm->visibleoncoursepage,
            'idnumber' => (string) $cm->idnumber,
            'groupmode' => (int) groups_get_activity_groupmode($cm),
            'groupingid' => (int) $cm->groupingid,
            'course' => (int) $cm->course,
            'module' => (int) $cm->module,
            'modulename' => (string) $cm->modname,
            'instance' => (int) $cm->instance,
            'completion' => (int) $cm->completion,
            'completionview' => (int) $cm->completionview,
            'completionexpected' => (int) $cm->completionexpected,
            'completionusegrade' => $cm->completiongradeitemnumber === null ? 0 : 1,
            'completionpassgrade' => (int) $cm->completionpassgrade,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            'showdescription' => (int) $cm->showdescription,
            'downloadcontent' => $cm->downloadcontent,
            'lang' => (string) $cm->lang,
            'tags' => \core_tag_tag::get_item_tags_array('core', 'course_modules', $cm->id),
        ], shared_block::derive_visibility((int) $cm->visible, (int) $cm->visibleoncoursepage));
        if (!empty($CFG->enableavailability)) {
            $data['availabilityconditionsjson'] = availability_privacy::sanitize((string) ($cm->availability ?? ''));
        }
        $catalogclass = registry::for((string) $cm->modname);
        if ($catalogclass !== null) {
            $data = array_merge($data, self::read_repeated_groups($catalogclass, (int) $cm->instance));
        }
        if ($cm->modname === 'quiz') {
            $data = array_merge($data, quiz::grade_settings((int) $cm->instance));
        }
        return $data;
    }

    /**
     * Minimal projection for cataloged types without catalog-specific settings
     * (folder, resource, choice, forum: Spec 0015 §4.1; only the name matters to
     * the teacher), and uncataloged modules, which remain readable but cannot
     * be written through Coursepilot. Shared by individual catalog classes
     * instead of a central module-type switch.
     *
     * @param string $modname
     * @param int $instanceid
     * @param bool $fullcontent
     * @return array{name: string, content: mixed[], settings: mixed[], quizslots: mixed[]}
     */
    public static function unknown(string $modname, int $instanceid, bool $fullcontent): array {
        global $DB;

        $details = self::empty($fullcontent);
        $record = $DB->get_record($modname, ['id' => $instanceid], 'name', IGNORE_MISSING);
        $details['name'] = $record ? (string) $record->name : '';
        return $details;
    }

    /**
     * Empty initial catalog state for missing instances or fields populated
     * in later steps.
     *
     * @param bool $fullcontent
     * @return array{name: string, content: mixed[], settings: mixed[], quizslots: mixed[]}
     */
    public static function empty(bool $fullcontent): array {
        return ['name' => '', 'content' => self::content_field('', $fullcontent), 'settings' => [], 'quizslots' => []];
    }

    /**
     * Shared normalization of HTML intro/content fields to catalog vocabulary
     * across module types.
     *
     * @param string $html
     * @param bool $fullcontent
     * @return array{html: string, preview: string, truncated: bool}
     */
    public static function content_field(string $html, bool $fullcontent): array {
        return [
            'html' => $fullcontent ? $html : '',
            'preview' => self::preview($html, $fullcontent),
            'truncated' => !$fullcontent && trim($html) !== '',
        ];
    }

    /**
     * Previews the module state.
     *
     * @param string $html
     * @param bool $fullcontent
     * @return string
     */
    public static function preview(string $html, bool $fullcontent): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        return $fullcontent || strlen($text) <= 280 ? $text : substr($text, 0, 277) . '...';
    }

    /**
     * Convert associative field/value settings to catalog name/value pairs.
     * Each catalog class uses this shared normalization for its own settings.
     *
     * @param mixed[] $settings
     * @return array<int, array{name: string, value: string}>
     */
    public static function settings(array $settings): array {
        $pairs = [];
        foreach ($settings as $name => $value) {
            $pairs[] = ['name' => (string) $name, 'value' => (string) $value];
        }
        return $pairs;
    }

    /**
     * Read pseudofield groups stored in a separate table (Spec 0015 §2.2
     * category 2, repeated groups, Issue #564). Read counterpart to
     * pseudofield_carry_forward::carry_forward_choice_options(). Activity types
     * declare groups in module_catalog::write_options()["repeated_group"], so
     * get_module_settings needs no module-specific branch.
     *
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param int $instanceid
     * @return array<string, mixed> Field name to value list; empty if the type declares no group.
     */
    public static function read_repeated_groups(string $catalogclass, int $instanceid): array {
        global $DB;

        $groups = $catalogclass::write_options()['repeated_group'] ?? [];
        $result = [];
        foreach ($groups as $spec) {
            $rows = $DB->get_records(
                $spec['table'],
                [$spec['foreignkey'] => $instanceid],
                $spec['orderby']
            );
            foreach ($spec['fields'] as $fieldname => $column) {
                $result[$fieldname] = array_values(array_map(
                    static fn (\stdClass $row): mixed => $row->{$column},
                    $rows
                ));
            }
        }
        return $result;
    }
}
