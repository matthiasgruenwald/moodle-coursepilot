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

/**
 * Shared moduleinfo preparation for every update_moduleinfo() caller
 * outside the native form route (Tickets #388/#392). Without these additions,
 * *_update_instance() functions read undefined properties because
 * get_moduleinfo_data() omits values normally filled by form preprocessing.
 *
 * Shared by update_module_settings and set_completion: even a patch naming
 * only completion fields needs the same preparation as any other write.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pseudofield_carry_forward {
    /**
     * Apply all six preparation steps for $modname.
     *
     * @param string $modname
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param \stdClass $moduleinfo Updated in place.
     * @param array $before Current state before writing, for editor pseudofields.
     * @param \stdClass $cm
     * @param array $patch Explicit caller fields, preserved here.
     * @return void
     */
    public static function apply(
        string $modname,
        string $catalogclass,
        \stdClass $moduleinfo,
        array $before,
        \stdClass $cm,
        array $patch
    ): void {
        self::fill_pseudofield_defaults($catalogclass, $moduleinfo, $patch);
        self::prepare_editor_content_pseudofields($modname, $catalogclass, $moduleinfo, $before, $cm, $patch);
        self::carry_forward_draft_file_pseudofield($modname, $moduleinfo, $patch);
        self::carry_forward_choice_options($modname, $moduleinfo, $cm, $patch);
        self::carry_forward_assign_plugin_config($modname, $moduleinfo, $cm, $patch);
        self::unformat_localised_gradepass($moduleinfo);
    }

    /**
     * Normalize editor-content pseudofields to the arrays Moodle expects,
     * or reject invalid values (#405).
     *
     * page_update_instance() reads page["text"]. Passing a bare string previously
     * produced a successful but empty page (Claude cross-check for #400).
     * Accept strings as shorthand and wrap them in editor arrays. Reject
     * other values without text, naming the field instead of losing content.
     *
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param array $patch Normalised in place.
     * @return void
     * @throws \moodle_exception invalideditorpseudofield
     */
    public static function normalise_editor_pseudofields(string $catalogclass, array &$patch): void {
        foreach ($catalogclass::pseudofields() as $pseudofield) {
            if (!str_starts_with($pseudofield->type, 'array{text:')) {
                continue;
            }
            if (!array_key_exists($pseudofield->name, $patch)) {
                continue;
            }
            $value = $patch[$pseudofield->name];
            if (is_string($value)) {
                $patch[$pseudofield->name] = ['text' => $value, 'format' => FORMAT_HTML, 'itemid' => 0];
                continue;
            }
            if (is_array($value) && array_key_exists('text', $value)) {
                $patch[$pseudofield->name] = $value + ['format' => FORMAT_HTML, 'itemid' => 0];
                continue;
            }
            throw new \moodle_exception('invalideditorpseudofield', 'local_coursepilot', '', [
                'field' => $pseudofield->name,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /**
     * Carry forward assignment submission/feedback settings (#400).
     *
     * mod_assign recomputes nosubmissions on every write from
     * {subtype}_{plugin}_enabled fields (locallib.php:1629, :1359-1373). These
     * live in assign_plugin_config and are absent from get_moduleinfo_data().
     * Omitting them would disable all submission types and set nosubmissions=1.
     *
     * Form defaults are admin-configurable and cannot replace current state;
     * preserve existing values, as for choice options.
     *
     * @param string $modname
     * @param \stdClass $moduleinfo Updated in place.
     * @param \stdClass $cm
     * @param array $patch
     * @return void
     */
    private static function carry_forward_assign_plugin_config(
        string $modname,
        \stdClass $moduleinfo,
        \stdClass $cm,
        array $patch
    ): void {
        global $DB;

        if ($modname !== 'assign') {
            return;
        }
        // Moodle 5.2+ compares the grading method strictly with ''. The form
        // supplies that string for simple grading; get_moduleinfo_data() supplies
        // null. Without normalization an unrelated patch resets all marker settings.
        if (property_exists($moduleinfo, 'markercount') && ($moduleinfo->advancedgradingmethod_submissions ?? null) === null) {
            $moduleinfo->advancedgradingmethod_submissions = '';
        }
        $rows = $DB->get_records('assign_plugin_config', ['assignment' => $cm->instance]);
        foreach ($rows as $row) {
            $fieldname = $row->subtype . '_' . $row->plugin . '_' . $row->name;
            if (array_key_exists($fieldname, $patch) || property_exists($moduleinfo, $fieldname)) {
                continue;
            }
            $moduleinfo->{$fieldname} = $row->value;
        }
    }

    /**
     * Undo localized passing-grade formatting (#400). get_moduleinfo_data()
     * uses format_float(), e.g. "0,00" in German, but writes need decimals.
     * Otherwise MariaDB truncates gradepass after the activity change has
     * already been persisted. Native forms remove formatting on submission;
     * non-form callers must do it here.
     *
     * Match the gradepass suffix because names vary by type (workshop uses
     * submissiongradepass/assessmentgradepass). Public for the separate quiz
     * write route, which has the same source and issue (Spec 0015 §5).
     *
     * @param \stdClass $moduleinfo Corrected in place.
     * @return void
     */
    public static function unformat_localised_gradepass(\stdClass $moduleinfo): void {
        foreach (get_object_vars($moduleinfo) as $name => $value) {
            if (is_string($value) && $value !== '' && str_ends_with($name, 'gradepass')) {
                $moduleinfo->{$name} = unformat_float($value);
            }
        }
    }

    /**
     * update_moduleinfo() overwrites intro from introeditor["text"]
     * (course/modlib.php:675-680), so a bare intro patch would disappear.
     * Synchronize patch text/format into the preloaded editor, preserving its
     * draft item ID for caller-managed embedded images (Issue #433).
     * Shared by update_module_settings and the dedicated quiz write route.
     *
     * @param \stdClass $moduleinfo Updated in place.
     * @param array $patch
     * @return void
     */
    public static function sync_intro_editor_from_patch(\stdClass $moduleinfo, array $patch): void {
        if (!isset($moduleinfo->introeditor) || !is_array($moduleinfo->introeditor)) {
            return;
        }
        if (array_key_exists('intro', $patch)) {
            $moduleinfo->introeditor['text'] = (string) $patch['intro'];
        }
        if (array_key_exists('introformat', $patch)) {
            $moduleinfo->introeditor['format'] = (int) $patch['introformat'];
        }
    }

    /**
     * Fill pseudofields absent from moduleinfo with cataloged form defaults,
     * as moodleform_mod would. Otherwise omitted optional fields (e.g. page
     * printintro) cause undefined-property warnings and reset to null.
     *
     * Skip null defaults, used for editor arrays, since null is no useful substitute.
     *
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param \stdClass $moduleinfo Updated in place.
     * @param array $patch
     * @return void
     */
    private static function fill_pseudofield_defaults(string $catalogclass, \stdClass $moduleinfo, array $patch): void {
        foreach ($catalogclass::pseudofields() as $pseudofield) {
            if (array_key_exists($pseudofield->name, $patch) || property_exists($moduleinfo, $pseudofield->name)) {
                continue;
            }
            if ($pseudofield->default !== null) {
                $moduleinfo->{$pseudofield->name} = $pseudofield->default;
            }
        }
    }

    /**
     * Reconstruct editor arrays from the flat catalog contract. Moodle writes
     * content from the editor array rather than the instance columns.
     *
     * @param string $modname The modname.
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @param \stdClass $moduleinfo Updated in place.
     * @param array $before
     * @param \stdClass $cm The cm.
     * @param array $patch
     * @return void
     */
    private static function prepare_editor_content_pseudofields(
        string $modname,
        string $catalogclass,
        \stdClass $moduleinfo,
        array $before,
        \stdClass $cm,
        array $patch
    ): void {
        global $CFG;

        $editors = $catalogclass::write_options()['editor_content'] ?? [];
        if (!$editors) {
            return;
        }
        require_once($CFG->dirroot . '/mod/' . $modname . '/lib.php');
        $locallib = $CFG->dirroot . '/mod/' . $modname . '/locallib.php';
        if (is_file($locallib)) {
            require_once($locallib);
        }
        foreach ($editors as $pseudofield => $columns) {
            if (array_key_exists($pseudofield, $patch)) {
                continue;
            }
            [$content, $format] = $columns;
            $draftitemid = file_get_unused_draft_itemid();
            $text = file_prepare_draft_area(
                $draftitemid,
                context_module::instance($cm->id)->id,
                'mod_' . $modname,
                $content,
                0,
                [],
                (string) ($patch[$content] ?? $before[$content] ?? '')
            );
            $moduleinfo->{$pseudofield} = [
                'text' => $text,
                'format' => (int) ($patch[$format] ?? $before[$format] ?? FORMAT_HTML),
                'itemid' => $draftitemid,
            ];
        }
    }

    /**
     * files (folder/resource) has no catalog default, so it remains absent
     * when the patch omits it (Issue #434). Moodle still reads the property,
     * causing a warning before ignoring it or replacing it with a submitted
     * draft ID. A neutral placeholder suppresses the warning while preserving
     * existing files outside form context.
     *
     * @param string $modname
     * @param \stdClass $moduleinfo Updated in place.
     * @param array $patch
     * @return void
     */
    private static function carry_forward_draft_file_pseudofield(string $modname, \stdClass $moduleinfo, array $patch): void {
        if (!in_array($modname, ['folder', 'resource'], true) || array_key_exists('files', $patch)) {
            return;
        }
        if (!property_exists($moduleinfo, 'files')) {
            $moduleinfo->files = 0;
        }
    }

    /**
     * choice option/limit/optionid live in choice_options rather than the
     * instance row, so get_moduleinfo_data() omits them. Reconstruct them as
     * mod_choice_mod_form::data_preprocessing() does, avoiding undefined
     * option warnings while preserving options when the patch omits them.
     *
     * @param string $modname
     * @param \stdClass $moduleinfo Updated in place.
     * @param \stdClass $cm
     * @param array $patch
     * @return void
     */
    private static function carry_forward_choice_options(string $modname, \stdClass $moduleinfo, \stdClass $cm, array $patch): void {
        global $DB;

        if ($modname !== 'choice' || array_key_exists('option', $patch)) {
            return;
        }
        $texts = $DB->get_records_menu('choice_options', ['choiceid' => $cm->instance], 'id', 'id,text');
        $limits = $DB->get_records_menu('choice_options', ['choiceid' => $cm->instance], 'id', 'id,maxanswers');
        if (!$texts) {
            return;
        }
        $ids = array_keys($texts);
        $moduleinfo->option = array_values($texts);
        $moduleinfo->limit = array_values($limits);
        $moduleinfo->optionid = $ids;
    }
}
