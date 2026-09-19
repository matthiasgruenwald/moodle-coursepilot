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

use core_external\external_api;
use local_coursepilot\history\version_writer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Klonen (Spec 0017 §7.5, Ticket #421): ein Endpunkt fuer beide Pfade,
 * kaputte Voraussetzungen werden erkannt und entfernt, der Aenderungsverlauf
 * bekommt genau einen Stand mit Herkunft "geklont".
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(clone_activity::class)]
final class clone_activity_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Lehrkraft (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * Abnahmekriterium: Intra-Kurs-Klon behaelt Plugin-Einstellungen (hier:
     * page-Inhalt) und bekommt den uebergebenen Titel, kein "(Kopie)"-Suffix.
     */
    public function test_intra_course_clone_preserves_settings_and_sets_title(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Original',
            'content' => 'Original-Inhalt',
        ]);

        $result = clone_activity::execute((int) $page->cmid, 'Klon von Original');
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $this->assertNotSame((int) $page->cmid, $result['cmid']);
        $this->assertSame((int) $course->id, $result['courseid']);

        $newcm = get_coursemodule_from_id('page', $result['cmid'], 0, false, MUST_EXIST);
        $this->assertSame('Klon von Original', $newcm->name);
        $this->assertStringNotContainsStringIgnoringCase('kopie', $newcm->name);
        $this->assertStringNotContainsStringIgnoringCase('copy', $newcm->name);

        $newinstance = $DB->get_record('page', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('Original-Inhalt', $newinstance->content);
    }

    /**
     * Abnahmekriterium: kursuebergreifender Klon landet im Zielkurs.
     */
    public function test_cross_course_clone(): void {
        global $DB;
        $this->resetAfterTest();
        [$sourcecourse, $teacher] = $this->course_with_editing_teacher();
        $targetcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'editingteacher');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $sourcecourse->id,
            'name' => 'Original',
            'content' => 'Original-Inhalt',
        ]);

        $result = clone_activity::execute((int) $page->cmid, 'Klon in anderem Kurs', (int) $targetcourse->id);
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $this->assertSame((int) $targetcourse->id, $result['courseid']);
        $newcm = get_coursemodule_from_id('page', $result['cmid'], (int) $targetcourse->id, false, MUST_EXIST);
        $this->assertSame('Klon in anderem Kurs', $newcm->name);

        $newinstance = $DB->get_record('page', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('Original-Inhalt', $newinstance->content);
    }

    /**
     * Abnahmekriterium: eine kaputte Abschlussbedingung (Verweis auf ein
     * Modul, das beim kursuebergreifenden Klon nicht mitkopiert wurde) wird
     * erkannt, entfernt und in der Meldung im Klartext genannt.
     */
    public function test_removes_and_names_broken_prerequisite_on_cross_course_clone(): void {
        global $DB;
        $this->resetAfterTest();
        [$sourcecourse, $teacher] = $this->course_with_editing_teacher();
        $targetcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'editingteacher');

        $prerequisite = $this->getDataGenerator()->create_module('page', [
            'course' => $sourcecourse->id,
            'name' => 'Voraussetzung-Ziel',
        ]);
        $dependent = $this->getDataGenerator()->create_module('page', [
            'course' => $sourcecourse->id,
            'name' => 'Abhaengige Aktivitaet',
        ]);

        set_restriction::execute((int) $dependent->cmid, json_encode([
            ['typ' => 'abschluss', 'aktivitaet_cmid' => (int) $prerequisite->cmid, 'status' => 'abgeschlossen'],
        ]));

        $result = clone_activity::execute((int) $dependent->cmid, 'Klon mit kaputter Voraussetzung', (int) $targetcourse->id);
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $this->assertStringContainsString('entfernt', $result['meldung']);
        $this->assertStringContainsString('Voraussetzung-Ziel', $result['meldung']);

        $newcm = $DB->get_record('course_modules', ['id' => $result['cmid']], '*', MUST_EXIST);
        $this->assertTrue($newcm->availability === null || $newcm->availability === '');
    }

    /**
     * Abnahmekriterium: fehlende Berechtigung im Zielkurs scheitert klar -
     * die Lehrkraft ist im Zielkurs nur als Student eingeschrieben, ohne
     * Bearbeiten-Berechtigung (bloss "nicht eingeschrieben" wuerde bereits
     * beim Zugriffs-Check in validate_context() als require_login_exception
     * scheitern, nicht als Capability-Fehler - dasselbe Muster wie
     * set_restriction_test::test_requires_manageactivities_capability()).
     */
    public function test_capability_error_in_target_course(): void {
        $this->resetAfterTest();
        [$sourcecourse, $teacher] = $this->course_with_editing_teacher();
        $targetcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'student');

        $page = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id]);

        $this->expectException(\required_capability_exception::class);
        clone_activity::execute((int) $page->cmid, 'Klon', (int) $targetcourse->id);
    }

    /**
     * Abnahmekriterium: fehlende Berechtigung im QUELLkurs scheitert ebenso
     * klar - die Lehrkraft hat im Quellkurs nur eine Rolle ohne
     * Bearbeiten-Berechtigung.
     */
    public function test_capability_error_in_source_course(): void {
        $this->resetAfterTest();
        $sourcecourse = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $sourcecourse->id, 'student');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id]);

        $this->expectException(\required_capability_exception::class);
        clone_activity::execute((int) $page->cmid, 'Klon');
    }

    /**
     * Abnahmekriterium: der Klon erzeugt genau einen Aenderungsverlauf-Stand
     * als Version 1 mit quelle="geklont" und der Quell-Modul-ID - unabhaengig
     * davon, was der Beobachter waehrend des Klonens selbst mitschreibt.
     */
    public function test_history_stand_has_correct_origin(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $result = clone_activity::execute((int) $page->cmid, 'Klon');
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $result['cmid']]));
        $this->assertCount(1, $versions);
        $this->assertSame(1, (int) $versions[0]->version);
        $this->assertSame(version_writer::SOURCE_GEKLONT, $versions[0]->source);
        $this->assertSame((int) $page->cmid, (int) $versions[0]->sourcecmid);
    }
}
