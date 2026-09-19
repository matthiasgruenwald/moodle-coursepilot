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
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use local_coursepilot\history\retention;
use local_coursepilot\history\version_writer;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Klonen (Spec 0017 §7.5, Ticket #421): ein Endpunkt fuer beide Moodle-Wege.
 * Derselbe Mechanismus fuer Intra-Kurs UND kursuebergreifend: Einzelaktivitaets-
 * Backup (MODE_IMPORT), sofort in den Zielkurs restauriert (TARGET_CURRENT_ADDING)
 * - genau die Primitiven, die Moodles eigenes duplicate_module() (course/lib.php)
 * intern selbst nutzt. duplicate_module() wird bewusst NICHT aufgerufen: es zaehlt
 * zu den in Moodle 5.2 deprecated Funktionen (Ticket #391, MDL-86854-Nachbarschaft,
 * siehe tests/external/no_deprecated_move_functions_test.php) - derselbe Grund, aus
 * dem move_module.php stateactions::cm_move() statt moveto_module() nutzt. Titel und
 * Sichtbarkeit werden danach immer explizit gesetzt (kein "(Kopie)"-Suffix, keine
 * geerbte/zufaellige Sichtbarkeit). Vorbild fuer den Backup/Restore-Teil: lokal
 * local_coursepilot\external\clone_activity_to_course (kursuebergreifender Pfad
 * des alten Plugins).
 *
 * Kaputte Voraussetzungen (#332): Moodle kann cmid-Verweise in
 * Abschlussbedingungen beim kursuebergreifenden Klonen nicht uebersetzen
 * (die Backup-Grundlage ist eine Einzelaktivitaet, nicht der ganze Kurs) und
 * setzt den Verweis auf 0 - ohne Bereinigung eine Aktivitaet, die fuer
 * niemanden sichtbar sein kann. {@see self::cleanup_dangling_availability()}
 * erkennt genau diesen Fall (type=completion, cm=0) und entfernt ihn, mit
 * Klartext-Meldung. Einzige Stelle dieses Specs, die etwas wegnimmt.
 *
 * Aenderungsverlauf (ADR 0018): unabhaengig davon, was der Beobachter waehrend
 * des Klonens (course_module_created/-updated) bereits mitgeschrieben hat,
 * wird der gesamte bisherige Verlauf der neuen cmid verworfen und durch genau
 * einen Stand ersetzt - Version 1, Quelle "geklont", Quell-Modul-ID gesetzt
 * (#421). Das macht den Endstand unabhaengig davon, wie viele
 * Zwischenereignisse Moodle intern beim Duplizieren/Restore feuert.
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

        $newcmid = self::clone_via_backup_restore($cm, $newtargetcourseid, (int) $USER->id);

        set_coursemodule_name($newcmid, $title);
        set_coursemodule_visible($newcmid, $visible ? 1 : 0);

        // Kaputte Voraussetzungen (#332) entstehen nur kursuebergreifend -
        // die Bereinigung selbst ist ungefaehrlich, auch unconditional
        // aufgerufen (sie entfernt ausschliesslich cm=0-Bedingungen, die es
        // beim Intra-Kurs-Klon nie gibt), aber nur dort noetig.
        $entferntemeldung = $crosscourse ? self::cleanup_dangling_availability($newcmid, $cm) : null;

        rebuild_course_cache($newtargetcourseid, true);

        // Aenderungsverlauf: verwirft, was der Beobachter waehrend des
        // Klonens bereits mitgeschrieben hat, und ersetzt es durch genau
        // einen Stand mit korrekter Herkunft (#421) - siehe Klassenkommentar.
        retention::purge_cm($newcmid);
        version_writer::capture($newcmid, (int) $USER->id, version_writer::SOURCE_GEKLONT, (int) $cm->id);

        return [
            'cmid' => $newcmid,
            'courseid' => $newtargetcourseid,
            'meldung' => self::build_message($title, $crosscourse, $entferntemeldung),
        ];
    }

    /**
     * Capability-Pruefung in Quell- UND Zielkurs (#421 Abnahmekriterium 3) -
     * kursuebergreifend zusaetzlich die Backup-/Restore-Rechte. Bei
     * Intra-Kurs sind Quell- und Zielkurs derselbe Kontext; die Pruefung
     * laeuft trotzdem fuer beide, damit das Verhalten unabhaengig vom Pfad
     * gleich bleibt.
     *
     * Der FEATURE_BACKUP_MOODLE2-Check gilt fuer BEIDE Pfade, nicht nur
     * kursuebergreifend: {@see self::clone_via_backup_restore()} nutzt
     * Einzelaktivitaets-Backup/Restore fuer Intra-Kurs genauso wie fuer
     * kursuebergreifend (siehe Klassenkommentar) - ohne diesen Check wuerde
     * ein nicht backup-faehiger Aktivitaetstyp im Intra-Kurs-Fall mit einer
     * rohen backup_controller-Ausnahme statt der lokalisierten Meldung
     * scheitern.
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
     * Einzelaktivitaets-Backup (MODE_IMPORT), sofort in den Zielkurs
     * importiert (TARGET_CURRENT_ADDING) - fuer BEIDE Pfade (Intra-Kurs und
     * kursuebergreifend), siehe Klassenkommentar zur Begruendung gegen
     * duplicate_module(). Die neue cmid wird wie bei duplicate_module()
     * selbst ueber den alten Modulkontext des restore_activity_task ermittelt
     * (Vorbild: local_coursepilot\external\clone_activity_to_course).
     *
     * @param \stdClass $cm
     * @param int $targetcourseid
     * @param int $userid
     * @return int neue cmid
     * @throws moodle_exception clonefailed
     */
    private static function clone_via_backup_restore(\stdClass $cm, int $targetcourseid, int $userid): int {
        global $CFG;

        $cmcontext = context_module::instance($cm->id);

        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $userid
        );
        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $bc->destroy();

        try {
            $newcmid = self::run_restore($backupid, $targetcourseid, $userid, (int) $cmcontext->id);
        } finally {
            if (empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($backupbasepath);
            }
        }

        return $newcmid;
    }

    /**
     * @param string $backupid
     * @param int $targetcourseid
     * @param int $userid
     * @param int $oldcmcontextid
     * @return int
     * @throws moodle_exception clonefailed
     */
    private static function run_restore(string $backupid, int $targetcourseid, int $userid, int $oldcmcontextid): int {
        $rc = new \restore_controller(
            $backupid,
            $targetcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $userid,
            \backup::TARGET_CURRENT_ADDING
        );

        if (!$rc->execute_precheck()) {
            $precheckresults = $rc->get_precheck_results();
            $rc->destroy();
            if (is_array($precheckresults) && !empty($precheckresults['errors'])) {
                throw new moodle_exception('backupprecheckerrors', 'backup', '', $precheckresults);
            }
            throw new moodle_exception('clonefailed', 'local_coursepilot');
        }

        $rc->execute_plan();

        $newcmid = null;
        foreach ($rc->get_plan()->get_tasks() as $task) {
            if (is_subclass_of($task, 'restore_activity_task') && $task->get_old_contextid() == $oldcmcontextid) {
                $newcmid = $task->get_moduleid();
                break;
            }
        }
        $rc->destroy();

        if (!$newcmid) {
            throw new moodle_exception('clonefailed', 'local_coursepilot');
        }

        return (int) $newcmid;
    }

    /**
     * Erkennt Abschlussbedingungen, deren cmid-Verweis Moodle beim
     * kursuebergreifenden Klonen nicht uebersetzen konnte (#332,
     * availability_completion\condition::update_after_restore() setzt
     * cmid auf 0, wenn das referenzierte Modul nicht mitrestauriert wurde),
     * entfernt sie aus dem "availability"-Baum und benennt sie im Klartext -
     * anhand des VOR dem Klonen gelesenen Quell-Baums, in dem die
     * urspruengliche cmid noch steht.
     *
     * @param int $newcmid
     * @param \stdClass $sourcecm
     * @return string|null Meldung ueber entfernte Bedingungen, null wenn keine.
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
     * Rekursiv, damit auch verschachtelte Bedingungsgruppen (native
     * Formularoberflaeche kann UND/ODER-Gruppen bauen, nicht nur der flache
     * Baum von {@see set_restriction}) bereinigt werden. Eine Gruppe, die
     * dadurch leer wird, faellt selbst weg statt als leere Huelle stehen zu
     * bleiben.
     *
     * @param array $node
     * @param array|null $sourcenode Derselbe Knoten im Quell-Baum vor dem Klonen (fuer die Meldung).
     * @param array $removed Referenz: Meldungstexte je entfernter Bedingung.
     * @return array|null null, wenn der Knoten (oder der ganze Baum) leer wurde.
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

            if (is_array($child) && ($child['type'] ?? null) === 'completion' && (int) ($child['cm'] ?? -1) === 0) {
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
     * Lehrkraft-deutsche Beschreibung der entfernten Bedingung - nennt die
     * ursprünglich referenzierte Aktivität, wenn der Quell-Baum sie noch
     * kennt (die neue Bedingung selbst weiss nur noch "cm: 0").
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
     * @param int $expectedcompletion COMPLETION_xx-Wert aus completionlib.php
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
     * @param string|null $entferntemeldung
     * @return string
     */
    private static function build_message(string $title, bool $crosscourse, ?string $entferntemeldung): string {
        $basis = $crosscourse
            ? "Aktivität als \"{$title}\" in den Zielkurs geklont."
            : "Aktivität als \"{$title}\" im selben Kurs geklont.";

        return $entferntemeldung !== null ? $basis . ' ' . $entferntemeldung : $basis;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der neuen (geklonten) Aktivitaet'),
            'courseid' => new external_value(PARAM_INT, 'Kurs, in dem der Klon liegt'),
            'meldung' => new external_value(
                PARAM_RAW,
                'Lehrkraft-deutsche Meldung; nennt entfernte kaputte Voraussetzungen im Klartext, falls vorhanden'
            ),
        ]);
    }
}
