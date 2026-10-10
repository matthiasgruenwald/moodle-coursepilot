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

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Per-entry Core form write, with independent rollback and no existing-entry reads (#593).
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class glossary_entry_writer {
    /**
     * @param array $input Validated External entry
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param \stdClass $glossary
     * @param \context_module $context
     * @return array
     */
    public static function add(
        array $input,
        \stdClass $course,
        \stdClass $cm,
        \stdClass $glossary,
        \context_module $context
    ): array {
        global $CFG, $DB;
        try {
            require_once($CFG->libdir . '/formslib.php');
            $required = new \MoodleQuickForm_Rule_Required();
            $concept = trim($input['concept']);
            if (!$required->validate($concept) || !$required->validate(trim($input['definition']))) {
                throw new \moodle_exception('glossaryentryrequired', 'local_coursepilot');
            }
            if (!in_array((int) $input['definitionformat'], [0, 1, 2, 4], true)) {
                throw new \moodle_exception('glossaryentryformat', 'local_coursepilot');
            }
            foreach (explode("\n", implode("\n", $input['aliases'])) as $alias) {
                $alias = trim($alias);
                // Match the reserved single-character keywords rejected by Moodle's entry form.
                if (strlen($alias) === 1 && preg_match('/[$-\\/:-?{-~!"^_`\\[\\]]/', $alias)) {
                    throw new \moodle_exception('errreservedkeywords', 'glossary');
                }
            }
            if ($input['tags'] && !\core_tag_tag::is_enabled('mod_glossary', 'glossary_entries')) {
                throw new \moodle_exception('glossaryentrytagsdisabled', 'local_coursepilot');
            }
            if (
                $input['tags'] && \core_tag_area::get_showstandard('mod_glossary', 'glossary_entries')
                == \core_tag_tag::STANDARD_ONLY
            ) {
                $collection = \core_tag_area::get_collection('mod_glossary', 'glossary_entries');
                foreach ($input['tags'] as $tagname) {
                    $tag = \core_tag_tag::get_by_name($collection, clean_param($tagname, PARAM_TAG), 'id, isstandard');
                    if (!$tag || !$tag->isstandard) {
                        throw new \moodle_exception('glossaryentrystandardtags', 'local_coursepilot');
                    }
                }
            }
            if (!$glossary->allowduplicatedentries && glossary_concept_exists($glossary, $concept)) {
                throw new \moodle_exception('errconceptalreadyexists', 'glossary');
            }
            if (array_key_exists('approved', $input)) {
                require_capability('mod/glossary:approve', $context);
            }
            $transaction = $DB->start_delegated_transaction();
            try {
                $entry = (object) [
                    'id' => null, 'concept' => $concept,
                    'definition_editor' => ['text' => $input['definition'], 'format' => $input['definitionformat']],
                    'aliases' => implode("\n", $input['aliases']),
                    'categories' => self::categories($input['categories'], $glossary, $context),
                ];
                foreach (
                    ['usedynalink' => 'glossary_linkentries', 'casesensitive' => 'glossary_casesensitive',
                    'fullmatch' => 'glossary_fullmatch'] as $field => $setting
                ) {
                    $entry->$field = $glossary->usedynalink ? ($input[$field] ?? $CFG->$setting) : $CFG->$setting;
                }
                self::files($input, $entry, $course, $context);
                $saved = glossary_edit_entry($entry, $course, $cm, $glossary, $context);
                self::approval($input, $saved, $course, $cm, $glossary, $context);
                if ($input['tags']) {
                    \core_tag_tag::set_item_tags('mod_glossary', 'glossary_entries', $saved->id, $context, $input['tags']);
                }
                $transaction->allow_commit();
                return ['success' => true, 'entryid' => (int) $saved->id, 'approved' => (bool) $saved->approved,
                    'errorcode' => '', 'message' => ''];
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } catch (\moodle_exception $e) {
            // Database/debug details must never enter the teacher or model context.
            return ['success' => false, 'entryid' => 0, 'approved' => false,
                'errorcode' => $e->errorcode, 'message' => $e instanceof \dml_exception
                    ? get_string('glossaryentryfailed', 'local_coursepilot') : $e->getMessage()];
        }
    }

    /** Explicit state follows mod/glossary/approve.php, limited to this new entry. */
    private static function approval(
        array $input,
        \stdClass $entry,
        \stdClass $course,
        \stdClass $cm,
        \stdClass $glossary,
        \context_module $context
    ): void {
        global $DB;
        if (!array_key_exists('approved', $input) || (bool) $entry->approved === (bool) $input['approved']) {
            return;
        }
        $entry->approved = (int) $input['approved'];
        $entry->timemodified = time();
        $DB->update_record('glossary_entries', (object) [
            'id' => $entry->id, 'approved' => $entry->approved, 'timemodified' => $entry->timemodified,
        ]);
        $event = $entry->approved ? \mod_glossary\event\entry_approved::create([
            'context' => $context, 'objectid' => $entry->id,
        ]) : \mod_glossary\event\entry_disapproved::create([
            'context' => $context, 'objectid' => $entry->id,
        ]);
        $event->add_record_snapshot('glossary_entries', $entry);
        $event->trigger();
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && $glossary->completionentries) {
            // Disapproval must recalculate: COMPLETE may otherwise retain the initial Core approval.
            $completion->update_state($cm, $entry->approved ? COMPLETION_COMPLETE : COMPLETION_UNKNOWN, $entry->userid);
        }
        if ($entry->usedynalink) {
            \mod_glossary\local\concept_cache::reset_glossary($glossary);
        }
    }

    /** Copy teacher material through the shared location-aware draft path. */
    private static function files(
        array $input,
        \stdClass $entry,
        \stdClass $course,
        \context_module $context
    ): void {
        global $CFG;
        foreach (['definition_files' => 'entry', 'attachment_files' => 'attachment'] as $field => $area) {
            if (!$input[$field]) {
                continue;
            }
            material_files::require_manage_own_files();
            $draftid = material_files::resolve_into_draft(
                $context->id,
                'mod_glossary',
                $area,
                0,
                $input[$field],
                $input['location']
            );
            $files = get_file_storage()->get_area_files(
                material_files::own_context()->id,
                'user',
                'draft',
                $draftid,
                'id',
                false
            );
            $maxbytes = get_max_upload_file_size($CFG->maxbytes, $course->maxbytes);
            if (count($files) > 99) {
                throw new \moodle_exception('glossaryentryfilelimit', 'local_coursepilot');
            }
            foreach ($files as $file) {
                if ($maxbytes > 0 && $file->get_filesize() > $maxbytes) {
                    throw new \moodle_exception('glossaryentryfilelimit', 'local_coursepilot');
                }
            }
            if ($area === 'entry') {
                $entry->definition_editor['itemid'] = $draftid;
            } else {
                $entry->attachment_filemanager = $draftid;
            }
        }
    }

    /** Category metadata only; creation follows mod/glossary/editcategories.php. */
    private static function categories(array $names, \stdClass $glossary, \context_module $context): array {
        global $DB;
        $ids = [];
        foreach (array_unique($names) as $name) {
            $name = trim($name);
            if ($name === '') {
                throw new \moodle_exception('glossaryentrycategoryrequired', 'local_coursepilot');
            }
            $category = $DB->get_record_sql('SELECT * FROM {glossary_categories} WHERE glossaryid = ? AND '
                . $DB->sql_equal('name', '?', false), [$glossary->id, $name]);
            if (!$category) {
                require_capability('mod/glossary:managecategories', $context);
                $category = (object) ['glossaryid' => $glossary->id, 'name' => $name, 'usedynalink' => 0];
                $category->id = $DB->insert_record('glossary_categories', $category);
                $event = \mod_glossary\event\category_created::create([
                    'context' => $context, 'objectid' => $category->id,
                ]);
                $event->add_record_snapshot('glossary_categories', $category);
                $event->add_record_snapshot('glossary', $glossary);
                $event->trigger();
                \mod_glossary\local\concept_cache::reset_glossary($glossary);
            }
            $ids[] = (int) $category->id;
        }
        return array_values(array_unique($ids));
    }
}
