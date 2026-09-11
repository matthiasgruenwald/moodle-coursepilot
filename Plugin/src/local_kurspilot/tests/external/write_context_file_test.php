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
use local_kurspilot\tests\webdav\fake_webdav_transport;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use local_kurspilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Schreiben in den Kontextbereich (Issue #408, Spec 0016 Paragraph 4.1).
 * Neben dem Happy-Path die Absagen, die das Werkzeug eng halten: Pfad,
 * Dateiendung, Groesse, Gleichzeitigkeit, Personenbezug, Quote. Seit Issue
 * #491 zusaetzlich der externe Zweig: bedingtes Anlegen/Ueberschreiben ueber
 * WebDAV, Nextcloud-Modus (mit ETag) und IServ-Modus (ohne ETag), Konflikt,
 * fehlende Ordnerebenen, voller Speicher, keine Moodle-Quote/-Capability.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(write_context_file::class)]
final class write_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::use_test_transport(null);
        parent::tearDown();
    }

    /**
     * Eine neue Datei entsteht, und die Antwort sagt ausdruecklich "neu
     * angelegt" - damit ein Tippfehler im Pfad im Chat sichtbar wird.
     */
    public function test_creates_new_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->write('plan.md', '# Plan');

        $this->assertTrue($result['created']);
        $this->assertSame(
            get_string('contextfilecreated', 'local_kurspilot', 'plan.md'),
            $result['message']
        );
        $this->assertSame('# Plan', $this->read_stored($user, '/kurspilot/', 'plan.md'));
        $this->assertSame(6, $result['size']);
    }

    /**
     * Auch ein Unterordner entsteht ohne Sonderfall.
     */
    public function test_creates_file_in_subfolder(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->write('faecher/mathe/profil.md', '# Mathe');

        $this->assertSame('# Mathe', $this->read_stored($user, '/kurspilot/faecher/mathe/', 'profil.md'));
    }

    /**
     * Ueberschreiben nennt vorherige und neue Groesse (Spec 0016 §5.4).
     */
    public function test_overwrites_existing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'plan.md', 'alt');

        $result = $this->write('plan.md', '# Neuer Plan');

        $this->assertFalse($result['created']);
        $this->assertSame(
            get_string('contextfileoverwritten', 'local_kurspilot', (object) [
                'path' => 'plan.md',
                'before' => 3,
                'after' => 12,
            ]),
            $result['message']
        );
        $this->assertSame('# Neuer Plan', $this->read_stored($user, '/kurspilot/', 'plan.md'));
    }

    /**
     * Ein Pfadsegment ausserhalb [A-Za-z0-9_-] wird abgewiesen.
     */
    public function test_rejects_invalid_path_segment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('faecher/ma the/profil.md', '# Mathe');
    }

    /**
     * "../" fuehrt nicht aus dem Bereich heraus.
     */
    public function test_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('../plan.md', '# Plan');
    }

    /**
     * Nur .md - der Kontextbereich nimmt kein Material auf (Spec 0016 §5.1).
     */
    public function test_rejects_non_markdown_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('notiz.txt', 'Text');
    }

    /**
     * Ueber 1 MB je Vorgang ist ein harter Fehler (Spec 0016 §5.2).
     */
    public function test_rejects_oversized_content(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('plan.md', str_repeat('x', 1024 * 1024 + 1));
    }

    /**
     * Genau 1 MB geht noch durch - die Grenze ist einschliessend.
     */
    public function test_accepts_content_at_the_size_limit(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = $this->write('plan.md', str_repeat('x', 1024 * 1024));

        $this->assertSame(1024 * 1024, $result['size']);
    }

    /**
     * Passt der uebergebene contenthash, geht der Vorgang durch.
     */
    public function test_accepts_matching_contenthash(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'plan.md', 'alt');

        $result = $this->write('plan.md', 'neu', sha1('alt'));

        $this->assertFalse($result['created']);
    }

    /**
     * Weicht er ab, bricht der Vorgang ab - und die Datei bleibt unangetastet
     * (alles-oder-nichts).
     */
    public function test_rejects_stale_contenthash_and_leaves_file_untouched(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'plan.md', 'zwischendurch von Hand geaendert');

        try {
            $this->write('plan.md', 'neu', sha1('alt'));
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilechanged', $e->errorcode);
        }

        $this->assertSame(
            'zwischendurch von Hand geaendert',
            $this->read_stored($user, '/kurspilot/', 'plan.md')
        );
    }

    /**
     * Ein contenthash fuer eine Datei, die es nicht (mehr) gibt, ist
     * ebenfalls ein Konflikt - sie wurde zwischendurch geloescht.
     */
    public function test_rejects_contenthash_for_missing_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('plan.md', 'neu', sha1('alt'));
    }

    /**
     * Personenbezogen markierter Inhalt geht bei ausgeschaltetem
     * #344-Schalter nicht durch, und es entsteht keine Datei.
     */
    public function test_rejects_personal_data_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        try {
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Personenbezug haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('personenbezug', strtolower($e->getMessage()));
        }

        $this->assertNull($this->stored_file($user, '/kurspilot/', 'lerngruppe.md'));
    }

    /**
     * Was bei ausgeschaltetem Schalter nicht lesbar ist, darf auch nicht
     * ueberschrieben werden - sonst waere die #344-Grenze auf dem
     * zerstoerenden Weg offen (Spec 0016 §4.2 begruendet das fuer Append).
     */
    public function test_rejects_overwriting_a_marked_file_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'lerngruppe.md', $this->marked_content());

        try {
            $this->write('lerngruppe.md', '# harmlos');
            $this->fail('Ueberschreiben haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->read_stored($user, '/kurspilot/', 'lerngruppe.md')
        );
    }

    /**
     * Bei eingeschaltetem Schalter geht derselbe Inhalt durch.
     */
    public function test_accepts_personal_data_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_kurspilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $content = $this->marked_content();
        $this->write('lerngruppe.md', $content);

        $this->assertSame($content, $this->read_stored($user, '/kurspilot/', 'lerngruppe.md'));
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
        $this->write('plan.md', '# Plan');
    }

    /**
     * Reicht die Nutzerquote nicht, nennt die Absage den Restplatz in MB
     * (Spec 0016 §1.3) und verweist auf die Ortswahlseite (Issue #491, Spec
     * #486 §6: "Scheitert dort ein Schreibvorgang an der Quote, verweist die
     * Meldung auf die Ortswahlseite.").
     */
    public function test_rejects_when_user_quota_exceeded(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->userquota = 1024;
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            $this->write('plan.md', str_repeat('x', 2048));
            $this->fail('Quotenueberschreitung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('MB', $e->getMessage());
            $this->assertStringContainsString(
                \local_kurspilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE,
                $e->getMessage()
            );
        }
    }

    /**
     * Extern legt {@see write_context_file} mit `If-None-Match: *` an
     * (Issue #491, Spec #486 §4/§6) - Nextcloud-Modus, der Fake liefert ETags.
     */
    public function test_creates_new_external_file_with_if_none_match_star(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $result = $this->write('plan.md', '# Plan');

        $this->assertTrue($result['created']);
        $this->assertSame('plan.md', $result['path']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame('*', $puts[0]['headers']['If-None-Match'] ?? null);
        $this->assertArrayNotHasKey('If-Match', $puts[0]['headers']);
    }

    /**
     * Extern ueberschreibt {@see write_context_file} mit `If-Match: <ETag>`
     * (Nextcloud-Modus).
     */
    public function test_overwrites_existing_external_file_with_if_match(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $seeded = $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        $result = $this->write('plan.md', '# Neuer Plan');

        $this->assertFalse($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame($seeded['etag'], $puts[0]['headers']['If-Match'] ?? null);
    }

    /**
     * Ohne ETag (IServ) dient `getlastmodified` als schwacher Ersatz -
     * dasselbe Werkzeug, ohne dass der Aufrufer etwas davon merkt.
     */
    public function test_overwrites_existing_external_file_without_etag_iserv_mode(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        $result = $this->write('plan.md', '# Neuer Plan');

        $this->assertFalse($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertArrayNotHasKey('If-Match', $puts[0]['headers']);
    }

    /**
     * Fehlende Ordnerebenen werden per MKCOL angelegt (Issue #491, Spec #486 §4).
     */
    public function test_creates_missing_folder_levels_via_mkcol(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $this->write('faecher/mathe/profil.md', '# Mathe');

        $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
        $this->assertNotEmpty($mkcols);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
    }

    /**
     * Ein 412 (Konflikt zwischen Lesen und Schreiben) ergibt `Konflikt` mit
     * der Anweisung, neu zu lesen und zusammenzufuehren - und legt keinen
     * Ausstand an (Issue #491, Spec #486 §4/§6). Der Inhalt bleibt dabei
     * unangetastet.
     */
    public function test_external_conflict_reports_konflikt_and_leaves_content_unchanged(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        $decorator = new \local_kurspilot\tests\webdav\stale_read_transport($fake, '/Kurspilot/Kontext/plan.md', $fake);
        webdav_instance::use_test_transport($decorator);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }

        // Weder der alte noch der neu versuchte Inhalt kommt vom
        // fehlgeschlagenen PUT - stehen bleibt die "Handaenderung", die der
        // Decorator zwischen Lesen und Schreiben simuliert hat.
        $this->assertSame('handaenderung', $this->external_content($fake, '/Kurspilot/Kontext/plan.md'));
    }

    /**
     * Anlegen ({@see \local_kurspilot\pointer_writer::write()} liest die
     * Datei zunaechst als fehlend, faehrt dann `put_new()` mit
     * `If-None-Match: *`) gegen eine inzwischen angelegte Datei ergibt 412 -
     * `Konflikt`, und der zwischenzeitlich entstandene Inhalt bleibt
     * unangetastet (Issue #491 Testvorgabe: "Ein Anlegen auf eine vorhandene
     * Datei ergibt 412 und laesst den Inhalt unveraendert.").
     */
    public function test_external_create_conflicts_when_file_appears_meanwhile(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $decorator = new \local_kurspilot\tests\webdav\stale_read_transport($fake, '/Kurspilot/Kontext/plan.md', $fake);
        webdav_instance::use_test_transport($decorator);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }

        $this->assertSame('handaenderung', $this->external_content($fake, '/Kurspilot/Kontext/plan.md'));
    }

    /**
     * Ein voller externer Speicher (507) ergibt `Speicher voll`.
     */
    public function test_external_storage_full_reports_speicher_voll(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(
                \local_kurspilot\webdav\webdav_error::STORAGE_FULL,
                $e->getMessage()
            );
        }
    }

    /**
     * Extern meldet die Restquote "keine Grenze" - die Moodle-Quotenpruefung
     * wirkt nicht (Issue #491, Spec #486 §6).
     */
    public function test_external_write_ignores_moodle_quota(): void {
        global $CFG;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $CFG->userquota = 1;

        $result = $this->write('plan.md', str_repeat('x', 4096));

        $this->assertTrue($result['created']);
    }

    /**
     * `moodle/user:manageownfiles` wirkt extern nicht (Issue #491, Spec #486 §6).
     */
    public function test_external_write_succeeds_without_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $result = $this->write('plan.md', '# Plan');

        $this->assertTrue($result['created']);
    }

    /**
     * @param fake_webdav_transport $fake
     * @param string $path
     * @return string
     */
    private function external_content(fake_webdav_transport $fake, string $path): string {
        // Kein oeffentlicher Lesezugriff auf den internen Speicher des Fakes -
        // ueber den Client selbst nachlesen, exakt wie ein echter Aufrufer.
        $client = new \local_kurspilot\webdav\webdav_client($fake);
        return $client->get('https://fake.example' . $path);
    }

    /**
     * Die Lehrkraft liest die Antwort auf Deutsch - dort muss ausdruecklich
     * "neu angelegt" bzw. "ueberschrieben" mit vorheriger und neuer Groesse
     * stehen (Spec 0016 §5.4). Geprueft am deutschen Sprachpaket, weil die
     * PHPUnit-Instanz nur Englisch aufgeloest bekommt.
     */
    public function test_german_messages_carry_the_required_wording(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_kurspilot.php');

        $this->assertStringContainsString('neu angelegt', $string['contextfilecreated']);
        $this->assertStringContainsString('überschrieben', $string['contextfileoverwritten']);
        $this->assertStringContainsString('{$a->before}', $string['contextfileoverwritten']);
        $this->assertStringContainsString('{$a->after}', $string['contextfileoverwritten']);
        $this->assertStringContainsString('neu lesen', $string['contextfilechanged']);
        $this->assertStringContainsString('MB', $string['contextquotaexceeded']);
    }

    /**
     * Der Endpunkt haengt am Kurspilot-Dienst und steht in der Allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'kurspilot_write_context_file',
            \local_kurspilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_kurspilot_write_context_file',
            \local_kurspilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_kurspilot\tool_registry::is_write('kurspilot_write_context_file'));
    }

    /**
     * Kein Parameter erlaubt es, contextid/itemid/component zu waehlen.
     */
    public function test_execute_parameters_expose_no_area_selector(): void {
        $this->assertSame(
            ['path', 'content', 'expected_contenthash'],
            array_keys(write_context_file::execute_parameters()->keys)
        );
    }

    /**
     * Person A schreibt nie in den Bereich von Person B - der Schreibvorgang
     * landet im eigenen Nutzerkontext.
     */
    public function test_writes_only_into_own_area(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teacherb);
        $this->write('plan.md', '# B');

        $this->assertNull($this->stored_file($teachera, '/kurspilot/', 'plan.md'));
        $this->assertSame('# B', $this->read_stored($teacherb, '/kurspilot/', 'plan.md'));
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @return array Bereinigte Antwort des Endpunkts.
     */
    private function write(string $path, string $content, string $expectedcontenthash = ''): array {
        $result = write_context_file::execute($path, $content, $expectedcontenthash);
        return external_api::clean_returnvalue(write_context_file::execute_returns(), $result);
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
