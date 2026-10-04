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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_coursepilot\activity_backup;
use local_coursepilot\course_module_placement;
use local_coursepilot\history\retention;
use local_coursepilot\history\version_writer;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Cloning (Spec 0017 §7.5, #421): one endpoint for both Moodle paths.
 * Within-course and cross-course clones use single-activity backup
 * (MODE_IMPORT), restored immediately into the target (TARGET_CURRENT_ADDING),
 * the same primitives used by duplicate_module() in course/lib.php.
 * We do not call that Moodle 5.2 deprecated function (#391, MDL-86854;
 * see no_deprecated_move_functions_test.php), just as move_module.php uses
 * stateactions::cm_move() instead of moveto_module(). Set title and visibility
 * explicitly, without an automatic copy suffix or inherited visibility.
 * The backup/restore model was the old clone_activity_to_course endpoint.
 *
 * {@see activity_backup} owns backup/restore (Spec 0026, #588).
 *
 * Dangling prerequisites (#332): cross-course single-activity restore
 * cannot translate completion cmid references outside its backup and
 * sets them to 0, potentially hiding the activity from everyone.
 * {@see self::cleanup_dangling_availability()} removes exactly these
 * completion/cm=0 conditions and reports them; the spec's only deletion.
 *
 * History (ADR 0018): replace all intermediate observer snapshots for the
 * new cmid with one version 1, source cloned and source module ID (#421).
 * This makes the result independent of Moodle's intermediate events.
 *
 * Declared directly in English (#572, Spec 0025 §A): message replaces meldung.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class clone_activity extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der zu klonenden Aktivitaet'),
            'title' => new external_value(
                PARAM_TEXT,
                'Titel der geklonten Aktivitaet - wird immer explizit gesetzt, kein "(Kopie)"-Suffix'
            ),
            'targetcourseid' => new external_value(
                PARAM_INT,
                'Ziel-Kurs-ID; weggelassen oder gleich dem Quellkurs = Klon im selben Kurs',
                VALUE_DEFAULT,
                0
            ),
            'visible' => new external_value(
                PARAM_BOOL,
                'Sichtbarkeit der geklonten Aktivitaet, immer explizit gesetzt',
                VALUE_DEFAULT,
                true
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param string $title
     * @param int $targetcourseid
     * @param bool $visible
     * @return array
     * @throws invalid_parameter_exception
     * @throws moodle_exception clonenobackupsupport
     */
    public static function execute(int $cmid, string $title, int $targetcourseid = 0, bool $visible = true): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'title' => $title,
            'targetcourseid' => $targetcourseid,
            'visible' => $visible,
        ]);

        $title = trim($params['title']);
        if ($title === '') {
            throw new invalid_parameter_exception('title darf nicht leer sein.');
        }

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $sourcecourseid = (int) $cm->course;
        $newtargetcourseid = $params['targetcourseid'] > 0 ? $params['targetcourseid'] : $sourcecourseid;
        $crosscourse = $newtargetcourseid !== $sourcecourseid;

        self::authorize($cm, $sourcecourseid, $newtargetcourseid, $crosscourse);

        // Single-activity backup (MODE_IMPORT) and restore (TARGET_CURRENT_ADDING)
        // use activity_backup for both paths; see the class documentation.
        $newcmid = activity_backup::restore($newtargetcourseid, null, activity_backup::backup($cm));

        set_coursemodule_name($newcmid, $title);
        course_module_placement::set_visible($newcmid, $visible);

        // Dangling prerequisites (#332) arise only across courses. Cleanup is
        // harmless even unconditionally (it only removes cm=0 conditions),
        // but within-course clones never need it.
        $removedmessage = $crosscourse ? self::cleanup_dangling_availability($newcmid, $cm) : null;

        rebuild_course_cache($newtargetcourseid, true);

        // History: discard intermediate observer snapshots and replace them
        // with one version carrying the correct origin (#421).
        retention::purge_cm($newcmid);
        version_writer::capture($newcmid, (int) $USER->id, version_writer::SOURCE_CLONED, (int) $cm->id);

        return [
            'cmid' => $newcmid,
            'courseid' => $newtargetcourseid,
            'message' => self::build_message($title, $crosscourse, $removedmessage),
        ];
    }

    /**
     * Check capabilities in both source and target courses (#421 criterion 3),
     * plus backup/restore capabilities for cross-course clones. Within-course
     * clones check the same context twice to keep both paths consistent.
     *
     * FEATURE_BACKUP_MOODLE2 applies to both paths: {@see activity_backup}
     * always uses backup/restore. Otherwise a non-backup-capable module would
     * throw a raw backup_controller exception instead of the localized error.
     *
     * @param \stdClass $cm
     * @param int $sourcecourseid
     * @param int $targetcourseid
     * @param bool $crosscourse
     * @return void
     * @throws moodle_exception clonenobackupsupport
     */
    private static function authorize(\stdClass $cm, int $sourcecourseid, int $targetcourseid, bool $crosscourse): void {
        $sourcecontext = context_course::instance($sourcecourseid);
        self::validate_context($sourcecontext);
        require_capability('local/coursepilot:use', $sourcecontext);
        require_capability('moodle/course:manageactivities', $sourcecontext);

        $targetcontext = context_course::instance($targetcourseid);
        self::validate_context($targetcontext);
        require_capability('local/coursepilot:use', $targetcontext);
        require_capability('moodle/course:manageactivities', $targetcontext);

        if ($crosscourse) {
            require_capability('moodle/backup:backuptargetimport', $sourcecontext);
            require_capability('moodle/restore:restoretargetimport', $targetcontext);
        }

        if (!plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2)) {
            throw new moodle_exception('clonenobackupsupport', 'local_coursepilot', '', ['modname' => $cm->modname]);
        }
    }

    /**
     * Detects completion conditions whose cmid could not be translated during
     * cross-course restore (#332). availability_completion\condition::
     * update_after_restore() sets cmid to 0 if its activity was not restored.
     * Remove such conditions and describe them using the source tree captured
     * before cloning, where the original cmid is still available.
     *
     * @param int $newcmid
     * @param \stdClass $sourcecm
     * @return string|null Message about removed conditions, null if none.
     */
    private static function cleanup_dangling_availability(int $newcmid, \stdClass $sourcecm): ?string {
        global $DB;

        $newcm = $DB->get_record('course_modules', ['id' => $newcmid], '*', MUST_EXIST);
        if ((string) $newcm->availability === '') {
            return null;
        }

        $tree = json_decode((string) $newcm->availability, true);
        if (!is_array($tree)) {
            return null;
        }

        $sourcetree = null;
        if ((string) ($sourcecm->availability ?? '') !== '') {
            $decoded = json_decode((string) $sourcecm->availability, true);
            $sourcetree = is_array($decoded) ? $decoded : null;
        }

        $removed = [];
        $cleaned = self::strip_dangling_completion($tree, $sourcetree, $removed);
        if (!$removed) {
            return null;
        }

        $newjson = $cleaned === null
            ? ''
            : json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $DB->set_field('course_modules', 'availability', $newjson, ['id' => $newcmid]);

        return self::build_removed_message($removed);
    }

    /**
     * Recursively cleans nested AND/OR groups produced by native forms, not
     * only the flat tree from {@see set_restriction}. Remove groups emptied
     * by cleanup instead of leaving empty wrappers.
     *
     * @param array $node
     * @param array|null $sourcenode The same source-tree node before cloning, used for the message.
     * @param array $removed By reference: message for each removed condition.
     * @return array|null null if the node or entire tree became empty.
     */
    private static function strip_dangling_completion(array $node, ?array $sourcenode, array &$removed): ?array {
        if (!isset($node['c']) || !is_array($node['c'])) {
            return $node;
        }

        $newc = [];
        $newshowc = [];
        foreach ($node['c'] as $i => $child) {
            $sourcechild = (is_array($sourcenode['c'] ?? null) && isset($sourcenode['c'][$i]) && is_array($sourcenode['c'][$i]))
                ? $sourcenode['c'][$i]
                : null;

            if (is_array($child) && isset($child['op'])) {
                $cleanedchild = self::strip_dangling_completion($child, $sourcechild, $removed);
                if ($cleanedchild === null) {
                    continue;
                }
                $newc[] = $cleanedchild;
                $newshowc[] = $node['showc'][$i] ?? true;
                continue;
            }

            if (is_array($child) && \local_coursepilot\cm_references::is_dangling_completion($child)) {
                $removed[] = self::describe_removed_condition($sourcechild);
                continue;
            }

            $newc[] = $child;
            $newshowc[] = $node['showc'][$i] ?? true;
        }

        if (!$newc) {
            return null;
        }

        $node['c'] = $newc;
        $node['showc'] = $newshowc;
        return $node;
    }

    /**
     * Teacher-facing description of the removed condition, naming the originally
     * referenced activity when the source tree retains it. The new tree only
     * knows cm: 0.
     *
     * @param array|null $sourcechild
     * @return string
     */
    private static function describe_removed_condition(?array $sourcechild): string {
        $status = self::completion_label((int) ($sourcechild['e'] ?? 1));

        if ($sourcechild !== null && !empty($sourcechild['cm'])) {
            $sourceactivity = get_coursemodule_from_id('', (int) $sourcechild['cm'], 0, false, IGNORE_MISSING);
            if ($sourceactivity) {
                return "Abschlussbedingung auf \"{$sourceactivity->name}\" ({$status}) - "
                    . 'die referenzierte Aktivität wurde beim kursübergreifenden Klonen nicht mitkopiert';
            }
        }

        return "Abschlussbedingung auf eine nicht mitkopierte Aktivität ({$status})";
    }

    /**
     * @param int $expectedcompletion COMPLETION_xx value from completionlib.php
     * @return string
     */
    private static function completion_label(int $expectedcompletion): string {
        return match ($expectedcompletion) {
            2 => 'bestanden',
            3 => 'nicht bestanden',
            0 => 'nicht abgeschlossen',
            default => 'abgeschlossen',
        };
    }

    /**
     * @param array $removed
     * @return string
     */
    private static function build_removed_message(array $removed): string {
        return count($removed) === 1
            ? '1 kaputte Voraussetzung entfernt: ' . $removed[0] . '.'
            : count($removed) . ' kaputte Voraussetzungen entfernt: ' . implode('; ', $removed) . '.';
    }

    /**
     * @param string $title
     * @param bool $crosscourse
     * @param string|null $removedmessage
     * @return string
     */
    private static function build_message(string $title, bool $crosscourse, ?string $removedmessage): string {
        $basis = $crosscourse
            ? "Aktivität als \"{$title}\" in den Zielkurs geklont."
            : "Aktivität als \"{$title}\" im selben Kurs geklont.";

        return $removedmessage !== null ? $basis . ' ' . $removedmessage : $basis;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der neuen (geklonten) Aktivitaet'),
            'courseid' => new external_value(PARAM_INT, 'Kurs, in dem der Klon liegt'),
            'message' => new external_value(
                PARAM_RAW,
                'Lehrkraft-deutsche Meldung; nennt entfernte kaputte Voraussetzungen im Klartext, falls vorhanden'
            ),
        ]);
    }
}
