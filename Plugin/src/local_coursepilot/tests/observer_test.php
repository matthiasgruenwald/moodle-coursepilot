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

use core_external\external_api;
use local_coursepilot\external\get_module_settings;
use local_coursepilot\history\version_writer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Aenderungsverlauf-Beobachter (#385, Spec 0015 §10): jede Handaenderung im
 * Modulformular loest ueber das native course_module_updated-Event einen
 * Schnappschuss aus.
 *
 * Ausgeloest wird der echte Moodle-Schreibweg direkt
 * (get_moduleinfo_data()/update_moduleinfo() aus course/modlib.php - dieselben
 * Funktionen, die auch das Modulformular ruft), nicht ueber lokale externe
 * Funktionen: local_coursepilot definiert keine schreibenden Webservices, gegen
 * die man testen koennte, und der Beobachter soll ohnehin unabhaengig davon
 * abgedeckt sein, ueber welchen Client geschrieben wurde (#385
 * Abnahmekriterium "eigener Pruefschnitt").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(observer::class)]
#[CoversClass(\local_coursepilot\history\version_writer::class)]
final class observer_test extends \advanced_testcase {

    /**
     * Legt einen Kurs samt Seiten-Aktivitaet und editierender Lehrkraft an.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Kurs, cm-Objekt, Lehrkraft
     */
    private function create_page(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Ausgangsstand',
            'content' => 'Ausgangstext.',
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm, $teacher];
    }

    /**
     * Simuliert die Handaenderung genau wie das Modulformular: den
     * vorbefuellten Ist-Stand holen, ein Feld aendern, ueber
     * update_moduleinfo() zurueckschreiben. Das ist der Aufruf, der real
     * course_module_updated ausloest.
     *
     * @param \stdClass $cm
     * @param \stdClass $course
     * @param string $newname
     * @return void
     */
    private function edit_via_module_form(\stdClass $cm, \stdClass $course, string $newname): void {
        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $moduleinfo->name = $newname;
        // mod_page_mod_form::data_preprocessing() mappt content/contentformat auf ein
        // eigenes 'page'-Editorfeld, das get_moduleinfo_data() (modulunabhaengig) nicht
        // kennt - ohne diese Zeile fehlt page_update_instance() das Feld, das jede echte
        // Formularabgabe mitliefert.
        if ($moduleinfo->modulename === 'page') {
            $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
            $moduleinfo->printintro = 0;
            $moduleinfo->printlastmodified = 1;
        }
        update_moduleinfo($cm, $moduleinfo, $course, null);
    }

    /**
     * Abnahmekriterium: Eine Handaenderung im Modulformular erzeugt einen
     * Stand mit Formularweg-Zustand und course_modules-Zeile.
     */
    public function test_hand_edit_creates_version_with_state_and_cm_row(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_page();

        // Version 1 entsteht schon beim Anlegen (#386, Spec 0015 §10.3).
        $this->assertSame(1, (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));

        $this->edit_via_module_form($cm, $course, 'Geaenderter Titel');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(2, $versions);

        $version = $versions[1];
        $this->assertSame(2, (int) $version->version);
        $this->assertSame('moodle', $version->source);
        $this->assertSame((int) $teacher->id, (int) $version->userid);

        $moduleinfo = json_decode($version->moduleinfo_json, true);
        $this->assertSame('Geaenderter Titel', $moduleinfo['name']);
        $this->assertSame('page', $moduleinfo['modulename']);
        $this->assertSame((int) $cm->id, $moduleinfo['coursemodule']);

        $cmrow = json_decode($version->coursemodule_json, true);
        $this->assertSame((int) $cm->id, (int) $cmrow['id']);
        $this->assertSame('page', $DB->get_field('modules', 'name', ['id' => (int) $cmrow['module']]));
    }

    /**
     * Zweite Handaenderung schreibt eine weitere Version fort - keine
     * Ueberschreibung, keine Rueckspulung.
     */
    public function test_second_hand_edit_appends_one_more_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();

        $this->edit_via_module_form($cm, $course, 'Erste Aenderung');
        $this->edit_via_module_form($cm, $course, 'Zweite Aenderung');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        // Version 1 = Anlegen, Version 2 = erste Aenderung, Version 3 = zweite Aenderung.
        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], array_map(fn ($v) => (int) $v->version, $versions));
        $this->assertSame('Zweite Aenderung', json_decode($versions[2]->moduleinfo_json, true)['name']);
    }

    /**
     * Abnahmekriterien: Datei-Zeilen des Modulkontexts werden erfasst,
     * Intro-Dateien sind rueckschreibbar (keine Luecke), Dateien ausserhalb
     * der Beschreibung sind als Luecke markiert.
     */
    public function test_files_outside_intro_are_marked_as_gap(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        $introfile = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'intro-bild.png',
        ], 'intro-bytes');

        $otherfile = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'anhang.pdf',
        ], 'other-bytes');

        $this->edit_via_module_form($cm, $course, 'Mit Dateien');

        // Version 1 stammt vom Anlegen, vor dem Hochladen der Dateien - die
        // Dateien landen erst in der Version der Handaenderung (Version 2).
        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id, 'version' => 2], '*', MUST_EXIST);

        $introrow = $DB->get_record('local_coursepilot_cm_file', ['pathnamehash' => $introfile->get_pathnamehash()], '*', MUST_EXIST);
        $introlink = $DB->get_record('local_coursepilot_cm_version_file', [
            'versionid' => $version->id,
            'fileid' => $introrow->id,
        ], '*', MUST_EXIST);
        $this->assertSame(0, (int) $introlink->gap, 'Intro-Datei darf nicht als Luecke markiert sein.');
        $this->assertSame('intro-bild.png', $introrow->filename);

        $otherrow = $DB->get_record('local_coursepilot_cm_file', ['pathnamehash' => $otherfile->get_pathnamehash()], '*', MUST_EXIST);
        $otherlink = $DB->get_record('local_coursepilot_cm_version_file', [
            'versionid' => $version->id,
            'fileid' => $otherrow->id,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $otherlink->gap, 'Datei ausserhalb der Beschreibung muss als Luecke markiert sein.');

        // Keine Bytes gespeichert - nur Metadaten-Spalten, "content" existiert nicht als Feld.
        $this->assertObjectNotHasProperty('content', $introrow);
    }

    /**
     * Abnahmekriterium: Datei-Metadaten sind dedupliziert - ein unveraenderter
     * Dateiname taucht ueber mehrere Staende hinweg nur einmal in
     * local_coursepilot_cm_file auf.
     */
    public function test_unchanged_file_is_not_duplicated_across_versions(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'bleibt-gleich.png',
        ], 'unveraendert');

        $this->edit_via_module_form($cm, $course, 'Version eins');
        $this->edit_via_module_form($cm, $course, 'Version zwei');

        // Version 1 = Anlegen (vor der Datei), Version 2/3 = die beiden Handaenderungen.
        $this->assertCount(3, $DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertCount(
            1,
            $DB->get_records('local_coursepilot_cm_file', ['filename' => 'bleibt-gleich.png']),
            'Unveraenderte Datei darf nur einmal in local_coursepilot_cm_file stehen.'
        );
        $this->assertCount(
            2,
            $DB->get_records('local_coursepilot_cm_version_file'),
            'Nur die beiden Staende nach dem Hochladen verweisen auf den Datei-Datensatz.'
        );
    }

    /**
     * Wird die Datei am gleichen Pfad inhaltlich ersetzt (gleicher
     * pathnamehash, anderer contenthash), muss der neue Stand die neuen
     * Metadaten zeigen statt der alten - Dedup darf keine veralteten
     * Metadaten liefern (#385 Codereview-Fund).
     */
    public function test_changed_file_content_gets_fresh_metadata_row(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'wird-ersetzt.png',
        ];
        $fs->create_file_from_string($filerecord, 'alter-inhalt');
        $this->edit_via_module_form($cm, $course, 'Version eins');

        // Gleicher Pfad, neuer Inhalt - wie ein erneuter Dateiupload im Formular.
        $fs->get_file($context->id, 'mod_page', 'intro', 0, '/', 'wird-ersetzt.png')->delete();
        $fs->create_file_from_string($filerecord, 'neuer-inhalt-laenger');
        $this->edit_via_module_form($cm, $course, 'Version zwei');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        // Version 1 = Anlegen (vor der Datei), Version 2 = "Version eins" (alte Datei),
        // Version 3 = "Version zwei" (neue Datei).
        $this->assertCount(3, $versions);

        $rows = array_values($DB->get_records('local_coursepilot_cm_file', ['filename' => 'wird-ersetzt.png'], 'id ASC'));
        $this->assertCount(2, $rows, 'Inhaltlich geaenderte Datei am gleichen Pfad braucht eine eigene Metadaten-Zeile.');
        $this->assertNotSame($rows[0]->contenthash, $rows[1]->contenthash);
        $this->assertNotSame($rows[0]->filesize, $rows[1]->filesize);

        $link2 = $DB->get_record('local_coursepilot_cm_version_file', ['versionid' => $versions[2]->id, 'fileid' => $rows[1]->id]);
        $this->assertNotFalse($link2, 'Die juengste Version muss auf die neue Metadaten-Zeile verweisen, nicht die veraltete.');
    }

    /**
     * Abnahmekriterium: der Stand enthaelt gradepass/gradecat/Outcomes, die
     * get_module_settings (#384) bewusst ausklammert - beide Werkzeuge duerfen
     * sich hier unterscheiden.
     */
    public function test_snapshot_includes_gradepass_unlike_read_tool(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/modlib.php');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);

        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $moduleinfo->gradepass = 50.0;
        update_moduleinfo($cm, $moduleinfo, $course, null);

        // Version 1 stammt vom Anlegen (ohne gradepass), Version 2 traegt den
        // gesetzten gradepass-Wert.
        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id, 'version' => 2], '*', MUST_EXIST);
        $snapshot = json_decode($version->moduleinfo_json, true);
        $hasgradepass = false;
        foreach ($snapshot as $field => $value) {
            if (str_starts_with($field, 'assigngradepass_') || $field === 'gradepass') {
                $hasgradepass = true;
            }
        }
        $this->assertTrue($hasgradepass, 'Der Verlaufs-Stand muss ein gradepass-Feld enthalten (Spec 0015 §10.4).');

        $readtool = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cm->id)
        );
        $readsettings = json_decode($readtool['settings_json'], true);
        foreach ($readsettings as $field => $value) {
            $this->assertFalse(
                str_starts_with((string) $field, 'assigngradepass_'),
                'get_module_settings klammert gradepass-Felder bewusst aus (#384).'
            );
        }
    }

    /**
     * Abnahmekriterium (#386): eine Aktivitaet, die nach Einfuehrung des
     * Verlaufs entsteht, bekommt Version 1 direkt beim Anlegen - als
     * regulaeren Stand, nicht als "vorgefunden".
     */
    public function test_new_activity_gets_version_one_on_create_not_vorgefunden(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertCount(1, $versions);
        $this->assertSame(1, (int) $versions[0]->version);
        $this->assertSame('moodle', $versions[0]->source);
    }

    /**
     * Abnahmekriterium (#386): kein Massen-Backfill beim Plugin-Upgrade -
     * ein course_modules-Datensatz, fuer den nie ein Ereignis beobachtet
     * wurde (Zustand vor Einfuehrung des Verlaufs bzw. waehrend eines
     * Plugin-Upgrades), erzeugt von sich aus keinen Stand.
     */
    public function test_plugin_upgrade_creates_no_versions(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        // Die Aktivitaet existiert bereits in der DB; ohne dass ein
        // course_module_*-Ereignis feuert (z.B. beim Plugin-Upgrade selbst),
        // darf sich der Verlauf nicht ruehren.
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $this->assertSame(0, (int) $DB->count_records('local_coursepilot_cm_version'));
    }

    /**
     * Abnahmekriterien (#386): fuer eine Aktivitaet, die es schon vor
     * Coursepilot gab (kein course_module_created je beobachtet, deshalb kein
     * Stand vorhanden), legt das erste course_module_updated zwei Versionen
     * an - Version 1 als vorgefunden gekennzeichnet, Version 2 mit dem neuen
     * Stand.
     */
    public function test_first_update_on_activity_without_history_backfills_vorgefunden_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_page();
        // Simuliert eine Bestandsaktivitaet: existiert bereits vollstaendig
        // (Kurs, Instanz, course_modules-Zeile, Kontext), aber ohne jeden
        // Verlaufseintrag - der Zustand, den ein pre-#386-Plugin hinterlaesst.
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $this->edit_via_module_form($cm, $course, 'Erste beobachtete Aenderung');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(2, $versions, 'Das erste Ereignis ohne Vorgeschichte muss zwei Versionen anlegen.');

        $this->assertSame(1, (int) $versions[0]->version);
        $this->assertSame(version_writer::SOURCE_VORGEFUNDEN, $versions[0]->source);
        $this->assertSame((int) $teacher->id, (int) $versions[0]->userid);

        $this->assertSame(2, (int) $versions[1]->version);
        $this->assertSame(version_writer::SOURCE_MOODLE, $versions[1]->source);

        // Das eigentliche Vorher (vor genau diesem Schreibvorgang) ist nicht
        // mehr rekonstruierbar - der Vorgefunden-Stand faengt deshalb densel-
        // ben (bereits geschriebenen) Ist-Stand wie Version 2 ein.
        $this->assertSame(
            json_decode($versions[1]->moduleinfo_json, true)['name'],
            json_decode($versions[0]->moduleinfo_json, true)['name']
        );
    }

    /**
     * Abnahmekriterium (#386): das zweite Ereignis derselben Aktivitaet
     * legt genau eine weitere Version an - keine erneute Vorgefunden-Version.
     */
    public function test_second_update_after_backfill_appends_exactly_one_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $this->edit_via_module_form($cm, $course, 'Erste beobachtete Aenderung');
        $this->edit_via_module_form($cm, $course, 'Zweite beobachtete Aenderung');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], array_map(fn ($v) => (int) $v->version, $versions));
        $this->assertSame(version_writer::SOURCE_VORGEFUNDEN, $versions[0]->source);
        $this->assertSame(version_writer::SOURCE_MOODLE, $versions[1]->source);
        $this->assertSame(version_writer::SOURCE_MOODLE, $versions[2]->source);
        $this->assertSame('Zweite beobachtete Aenderung', json_decode($versions[2]->moduleinfo_json, true)['name']);
    }

    /**
     * Abnahmekriterium (#396): eine Umsortierung der Fragen (slot_moved,
     * eines der 16 mod_quiz-Struktur-Ereignisse) erzeugt einen neuen Stand -
     * genau wie jede Handaenderung im Modulformular.
     */
    public function test_reordering_quiz_slots_creates_new_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        // Version 1 entsteht beim Anlegen (#386); die beiden slot_created-
        // Ereignisse beim Hinzufuegen der Fragen zaehlen bewusst nicht zu den
        // 16 beobachteten Struktur-Ereignissen (Inhalt, keine Anordnung).
        $before = (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $slots = array_values($DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot'));
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($slots[1]->id, 0, 1);

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount($before + 1, $versions);

        $newest = end($versions);
        $this->assertNotNull($newest->arrangement_json, 'Der neue Stand muss den Anordnungs-Stand mitschreiben.');
        $arrangement = json_decode($newest->arrangement_json, true);
        $this->assertSame((int) $slots[1]->id, $arrangement['slots'][0]['id']);
    }

    /**
     * Abnahmekriterium (#396): der Anordnungs-Stand enthaelt Slots (mit
     * Fragereferenz), Abschnitte und Feedback - fuer Nicht-quiz-Aktivitaeten
     * bleibt arrangement_json unveraendert null (keine Struktur-API dafuer).
     */
    public function test_non_quiz_activity_has_no_arrangement_json(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id], '*', MUST_EXIST);
        $this->assertNull($version->arrangement_json);
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Test (mit zwei Fragen).
     */
    private function create_quiz_with_two_questions(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $qbankcontext->id]);

        $question1 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        $question2 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question1->id, $quiz);
        quiz_add_quiz_question($question2->id, $quiz);

        return [$course, $quiz];
    }
}
