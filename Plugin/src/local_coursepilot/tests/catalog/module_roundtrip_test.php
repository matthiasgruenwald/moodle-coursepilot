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

use core_external\external_api;
use local_coursepilot\external\create_module;
use local_coursepilot\external\create_quiz;
use local_coursepilot\external\get_module_settings;
use local_coursepilot\external\update_module_settings;
use local_coursepilot\external\update_quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Vertragstest ueber alle neun katalogisierten Modultypen (Issue #556,
 * Abnahmekriterium 4): jede Art durchlaeuft denselben Rundlauf - anlegen,
 * lesen, ein Feld aendern, erneut lesen -, unabhaengig davon, ob sie ueber
 * die generischen Werkzeuge (create_module/update_module_settings) oder ihr
 * eigenes Werkzeugpaar (create_quiz/update_quiz_settings, {@see registry::for()})
 * geschrieben wird. Ein zehnter Modultyp braucht hier nur einen weiteren
 * {@see self::scenario()}-Zweig, keinen neuen Testkoerper.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(registry::class)]
final class module_roundtrip_test extends \advanced_testcase {

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
     * Legt eine Materialdatei fuer den aktuell angemeldeten Nutzer an, wie
     * upload_material_file sie hinterliesse (Vorbild
     * {@see \local_coursepilot\external\create_module_test::create_material_file()}).
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function create_material_file(string $path, string $content): void {
        $filerecord = \local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            '/coursepilot-material/',
            $path
        );
        \local_coursepilot\material_files::replace(null, $filerecord, $content);
    }

    private function create_via_module_tool(int $courseid, string $modname, array $felder): array {
        return external_api::clean_returnvalue(
            create_module::execute_returns(),
            create_module::execute($courseid, 0, $modname, json_encode($felder), \local_coursepilot\material_files::ORT_BESTAND)
        );
    }

    private function create_quiz_instance(int $courseid): array {
        $felder = [
            'name' => 'Rundlauf-Test',
            'intro' => 'Beschreibung',
            'subnet' => '',
            'browsersecurity' => '-',
            'preferredbehaviour' => 'deferredfeedback',
        ];
        return external_api::clean_returnvalue(
            create_quiz::execute_returns(),
            create_quiz::execute($courseid, 0, json_encode($felder), '', -1.0)
        );
    }

    /**
     * @param int $cmid
     * @return array Ist-Stand, dieselbe Form wie get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    private function patch(string $modname, int $cmid, array $felder): void {
        if ($modname === 'quiz') {
            external_api::clean_returnvalue(
                update_quiz_settings::execute_returns(),
                update_quiz_settings::execute($cmid, json_encode($felder))
            );
            return;
        }
        external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode($felder))
        );
    }

    /**
     * Legt eine Aktivitaet des genannten Typs an und nennt ein Feld, das sich
     * gefahrlos patchen laesst (Vorbild: die jeweiligen create_*_test-Dateien
     * - Rueckgriff auf ihre Mindestfelder).
     *
     * @param string $modname
     * @param int $courseid
     * @return array{cmid: int, field: string, value: mixed}
     */
    private function scenario(string $modname, int $courseid): array {
        return match ($modname) {
            // "name" wird bei label aus dem Intro abgeleitet und beim
            // Anlegen sofort ueberschrieben (siehe label::fields()) -
            // "intro" ist hier das einzige patchbare Feld.
            'label' => [
                'cmid' => $this->create_via_module_tool($courseid, 'label', [
                    'intro' => 'Ausgangstext',
                ])['cmid'],
                'field' => 'intro',
                'value' => 'Geaenderter Text',
            ],
            'page' => [
                'cmid' => $this->create_via_module_tool($courseid, 'page', [
                    'name' => 'Seite',
                    'page' => ['text' => 'Seiteninhalt', 'format' => FORMAT_HTML, 'itemid' => 0],
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Seitentitel',
            ],
            'url' => [
                'cmid' => $this->create_via_module_tool($courseid, 'url', [
                    'name' => 'Externer Link',
                    'externalurl' => 'https://example.org/',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Linktitel',
            ],
            'folder' => [
                'cmid' => $this->create_via_module_tool($courseid, 'folder', [
                    'name' => 'Materialordner',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Ordnername',
            ],
            'resource' => (function () use ($courseid): array {
                $this->create_material_file('arbeitsblatt.pdf', 'Arbeitsblattinhalt');
                return [
                    'cmid' => $this->create_via_module_tool($courseid, 'resource', [
                        'name' => 'Datei',
                        'files' => ['arbeitsblatt.pdf'],
                    ])['cmid'],
                    'field' => 'name',
                    'value' => 'Neuer Dateiname',
                ];
            })(),
            'choice' => [
                'cmid' => $this->create_via_module_tool($courseid, 'choice', [
                    'name' => 'Abstimmung',
                    'intro' => 'Bitte waehlen',
                    'option' => ['Ja', 'Nein'],
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neue Abstimmung',
            ],
            'forum' => [
                'cmid' => $this->create_via_module_tool($courseid, 'forum', [
                    'name' => 'Forum',
                    'intro' => 'Diskussion',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neuer Forumtitel',
            ],
            'assign' => [
                'cmid' => $this->create_via_module_tool($courseid, 'assign', [
                    'name' => 'Aufgabe',
                    'intro' => 'Aufgabenstellung',
                ])['cmid'],
                'field' => 'name',
                'value' => 'Neue Aufgabe',
            ],
            'quiz' => [
                'cmid' => $this->create_quiz_instance($courseid)['cmid'],
                'field' => 'name',
                'value' => 'Neuer Testtitel',
            ],
            default => throw new \coding_exception("Kein Szenario fuer Modultyp {$modname}."),
        };
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function modname_provider(): array {
        return array_combine(
            registry::known_modnames(),
            array_map(static fn (string $modname): array => [$modname], registry::known_modnames())
        );
    }

    /**
     * Jeder katalogisierte Modultyp durchlaeuft denselben Rundlauf: anlegen,
     * lesen, ein Feld aendern, erneut lesen - das geaenderte Feld traegt den
     * neuen Wert, sonst nichts weiter vorausgesetzt (Issue #556, Abnahme-
     * kriterium 4).
     */
    #[DataProvider('modname_provider')]
    public function test_round_trip_create_read_patch_read(string $modname): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $case = $this->scenario($modname, $course->id);
        $this->assertGreaterThan(0, $case['cmid'], "{$modname}: Anlegen muss eine cmid liefern.");

        $before = $this->read($case['cmid']);
        $this->assertArrayHasKey($case['field'], $before, "{$modname}: gepatchtes Feld muss lesbar sein.");
        $this->assertNotSame($case['value'], $before[$case['field']], "{$modname}: Ausgangswert darf nicht bereits der Zielwert sein.");

        $this->patch($modname, $case['cmid'], [$case['field'] => $case['value']]);

        $after = $this->read($case['cmid']);
        $this->assertSame($case['value'], $after[$case['field']], "{$modname}: Patch muss beim erneuten Lesen sichtbar sein.");
    }

    /**
     * Issue #564: "option" liegt (anders als "name") nicht in der
     * choice-Instanzzeile, sondern in choice_options - eine eigene
     * "repeated group" (Spec 0015 §2.2 Kategorie 2), die der generische
     * Rundlauf oben (Feld "name") nicht abdeckt. Rundlauf wie bei den
     * uebrigen acht Modultypen: anlegen, lesen, Option aendern, erneut lesen.
     */
    public function test_choice_option_round_trip(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $cmid = $this->create_via_module_tool($course->id, 'choice', [
            'name' => 'Abstimmung',
            'intro' => 'Bitte waehlen',
            'option' => ['Ja', 'Nein'],
            'limit' => [2, 3],
        ])['cmid'];

        $before = $this->read($cmid);
        $this->assertSame(['Ja', 'Nein'], $before['option'], 'Angelegte Optionen muessen beim Lesen sichtbar sein.');
        $this->assertSame([2, 3], array_map('intval', $before['limit']), 'Gesetzte Limits muessen beim Lesen sichtbar sein.');
        $this->assertCount(2, $before['optionid'], 'Bestehende choice_options-IDs muessen beim Lesen sichtbar sein.');

        // "optionid" muss mitgeschickt werden, sonst legt choice_update_instance()
        // zusaetzliche Optionen an statt bestehende zu ueberschreiben (siehe
        // choice::pseudofields(), Feld "optionid") - derselbe Rundlauf, den
        // mod_choice_mod_form::data_preprocessing() im echten Formularweg vorbereitet.
        $this->patch('choice', $cmid, [
            'option' => ['Vielleicht', 'Auf jeden Fall'],
            'limit' => [4, 5],
            'optionid' => $before['optionid'],
        ]);

        $after = $this->read($cmid);
        $this->assertSame(
            ['Vielleicht', 'Auf jeden Fall'],
            $after['option'],
            'Geaenderte Optionen muessen beim erneuten Lesen sichtbar sein.'
        );
        $this->assertSame([4, 5], array_map('intval', $after['limit']), 'Geaenderte Limits muessen beim erneuten Lesen sichtbar sein.');
    }

    /**
     * Die Registry ist die vollstaendige Liste - dieser Test scheitert, wenn
     * eine neue Katalogklasse eingetragen wird, ohne hier ein Szenario zu
     * bekommen (haelt Abnahmekriterium 4 "alle neun" dauerhaft wahr).
     */
    public function test_every_registered_modname_has_a_scenario(): void {
        foreach (registry::known_modnames() as $modname) {
            $this->assertIsArray(
                self::modname_provider()[$modname] ?? null,
                "Modultyp {$modname} fehlt im Rundlauf-Vertragstest."
            );
        }
    }
}
