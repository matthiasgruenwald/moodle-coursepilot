<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\external;

use core_external\external_api;
use local_kurspilot\context_files;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use local_kurspilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Anhaengen im Kontextbereich (Issue #409, Spec 0016 Paragraph 4.2).
 * Happy-Path, Neuanlegen, Personenbezug der Zieldatei, das weiche
 * 1-MB-Signal und die Alles-oder-nichts-Zusage. Seit Issue #491 zusaetzlich
 * der externe Zweig: Read-modify-write mit `If-Match`, verpflichtender
 * Rotationshinweis.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(append_context_file::class)]
final class append_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::use_test_transport(null);
        parent::tearDown();
    }

    /**
     * Anhaengen an eine bestehende Datei: der Inhalt waechst, die Antwort
     * nennt die neue Gesamtgroesse (Spec 0016 §5.4).
     */
    public function test_appends_to_existing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'journal.md', "# Journal\n");

        $result = $this->append('journal.md', "- Stunde 1\n");

        $this->assertFalse($result['created']);
        $this->assertSame("# Journal\n- Stunde 1\n", $this->read_stored($user, '/kurspilot/', 'journal.md'));
        $this->assertSame(21, $result['size']);
        $this->assertSame(
            get_string('contextfileappended', 'local_kurspilot', (object) [
                'path' => 'journal.md',
                'size' => 21,
            ]),
            $result['message']
        );
    }

    /**
     * Fehlt die Zieldatei, entsteht sie - und die Antwort sagt ausdruecklich
     * "neu angelegt", damit ein Tippfehler im Pfad im Chat sichtbar wird.
     */
    public function test_creates_missing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->append('journal.md', '# Journal');

        $this->assertTrue($result['created']);
        $this->assertSame(
            get_string('contextfilecreated', 'local_kurspilot', 'journal.md'),
            $result['message']
        );
        $this->assertSame('# Journal', $this->read_stored($user, '/kurspilot/', 'journal.md'));
    }

    /**
     * Auch beim Anhaengen gelten die Pfadregeln (Spec 0016 §5.1).
     */
    public function test_rejects_invalid_path_segment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('faecher/ma the/journal.md', 'x');
    }

    /**
     * "../" fuehrt nicht aus dem Bereich heraus.
     */
    public function test_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('../journal.md', 'x');
    }

    /**
     * Nur .md - der Kontextbereich nimmt kein Material auf.
     */
    public function test_rejects_non_markdown_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('notiz.txt', 'x');
    }

    /**
     * Das Anhaengsel selbst ist hart auf 1 MB begrenzt (Spec 0016 §5.2).
     */
    public function test_rejects_oversized_content(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('journal.md', str_repeat('x', 1024 * 1024 + 1));
    }

    /**
     * Die Zieldatei darf ueber 1 MB wachsen - das ist ein weiches Signal,
     * kein Fehler: der Append geht durch, die Antwort empfiehlt Rotation
     * (Spec 0016 §5.2/§8.4).
     */
    public function test_oversized_target_file_gets_rotation_hint(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'journal.md', str_repeat('x', 1024 * 1024));

        $result = $this->append('journal.md', 'y');

        $this->assertSame(1024 * 1024 + 1, $result['size']);
        $this->assertStringContainsString(
            get_string('contextfilerotation', 'local_kurspilot'),
            $result['message']
        );
    }

    /**
     * Unterhalb der Grenze steht kein Rotationshinweis in der Antwort.
     */
    public function test_small_file_has_no_rotation_hint(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'journal.md', 'kurz');

        $result = $this->append('journal.md', 'x');

        $this->assertStringNotContainsString(
            get_string('contextfilerotation', 'local_kurspilot'),
            $result['message']
        );
    }

    /**
     * Ist die Zieldatei personenbezogen markiert und der #344-Schalter aus,
     * wird abgewiesen - sonst liesse sich die Grenze mit einem Append
     * umgehen (Spec 0016 §4.2).
     */
    public function test_rejects_marked_target_file_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'lerngruppe.md', $this->marked_content());

        try {
            $this->append('lerngruppe.md', "\n- Notiz");
            $this->fail('Personenbezug haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->read_stored($user, '/kurspilot/', 'lerngruppe.md')
        );
    }

    /**
     * Bei eingeschaltetem Schalter geht derselbe Append durch.
     */
    public function test_appends_to_marked_target_file_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_kurspilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'lerngruppe.md', $this->marked_content());

        $this->append('lerngruppe.md', "\n- Notiz");

        $this->assertSame(
            $this->marked_content() . "\n- Notiz",
            $this->read_stored($user, '/kurspilot/', 'lerngruppe.md')
        );
    }

    /**
     * Keine Zieldatei = kein Frontmatter = kein Personenbezug: der Append
     * legt die Datei an, auch wenn der Schalter aus ist (Spec 0016 §5.5).
     */
    public function test_creates_missing_file_without_frontmatter_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->append('lerngruppe.md', '# Lerngruppe');

        $this->assertTrue($result['created']);
    }

    /**
     * Ohne moodle/user:manageownfiles kein Schreibzugriff (Spec 0016 §1.1).
     */
    public function test_rejects_missing_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        $this->append('journal.md', 'x');
    }

    /**
     * Alles-oder-nichts: scheitert der Vorgang (hier an der Nutzerquote),
     * bleibt die Zieldatei unveraendert stehen - kein halb angehaengter
     * Zustand.
     */
    public function test_failed_append_leaves_target_file_untouched(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->userquota = 1024;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'journal.md', 'alt');

        try {
            $this->append('journal.md', str_repeat('x', 2048));
            $this->fail('Quotenueberschreitung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('MB', $e->getMessage());
        }

        $this->assertSame('alt', $this->read_stored($user, '/kurspilot/', 'journal.md'));
    }

    /**
     * Der Append laeuft in einem einzigen Serveraufruf: kein Parameter
     * verlangt den vorher gelesenen Inhalt oder contenthash, es gibt also
     * kein Read-Modify-Write auf Anwendungsebene (Spec 0016 §4.2/§5.3).
     */
    public function test_execute_parameters_need_no_prior_read(): void {
        $this->assertSame(
            ['path', 'content', 'ausstand'],
            array_keys(append_context_file::execute_parameters()->keys)
        );
    }

    /**
     * Zwei aufeinanderfolgende Appends verlieren nichts - jeder Aufruf
     * liest den aktuellen Stand selbst.
     */
    public function test_consecutive_appends_accumulate(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->append('journal.md', 'a');
        $this->append('journal.md', 'b');
        $result = $this->append('journal.md', 'c');

        $this->assertSame('abc', $this->read_stored($user, '/kurspilot/', 'journal.md'));
        $this->assertSame(3, $result['size']);
    }

    /**
     * Person A haengt nie im Bereich von Person B an.
     */
    public function test_appends_only_into_own_area(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teacherb);
        $this->append('journal.md', '# B');

        $this->assertNull($this->stored_file($teachera, '/kurspilot/', 'journal.md'));
        $this->assertSame('# B', $this->read_stored($teacherb, '/kurspilot/', 'journal.md'));
    }

    /**
     * Die Lehrkraft liest die Antwort auf Deutsch - "angehängt" mit
     * Gesamtgroesse und der Rotationshinweis muessen dort stehen
     * (Spec 0016 §5.4). Geprueft am deutschen Sprachpaket, weil die
     * PHPUnit-Instanz nur Englisch aufgeloest bekommt.
     */
    public function test_german_messages_carry_the_required_wording(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_kurspilot.php');

        $this->assertStringContainsString('angehängt', $string['contextfileappended']);
        $this->assertStringContainsString('{$a->size}', $string['contextfileappended']);
        $this->assertStringContainsString('Rotation', $string['contextfilerotation']);
    }

    /**
     * Extern haengt {@see append_context_file} per Read-modify-write mit
     * `If-Match` an (Issue #491, Spec #486 §4/§6).
     */
    public function test_appends_to_existing_external_file_with_if_match(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $seeded = $fake->seed_file('/Kurspilot/Kontext/journal.md', "# Journal\n");

        $result = $this->append('journal.md', "- Stunde 1\n");

        $this->assertFalse($result['created']);
        $this->assertSame(21, $result['size']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame($seeded['etag'], $puts[0]['headers']['If-Match'] ?? null);
        $this->assertSame("# Journal\n- Stunde 1\n", $puts[0]['body']);
    }

    /**
     * Fehlt die Zieldatei extern, entsteht sie ueber `If-None-Match: *`.
     */
    public function test_creates_missing_external_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $result = $this->append('journal.md', '# Journal');

        $this->assertTrue($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertSame('*', $puts[0]['headers']['If-None-Match'] ?? null);
    }

    /**
     * Der Rotationshinweis gilt extern als Pflicht (Spec #486 §6) - anders
     * als in Moodle steht er in jeder Antwort, nicht erst ab 1 MB, weil jedes
     * externe Anhaengen die ganze Datei zweimal uebertraegt.
     */
    public function test_external_append_always_carries_rotation_hint(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/journal.md', 'kurz');

        $result = $this->append('journal.md', 'x');

        $this->assertStringContainsString(
            get_string('contextfilerotation', 'local_kurspilot'),
            $result['message']
        );
    }

    /**
     * Ein voller externer Speicher (507) ist ein Ausfall (Issue #492, ADR
     * 0023): der Vorgang "anhängen" wird vermerkt, nicht "anlegen"/
     * "überschreiben", und die Antwort ist die fuenfteilige Ausfallmeldung.
     */
    public function test_external_append_storage_full_records_ausstand(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
            $this->assertStringContainsString('journal.md', $e->getMessage());
            $this->assertStringContainsString('Kennung', $e->getMessage());
        }

        $ausstaende = \local_kurspilot\ausstand_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('journal.md', $ausstaende[0]['pfad']);
        $this->assertSame('anhängen', $ausstaende[0]['eintraege'][0]['vorgang']);
        $this->assertSame(
            \local_kurspilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['eintraege'][0]['fehlerklasse']
        );
    }

    /**
     * `ausstand=<Kennung>` hakt den Eintrag beim erfolgreichen Nachtragen
     * per Anhaengen ab (ADR 0023 Punkt 3).
     */
    public function test_ausstand_parameter_dismisses_entry_on_successful_append(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }
        $kennung = \local_kurspilot\ausstand_notice::list_grouped()[0]['eintraege'][0]['kennung'];

        $fake2 = new \local_kurspilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Kurspilot/Kontext');
        webdav_instance::use_test_transport($fake2);

        $result = append_context_file::execute('journal.md', 'x', $kennung);
        $result = external_api::clean_returnvalue(append_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_kurspilot\ausstand_notice::list_grouped());
    }

    /**
     * `moodle/user:manageownfiles` und die Nutzerquote wirken extern nicht
     * (Issue #491, Spec #486 §6).
     */
    public function test_external_append_ignores_moodle_quota_and_capability(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $CFG->userquota = 1;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $result = $this->append('journal.md', str_repeat('x', 4096));

        $this->assertTrue($result['created']);
    }

    /**
     * Der Endpunkt haengt am Kurspilot-Dienst und steht in der Allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'kurspilot_append_context_file',
            \local_kurspilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_kurspilot_append_context_file',
            \local_kurspilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_kurspilot\tool_registry::is_write('kurspilot_append_context_file'));
    }

    /**
     * @param string $path
     * @param string $content
     * @return array Bereinigte Antwort des Endpunkts.
     */
    private function append(string $path, string $content): array {
        $result = append_context_file::execute($path, $content);
        return external_api::clean_returnvalue(append_context_file::execute_returns(), $result);
    }

    /**
     * @return string Inhalt mit Frontmatter-Markierung "personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\nkurspilot:\n  personenbezug: true\n---\n# S. M., 7a";
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @return \stored_file|null
     */
    private function stored_file(\stdClass $user, string $filepath, string $filename): ?\stored_file {
        $file = get_file_storage()->get_file(
            \context_user::instance($user->id)->id,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            $filepath,
            $filename
        );
        return $file ?: null;
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @return string
     */
    private function read_stored(\stdClass $user, string $filepath, string $filename): string {
        $file = $this->stored_file($user, $filepath, $filename);
        $this->assertNotNull($file, 'Erwartete Datei fehlt: ' . $filepath . $filename);
        return $file->get_content();
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @param string $content
     */
    private function create_context_file(\stdClass $user, string $filepath, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => context_files::COMPONENT,
            'filearea' => context_files::FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }
}
