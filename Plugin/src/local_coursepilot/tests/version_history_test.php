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

use local_coursepilot\history\version_history;
use local_coursepilot\history\version_source;
use local_coursepilot\history\version_writer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Lesende Oberflaeche des Aenderungsverlaufs (#394, Spec 0015 §10.6):
 * list_versions (Einzeiler serverseitig berechnet) und compare (volles
 * Diff zweier frei gewaehlter Staende).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_history::class)]
final class version_history_test extends \advanced_testcase {

    /**
     * Legt einen Kurs samt Seiten-Aktivitaet und editierender Lehrkraft an.
     * Die Erstanlage feuert bereits course_module_created (Version 1, Quelle
     * "moodle" - #386, Spec 0015 §10.3).
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
     */
    private function create_page(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Erste Fassung',
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm, $teacher];
    }

    /**
     * Aendert Name und Sichtbarkeit der Seite ueber den Formularweg -
     * loest course_module_updated aus, damit version_writer eine weitere
     * Version anlegt.
     *
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param string $name
     * @return void
     */
    private function update_page(\stdClass $course, \stdClass $cm, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        $moduleinfo->name = $name;
        $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        \update_moduleinfo($cm, $moduleinfo, $course, null);
    }

    /**
     * Simuliert eine Bestandsaktivitaet, die es schon vor Coursepilot gab:
     * loescht die bereits vorhandene Version 1 (aus dem Anlegen), sodass der
     * naechste Schreibvorgang die Vorgefunden-Backfill-Logik ausloest
     * (#386, Spec 0015 §10.3).
     *
     * @param int $cmid
     * @return void
     */
    private function simulate_legacy_activity(int $cmid): void {
        global $DB;

        $versionids = $DB->get_fieldset_select('local_coursepilot_cm_version', 'id', 'cmid = ?', [$cmid]);
        [$insql, $inparams] = $DB->get_in_or_equal($versionids);
        $DB->delete_records_select('local_coursepilot_cm_version_file', "versionid $insql", $inparams);
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cmid]);
    }

    /**
     * Abnahmekriterium: Version 1 ist als vorgefunden erkennbar, sowohl im
     * "quelle"-Feld als auch im Einzeiler.
     */
    public function test_legacy_version_one_is_marked_as_discovered(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->simulate_legacy_activity($cm->id);

        $this->update_page($course, $cm, 'Nach Coursepilot-Einfuehrung');

        $result = version_history::list_versions($cm->id);
        $this->assertCount(2, $result['versions']);

        $v1 = $result['versions'][0];
        $this->assertSame(1, $v1['version']);
        $this->assertSame('discovered', $v1['source']);
        $this->assertTrue($v1['discovered']);
        $this->assertStringContainsString('starting state found before Coursepilot', $v1['summary_line']);

        $v2 = $result['versions'][1];
        $this->assertSame(2, $v2['version']);
        $this->assertFalse($v2['discovered']);
    }

    /**
     * Eine Aktivitaet, die erst nach Coursepilot angelegt wurde, hat eine
     * "moodle"-Version 1 - keine falsch positive Vorgefunden-Markierung.
     */
    public function test_freshly_created_activity_version_one_is_not_discovered(): void {
        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $result = version_history::list_versions($cm->id);
        $this->assertCount(1, $result['versions']);
        $this->assertFalse($result['versions'][0]['discovered']);
        $this->assertSame('moodle', $result['versions'][0]['source']);
    }

    public function test_tool_history_stays_english_for_a_german_user(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $CFG->langotherroot = $CFG->libdir . '/tests/fixtures/langtest';
        get_string_manager(true);
        [$course, $cm] = $this->create_page();
        force_current_language('de');
        $this->assertSame('de', current_language());
        $this->update_page($course, $cm, 'Second version');
        $DB->set_field('local_coursepilot_cm_version', 'userid', 0, ['cmid' => $cm->id]);

        $result = version_history::list_versions($cm->id);
        $this->assertStringContainsString('first recorded state', $result['versions'][0]['summary_line']);
        $this->assertSame('User #0', $result['versions'][0]['user']);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $result['versions'][0]['summary_line']);
        $this->assertStringContainsString('changed', $result['versions'][1]['summary_line']);
        $this->assertStringContainsString('The history is incomplete', $result['gap_notice']);
        $comparison = version_history::compare($cm->id, 1, 2);
        $this->assertSame($result['gap_notice'], $comparison['gap_notice']);
        $this->assertSame('User #0', $comparison['before']['user']);
    }

    /**
     * Abnahmekriterium 1+2: der Einzeiler nennt wer/wann/wodurch und wird
     * serverseitig aus den Vollstaenden berechnet (das geaenderte Feld
     * "name" taucht im Einzeiler auf, ohne dass der Aufrufer es mitgibt).
     */
    public function test_einzeiler_names_who_when_and_changed_field(): void {
        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_page();

        $this->update_page($course, $cm, 'Zweite Fassung');

        $result = version_history::list_versions($cm->id);
        $einzeiler = $result['versions'][1]['summary_line'];

        $this->assertStringContainsString(fullname($teacher), $einzeiler);
        $this->assertStringContainsString('name', $einzeiler);
    }

    /**
     * Abnahmekriterium 5: die Antwort weist die bekannten Luecken des
     * Verlaufs aus - fest, nicht pro Version berechnet.
     */
    public function test_list_includes_fixed_gaps_hint(): void {
        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $result = version_history::list_versions($cm->id);
        $this->assertStringContainsString('gradebook', $result['gap_notice']);
        $this->assertStringContainsString('Restore', $result['gap_notice']);
        $this->assertStringContainsString('quiz', $result['gap_notice']);
        $this->assertStringContainsString('database writes', $result['gap_notice']);
    }

    /**
     * Abnahmekriterium 3: compare vergleicht zwei beliebige, nicht nur
     * benachbarte Staende.
     */
    public function test_compare_diffs_non_adjacent_versions(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();

        $this->update_page($course, $cm, 'Zweite Fassung');
        $this->update_page($course, $cm, 'Dritte Fassung');

        $this->assertCount(3, version_history::list_versions($cm->id)['versions']);

        $result = version_history::compare($cm->id, 1, 3);
        $this->assertSame(1, $result['before']['version']);
        $this->assertSame(3, $result['after']['version']);

        $namefield = null;
        foreach ($result['changes'] as $change) {
            if ($change['field'] === 'name') {
                $namefield = $change;
            }
        }
        $this->assertNotNull($namefield, 'Feld "name" muss im Diff auftauchen.');
        $this->assertSame(json_encode('Erste Fassung'), $namefield['before_json']);
        $this->assertSame(json_encode('Dritte Fassung'), $namefield['after_json']);
        $this->assertStringContainsString('gradebook', $result['gap_notice']);
    }

    /**
     * Ein Vergleich mit einer nicht existierenden Version scheitert mit
     * einer Meldung statt einem stillen leeren Ergebnis.
     */
    public function test_compare_with_unknown_version_throws(): void {
        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $this->expectException(\moodle_exception::class);
        version_history::compare($cm->id, 1, 99);
    }

    /**
     * Dateiaenderungen zwischen zwei Staenden werden ausgewiesen - direkte
     * Manipulation der Datei-Verknuepfungstabellen, um den Diff-Pfad ohne
     * echten Draft-Datei-Upload zu pruefen.
     */
    public function test_compare_reports_added_and_removed_files(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $CFG->langotherroot = $CFG->libdir . '/tests/fixtures/langtest';
        get_string_manager(true);
        [$course, $cm] = $this->create_page();
        $this->update_page($course, $cm, 'Zweite Fassung');

        $versionids = array_values($DB->get_records_menu(
            'local_coursepilot_cm_version',
            ['cmid' => $cm->id],
            'version ASC',
            'version, id'
        ));
        [$version1id, $version2id] = $versionids;

        $oldfileid = $DB->insert_record('local_coursepilot_cm_file', (object) [
            'pathnamehash' => sha1('old'),
            'contenthash' => sha1('old-content'),
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'alt.pdf',
            'filesize' => 10,
            'mimetype' => 'application/pdf',
            'timemodified' => time(),
        ]);
        $newfileid = $DB->insert_record('local_coursepilot_cm_file', (object) [
            'pathnamehash' => sha1('new'),
            'contenthash' => sha1('new-content'),
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'neu.pdf',
            'filesize' => 20,
            'mimetype' => 'application/pdf',
            'timemodified' => time(),
        ]);
        $DB->insert_record('local_coursepilot_cm_version_file', (object) [
            'versionid' => $version1id,
            'fileid' => $oldfileid,
            'gap' => 1,
        ]);
        $DB->insert_record('local_coursepilot_cm_version_file', (object) [
            'versionid' => $version2id,
            'fileid' => $newfileid,
            'gap' => 1,
        ]);

        $result = version_history::compare($cm->id, 1, 2);
        $aenderungen = $result['files'];

        $this->assertCount(2, $aenderungen);
        $entfernt = array_values(array_filter($aenderungen, static fn(array $c): bool => $c['change_type'] === 'removed'));
        $hinzugefuegt = array_values(array_filter($aenderungen, static fn(array $c): bool => $c['change_type'] === 'added'));
        $this->assertSame('alt.pdf', $entfernt[0]['filename']);
        $this->assertSame('neu.pdf', $hinzugefuegt[0]['filename']);

        $summary = version_history::list_versions($cm->id)['versions'][1]['summary_line'];
        $this->assertStringContainsString('1 file added', $summary);
        $this->assertStringContainsString('1 file removed', $summary);

        $secondfile = $DB->get_record('local_coursepilot_cm_file', ['id' => $newfileid], '*', MUST_EXIST);
        unset($secondfile->id);
        $secondfile->pathnamehash = sha1('second');
        $secondfile->contenthash = sha1('second-content');
        $secondfile->filename = 'second.pdf';
        $secondfileid = $DB->insert_record('local_coursepilot_cm_file', $secondfile);
        $DB->insert_record('local_coursepilot_cm_version_file', (object) [
            'versionid' => $version2id,
            'fileid' => $secondfileid,
            'gap' => 1,
        ]);
        $rows = version_history::list_versions($cm->id)['versions'];
        $this->assertStringContainsString('2 files added', $rows[1]['summary_line']);
        $reverse = version_history::list_versions($cm->id, 'de')['versions'];
        $this->assertStringContainsString('2 Dateien hinzugefügt', $reverse[1]['summary_line']);
    }

    /**
     * Grundlage der Aktivitaetenliste auf history.php (#397): eine
     * Aktivitaet mit erfasstem Verlauf erscheint, mit Name und Aktivitaetstyp.
     */
    public function test_course_activities_lists_activity_with_history(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();

        $activities = version_history::course_activities($course->id);

        $this->assertCount(1, $activities);
        $this->assertSame((int) $cm->id, $activities[0]['cmid']);
        $this->assertSame($cm->name, $activities[0]['name']);
        $this->assertSame('page', $activities[0]['modname']);
    }

    /**
     * Ein Kurs ohne jeden Schreibvorgang hat eine leere Aktivitaetenliste -
     * kein Fehler, keine Platzhalterzeile.
     */
    public function test_course_activities_empty_without_history(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame([], version_history::course_activities($course->id));
    }

    /**
     * Verlaufszeilen einer zwischenzeitlich geloeschten Aktivitaet duerfen
     * die Aktivitaetenliste eines anderen Kurses nicht crashen lassen -
     * sie werden stillschweigend uebersprungen (#387: die Kurs-Kaskade
     * greift nur beim ganzen Kurs).
     */
    public function test_course_activities_skips_deleted_activity(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        // Direkter DB-Eingriff statt course_delete_module(): dessen
        // eigentliche Loeschung laeuft asynchron ueber eine Ad-hoc-Aufgabe,
        // im Test soll nur der Zustand "Verlaufszeile ohne Aktivitaet mehr"
        // simuliert werden.
        $DB->delete_records('course_modules', ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);

        $this->assertSame([], version_history::course_activities($course->id));
    }

    /**
     * #596: Vermerk-Stand an der alten cmid - Quelle "superseded", Bezug = neue
     * cmid ueber sourcecmid (keine Schemaaenderung), Einzeiler nennt den Bezug.
     */
    public function test_superseded_marker_references_new_cmid(): void {
        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_page();
        $new = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->setUser($teacher);

        version_writer::capture_superseded((int) $cm->id, (int) $new->cmid, (int) $teacher->id);

        $rows = version_history::list_versions($cm->id)['versions'];
        $marker = end($rows);
        $this->assertSame('superseded', $marker['source']);
        $this->assertSame((int) $new->cmid, $marker['source_cmid']);
        $this->assertFalse($marker['discovered']);
        $this->assertStringContainsString('superseded by activity ' . $new->cmid, $marker['summary_line']);
    }

    /**
     * #596: Quelle "from_xml" als Begriff - Schluessel und Beschriftung.
     */
    public function test_from_xml_source_is_described(): void {
        $this->resetAfterTest();
        [, $cm, $teacher] = $this->create_page();
        $this->setUser($teacher);

        version_writer::capture((int) $cm->id, (int) $teacher->id, version_writer::SOURCE_FROM_XML);

        $rows = version_history::list_versions($cm->id)['versions'];
        $this->assertSame('from_xml', end($rows)['source']);
        $this->assertSame('created from activity XML', (new version_source('from_xml'))->label());
        $this->assertSame('clone of activity 7', (new version_source('cloned', 7))->label());
        $this->assertSame('unknown_source', (new version_source('unknown_source'))->label());
    }

    /**
     * #596: der Hinweis nennt die Luecke bei erschlossenen Arten ehrlich.
     */
    public function test_gap_notice_names_instance_row_only_gap_for_catalogued_kinds(): void {
        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $notice = version_history::list_versions($cm->id)['gap_notice'];
        $this->assertStringContainsString('activity types created from XML', $notice);
        $this->assertStringContainsString('only the instance row', $notice);
    }
}
