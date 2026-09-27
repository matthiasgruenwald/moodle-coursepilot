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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Erstanmeldung einer normalen Kurslehrkraft (#575, docs/admin-erstanleitung.md
 * Abschnitt "Fernzugriffsrecht für Lehrkräfte"): der dokumentierte
 * Einrichtungsweg verwendet eine eigens angelegte, nur an
 * `local/coursepilot:useremote` hängende Rolle statt einer systemweiten
 * `editingteacher`-Zuweisung. Diese Tests legen die Rolle genauso an, wie es
 * die Anleitung für die Administrationsperson beschreibt, und prüfen die
 * Wirkung an einer Lehrkraft ohne jede globale Lehrkraftrolle.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(dispatcher::class)]
final class remote_role_test extends \advanced_testcase {

    /**
     * Legt die in der Anleitung beschriebene Rolle an: kein Archetyp (kein
     * automatisch geerbtes Recht), nur im Systemkontext zuweisbar, dort
     * ausschließlich `local/coursepilot:useremote` erlaubt.
     *
     * @return int Rollen-ID.
     */
    private function create_documented_remote_role(): int {
        $roleid = create_role('Kurspilot Fernzugriff', 'coursepilotremote', '', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability(
            'local/coursepilot:useremote',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        return $roleid;
    }

    /**
     * @param int $userid
     * @return string Das Access-Token.
     */
    private function issue_access_token(int $userid): string {
        global $DB;

        $accesstoken = oauth_lib::random_token(32);
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'accesstokenhash' => hash('sha256', $accesstoken),
            'refreshtokenhash' => hash('sha256', oauth_lib::random_token(32)),
            'clientid' => 'test-client',
            'userid' => $userid,
            'expires' => time() + oauth_lib::ACCESS_TOKEN_TTL,
            'refreshexpires' => time() + oauth_lib::REFRESH_TOKEN_TTL,
            'revoked' => 0,
            'timecreated' => time(),
        ]);
        return $accesstoken;
    }

    private function headers(): array {
        return ['origin' => null, 'pathinfo' => '', 'method' => 'POST'];
    }

    /**
     * Vor der Freischaltung: eine frisch angelegte Lehrkraft, nur im eigenen
     * Kurs als `editingteacher` eingeschrieben, ohne jede globale
     * Lehrkraftrolle, wird beim OAuth-Einstieg abgewiesen.
     */
    public function test_course_only_teacher_without_system_role_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $token = $this->issue_access_token($teacher->id);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('local/coursepilot:useremote', $response['body']['error']['message']);
    }

    /**
     * Nach Zuweisung der dedizierten Rolle im Systemkontext: derselbe Kurs-
     * Lehrkraft-Zugang startet den OAuth-Flow und nutzt ein Kurswerkzeug im
     * eigenen Kurs - ohne dass ihr dafür irgendwo eine Kursbearbeitungsrolle
     * verliehen wurde.
     */
    public function test_dedicated_role_grants_remote_access_and_own_course_tool(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $token = $this->issue_access_token($teacher->id);
        $roleid = $this->create_documented_remote_role();
        role_assign($roleid, $teacher->id, \context_system::instance()->id);

        $initresponse = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());
        $this->assertSame(200, $initresponse['status']);

        $toolresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_get_sections', 'arguments' => ['courseid' => $course->id]],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $toolresponse['status']);
        $this->assertArrayNotHasKey('isError', $toolresponse['body']['result']);
    }

    /**
     * Die dedizierte Rolle trägt ausschließlich `useremote` - ein fremder
     * Kurs bleibt trotz gültigem Token verboten, weil dort weder Einschreibung
     * noch `local/coursepilot:use` vorliegen (#575, Abnahmekriterium "Fremde
     * Kurse bleiben verboten").
     */
    public function test_dedicated_role_does_not_unlock_foreign_courses(): void {
        $this->resetAfterTest();
        $owncourse = $this->getDataGenerator()->create_course();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $owncourse->id, 'editingteacher');
        $token = $this->issue_access_token($teacher->id);
        $roleid = $this->create_documented_remote_role();
        role_assign($roleid, $teacher->id, \context_system::instance()->id);

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_get_sections', 'arguments' => ['courseid' => $foreigncourse->id]],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
    }

    /**
     * Entzug der Fernzugriffsrolle sperrt den OAuth-Einstieg vollständig,
     * lässt die Kurs-Berechtigung aber unangetastet - getrennt entziehbar
     * (#575, Abnahmekriterium "wirken unabhängig").
     */
    public function test_revoking_dedicated_role_blocks_remote_access_but_keeps_course_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $token = $this->issue_access_token($teacher->id);
        $roleid = $this->create_documented_remote_role();
        role_assign($roleid, $teacher->id, \context_system::instance()->id);
        $this->assertSame(200, dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers())['status']);

        role_unassign($roleid, $teacher->id, \context_system::instance()->id);

        $response = dispatcher::handle(['id' => 2, 'method' => 'initialize'], $token, $this->headers());
        $this->assertSame(403, $response['status']);
        $coursecontext = \context_course::instance($course->id);
        $this->assertTrue(has_capability('local/coursepilot:use', $coursecontext, $teacher->id));
    }

    /**
     * Umgekehrt: Entzug der Kurs-Einschreibung sperrt nur das Kurswerkzeug,
     * der Fernzugriff (OAuth-Einstieg) bleibt über die dedizierte Rolle
     * bestehen - beide Berechtigungen wirken unabhängig voneinander (#575).
     */
    public function test_revoking_course_enrolment_blocks_course_tool_but_keeps_remote_access(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $token = $this->issue_access_token($teacher->id);
        $roleid = $this->create_documented_remote_role();
        role_assign($roleid, $teacher->id, \context_system::instance()->id);

        $this->unenrol_teacher($teacher->id, $course->id);

        $initresponse = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());
        $this->assertSame(200, $initresponse['status']);

        $toolresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_get_sections', 'arguments' => ['courseid' => $course->id]],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $toolresponse['status']);
        $this->assertTrue($toolresponse['body']['result']['isError']);
    }

    /**
     * Entzieht die manuelle Einschreibung wieder - der Generator selbst
     * bietet dafür keine Kurzform.
     *
     * @param int $userid
     * @param int $courseid
     */
    private function unenrol_teacher(int $userid, int $courseid): void {
        global $DB;

        $enrolinstance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->unenrol_user($enrolinstance, $userid);
    }

    /**
     * Legt die noch bestehende Archetyp-Vorbelegung in `db/access.php` fest
     * (Rueckwaertskompatibilitaet mit der bisherigen "teacher_edit"-Praxis,
     * siehe Kommentar dort): eine systemweite `editingteacher`- oder
     * `teacher`-Zuweisung schaltet `local/coursepilot:useremote` weiterhin
     * automatisch frei. Der Test dokumentiert das bewusst als aktuellen,
     * nicht empfohlenen Stand - eine kuenftige Aenderung an dieser
     * Vorbelegung muss diesen Test bewusst anfassen, statt unbemerkt
     * durchzurutschen (#575, Review-Nacharbeit).
     */
    public function test_useremote_capability_default_archetypes_stay_documented_as_legacy(): void {
        $this->resetAfterTest();

        $editingteacherdefaults = get_default_capabilities('editingteacher');
        $teacherdefaults = get_default_capabilities('teacher');

        $this->assertSame(CAP_ALLOW, $editingteacherdefaults['local/coursepilot:useremote']);
        $this->assertSame(CAP_ALLOW, $teacherdefaults['local/coursepilot:useremote']);
    }
}
