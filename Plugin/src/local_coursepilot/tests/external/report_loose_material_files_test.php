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

use local_coursepilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Aufraeumbericht ueber "lose" Materialdateien (Spec 0018 §8.2/§8.3, Issue
 * #438): contenthash-Abgleich gegen die Aktivitaets-Fileareas der eigenen
 * Kurse, inklusive des Zuschnitt-Sonderfalls.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(report_loose_material_files::class)]
final class report_loose_material_files_test extends \advanced_testcase {

    public function test_lists_unused_file_as_loose(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_material_file('screenshot.png', 'Inhalt A');

        $result = report_loose_material_files::execute();

        $this->assertCount(1, $result['files']);
        $this->assertSame('screenshot.png', $result['files'][0]['path']);
        $this->assertSame(strlen('Inhalt A'), $result['files'][0]['size']);
        $this->assertSame(strlen('Inhalt A'), $result['total_size']);
        $this->assertSame(0, $result['files'][0]['age_days']);
    }

    /**
     * Verwendet heisst: der contenthash taucht in einer Aktivitaets-Filearea
     * eines eigenen Kurses auf (Spec 0018 §8.2) - kein Rateweg, kein
     * Namensabgleich.
     */
    public function test_used_file_is_not_loose(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $this->store_material_file('arbeitsblatt.pdf', 'Verwendeter Inhalt');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $cm = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $modulecontext = \context_module::instance($cm->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $modulecontext->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'arbeitsblatt.pdf',
        ], 'Verwendeter Inhalt');

        $result = report_loose_material_files::execute();

        $this->assertSame([], $result['files']);
    }

    /**
     * Der Zuschnitt-Sonderfall (Spec 0018 §8.2): das Original hat einen
     * anderen contenthash als der eingebettete Ausschnitt, also erscheint
     * das Original als lose, der Ausschnitt nicht.
     */
    public function test_original_is_loose_after_crop_embedded(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $this->setUser($teacher);
        $this->store_material_file('buchseite.png', 'ganze Buchseite');
        $this->store_material_file('ausschnitt.png', 'nur der Ausschnitt');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $cm = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $modulecontext = \context_module::instance($cm->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $modulecontext->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'ausschnitt.png',
        ], 'nur der Ausschnitt');

        $result = report_loose_material_files::execute();

        $this->assertCount(1, $result['files']);
        $this->assertSame('buchseite.png', $result['files'][0]['path']);
    }

    public function test_own_courses_only_counts_towards_used(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_material_file('screenshot.png', 'Fremdinhalt');

        // Datei mit demselben Inhalt in einem Kurs, in dem die aufrufende
        // Person nicht eingeschrieben ist - zaehlt nicht als "verwendet".
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'editingteacher');
        $cm = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $modulecontext = \context_module::instance($cm->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $modulecontext->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'screenshot.png',
        ], 'Fremdinhalt');

        $result = report_loose_material_files::execute();

        $this->assertCount(1, $result['files']);
    }

    public function test_reports_remaining_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $result = report_loose_material_files::execute();

        $this->assertNotNull($result['remaining_quota_mb']);
    }

    private function store_material_file(string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $content);
    }
}
