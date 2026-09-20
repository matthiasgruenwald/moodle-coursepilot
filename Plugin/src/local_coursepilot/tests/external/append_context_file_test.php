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
use local_coursepilot\context_files;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Anhaengen im Kontextbereich (Issue #409, Spec 0016 Paragraph 4.2).
 * Happy-Path, Neuanlegen, Personenbezug der Zieldatei, das weiche
 * 1-MB-Signal und die Alles-oder-nichts-Zusage. Seit Issue #491 zusaetzlich
 * der externe Zweig: Read-modify-write mit `If-Match`, verpflichtender
 * Rotationshinweis.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(append_context_file::class)]
final class append_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::set_transport(null);
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
        $this->create_context_file($user, '/coursepilot/', 'journal.md', "# Journal\n");

        $result = $this->append('journal.md', "- Stunde 1\n");

        $this->assertFalse($result['created']);
        $this->assertSame("# Journal\n- Stunde 1\n", $this->read_stored($user, '/coursepilot/', 'journal.md'));
        $this->assertSame(21, $result['size']);
        $this->assertSame(
            get_string('contextfileappended', 'local_coursepilot', (object) [
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
            get_string('contextfilecreated', 'local_coursepilot', 'journal.md'),
            $result['message']
        );
        $this->assertSame('# Journal', $this->read_stored($user, '/coursepilot/', 'journal.md'));
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

        // Genauer Fehlerschluessel, nicht nur "irgendeine moodle_exception"
        // (Issue #540 Regressionsschutz, siehe das Gegenstueck in
        // write_context_file_test): ohne die Ausnahme in
        // context_area::is_moodle_call_error() wuerde die seit #540 neue
        // Ausfallbehandlung das faelschlich als Ausstand vermerken.
        try {
            $this->append('notiz.txt', 'x');
            $this->fail('Falsche Dateiendung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        }
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
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
        $this->create_context_file($user, '/coursepilot/', 'journal.md', str_repeat('x', 1024 * 1024));

        $result = $this->append('journal.md', 'y');

        $this->assertSame(1024 * 1024 + 1, $result['size']);
        $this->assertStringContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
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
        $this->create_context_file($user, '/coursepilot/', 'journal.md', 'kurz');

        $result = $this->append('journal.md', 'x');

        $this->assertStringNotContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
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
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        try {
            $this->append('lerngruppe.md', "\n- Notiz");
            $this->fail('Personenbezug haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->read_stored($user, '/coursepilot/', 'lerngruppe.md')
        );
    }

    /**
     * Bei eingeschaltetem Schalter geht derselbe Append durch.
     */
    public function test_appends_to_marked_target_file_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $this->append('lerngruppe.md', "\n- Notiz");

        $this->assertSame(
            $this->marked_content() . "\n- Notiz",
            $this->read_stored($user, '/coursepilot/', 'lerngruppe.md')
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
     * Anhaengen an eine bereits markierte externe Zieldatei ist an einem
     * nicht zugelassenen Speicher abgewiesen (Issue #493, ADR 0021 §3) - auch
     * wenn der #344-Schalter an ist.
     */
    public function test_rejects_append_to_marked_file_at_disallowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        try {
            $this->append('lerngruppe.md', "\n- Notiz");
            $this->fail('Nicht zugelassener Speicher haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }
    }

    /**
     * Auch eine neu entstehende Datei, deren Anhaengsel selbst schon
     * markiert ist, geht nicht an einen nicht zugelassenen Speicher -
     * "geprueft wird die ganze entstehende Datei" (Spec #486 §6).
     */
    public function test_rejects_append_creating_marked_file_at_disallowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        try {
            $this->append('lerngruppe.md', $this->marked_content());
            $this->fail('Nicht zugelassener Speicher haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }
    }

    /**
     * Am zugelassenen Speicher geht dasselbe Anhaengen durch.
     */
    public function test_accepts_append_to_marked_file_at_allowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        set_config('personaldatahosts', 'example.test', 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $result = $this->append('lerngruppe.md', "\n- Notiz");

        $this->assertFalse($result['created']);
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
        $this->create_context_file($user, '/coursepilot/', 'journal.md', 'alt');

        try {
            $this->append('journal.md', str_repeat('x', 2048));
            $this->fail('Quotenueberschreitung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('MB', $e->getMessage());
        }

        $this->assertSame('alt', $this->read_stored($user, '/coursepilot/', 'journal.md'));
    }

    /**
     * Der Moodle-Zweig braucht keinen vorher gelesenen Stand: Lesen,
     * Zusammenfuegen und Schreiben passieren dort in einem Serveraufruf
     * (Spec 0016 §4.2/§5.3). "expected_contenthash" existiert trotzdem als
     * Parameter - er wirkt nur extern (Issue #513, Spec #486 §6: "Anhaengen
     * nutzt den Pruefwert ebenso"), weil dort tatsaechlich ein fruehes Lesen
     * vorausgehen kann. Befund aus Issue #513: der urspruengliche Test
     * (`['path', 'content', 'ausstand']`) galt vor diesem Parameter.
     */
    public function test_execute_parameters_expose_expected_contenthash_for_the_external_branch(): void {
        $this->assertSame(
            ['path', 'content', 'ausstand', 'expected_contenthash', 'courseid'],
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

        $this->assertSame('abc', $this->read_stored($user, '/coursepilot/', 'journal.md'));
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

        $this->assertNull($this->stored_file($teachera, '/coursepilot/', 'journal.md'));
        $this->assertSame('# B', $this->read_stored($teacherb, '/coursepilot/', 'journal.md'));
    }

    /**
     * Die Lehrkraft liest die Antwort auf Deutsch - "angehängt" mit
     * Gesamtgroesse und der Rotationshinweis muessen dort stehen
     * (Spec 0016 §5.4). Geprueft am deutschen Sprachpaket, weil die
     * PHPUnit-Instanz nur Englisch aufgeloest bekommt.
     */
    public function test_german_messages_carry_the_required_wording(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');

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
        $fake->seed_folder('/Coursepilot/Kontext');
        $seeded = $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");

        $result = $this->append('journal.md', "- Stunde 1\n");

        $this->assertFalse($result['created']);
        $this->assertSame(21, $result['size']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame($seeded['etag'], $puts[0]['headers']['If-Match'] ?? null);
        $this->assertSame("# Journal\n- Stunde 1\n", $puts[0]['body']);
    }

    /**
     * Konfliktschutz mit dem gelesenen Pruefwert (Issue #513, Spec #486
     * §4/§6): Ein zweiter Chat schreibt zwischen dem Lesen und dem Anhaengen
     * des ersten - die Handaenderung passiert direkt am Fake-Speicher, lange
     * vor dem eigentlichen Aufruf, kein Decorator noetig.
     */
    public function test_stale_checkvalue_from_earlier_read_is_rejected_as_conflict(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");

        $gelesen = read_context_file::execute('journal.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n- Handaenderung\n");

        try {
            $this->append('journal.md', "- Stunde 1\n", $gelesen['contenthash']);
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }

        $this->assertSame(
            "# Journal\n- Handaenderung\n",
            $this->external_content($fake, '/Coursepilot/Kontext/journal.md')
        );
    }

    /**
     * Passt der mitgegebene Pruefwert zum aktuellen Stand, geht das
     * Anhaengen wie gewohnt durch (Issue #513).
     */
    public function test_matching_checkvalue_allows_append(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");

        $gelesen = read_context_file::execute('journal.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $this->append('journal.md', "- Stunde 1\n", $gelesen['contenthash']);

        $this->assertSame(
            "# Journal\n- Stunde 1\n",
            $this->external_content($fake, '/Coursepilot/Kontext/journal.md')
        );
    }

    /**
     * Nachtragen mit "ausstand=" ueberschreibt nie ungeprueft (Entscheidung
     * zu Issue #513): Fehlt der Pruefwert, obwohl die Zieldatei bereits
     * existiert, geht das Nachtragen als Konflikt zurueck statt gewachsenen
     * Bestand stillschweigend zu erweitern.
     */
    public function test_ausstand_retry_without_checkvalue_is_rejected_when_file_exists(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['eintraege'][0]['kennung'];

        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        $fake2->seed_file('/Coursepilot/Kontext/journal.md', 'inzwischen gewachsen');
        webdav_instance::set_transport($fake2);

        try {
            append_context_file::execute('journal.md', 'x', $kennung);
            $this->fail('Nachtragen ohne Pruefwert haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }
        $this->assertSame('inzwischen gewachsen', $this->external_content($fake2, '/Coursepilot/Kontext/journal.md'));
    }

    /**
     * Fehlt die Zieldatei extern, entsteht sie ueber `If-None-Match: *`.
     */
    public function test_creates_missing_external_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $result = $this->append('journal.md', '# Journal');

        $this->assertTrue($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertSame('*', $puts[0]['headers']['If-None-Match'] ?? null);
    }

    /**
     * Fehlt die Kontextbereich-Wurzel am externen Ort, legt auch Anhaengen
     * nichts an - derselbe Schutz wie beim Schreiben (Issue #514, siehe
     * {@see \local_coursepilot\external\write_context_file_test::test_rejects_write_when_context_root_is_missing_and_creates_no_folder()}).
     */
    public function test_rejects_append_when_context_root_is_missing_and_creates_no_folder(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        // Bewusst kein $fake->seed_folder('/Coursepilot/Kontext') - die Wurzel fehlt.

        try {
            $this->append('journal.md', '# Journal');
            $this->fail('Fehlende Kontextbereich-Wurzel haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('contextrootmissing', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
        $this->assertSame([], array_values(array_filter(
            $fake->requests(),
            static fn (array $r): bool => in_array($r['method'], ['PUT', 'MKCOL'], true)
        )));
    }

    /**
     * Fehlende Ordnerebenen werden auch beim Anhaengen per MKCOL angelegt,
     * die Kontextbereich-Wurzel selbst aber nie mitgebaut (Issue #514,
     * Akzeptanzkriterium 2+3 - Gegenstueck zu
     * {@see \local_coursepilot\external\write_context_file_test::test_creates_missing_folder_levels_via_mkcol()}
     * fuer den Anhaeng-Endpunkt).
     */
    public function test_creates_missing_folder_levels_via_mkcol_without_touching_the_root(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $this->append('faecher/mathe/journal.md', '# Mathe');

        $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
        $this->assertNotEmpty($mkcols);
        $roottargets = array_filter($mkcols, static function (array $r): bool {
            return rtrim((string) parse_url($r['url'], PHP_URL_PATH), '/') === '/Coursepilot/Kontext';
        });
        $this->assertSame([], array_values($roottargets));
    }

    /**
     * Der Rotationshinweis folgt extern derselben 1-MB-Grenze wie im
     * Moodle-Zweig (Issue #505 Befund #9): der Text nennt ausdruecklich "1
     * MB", eine unbedingte Anzeige waere bei kleinen Dateien irrefuehrend.
     */
    public function test_external_append_over_limit_carries_rotation_hint(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', str_repeat('x', 1024 * 1024));

        $result = $this->append('journal.md', 'y');

        $this->assertSame(1024 * 1024 + 1, $result['size']);
        $this->assertStringContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
            $result['message']
        );
    }

    /**
     * Unterhalb der Grenze steht extern kein Rotationshinweis (Issue #505
     * Befund #9).
     */
    public function test_external_append_under_limit_has_no_rotation_hint(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', 'kurz');

        $result = $this->append('journal.md', 'x');

        $this->assertStringNotContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
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
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
            $this->assertStringContainsString('journal.md', $e->getMessage());
            $this->assertStringContainsString('Kennung', $e->getMessage());
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('journal.md', $ausstaende[0]['pfad']);
        $this->assertSame('anhängen', $ausstaende[0]['eintraege'][0]['vorgang']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['eintraege'][0]['fehlerklasse']
        );
    }

    /**
     * Eine abgelehnte Anmeldung (401) *beim Vorab-Lesen* der Zieldatei
     * (Personenbezugs-Vorpruefung) darf den Anhaengevorgang nicht ohne
     * Ausstand abbrechen (Issue #505 Befund #10): derselbe Ausfall trifft
     * den anschliessenden echten Schreibversuch erneut, der ihn dann
     * vollstaendig behandelt - genau wie beim Ueberschreiben.
     */
    public function test_external_append_records_ausstand_on_login_rejected_during_preread(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->deny_auth();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Abgelehnte Anmeldung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('journal.md', $ausstaende[0]['pfad']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::AUTH_REJECTED,
            $ausstaende[0]['eintraege'][0]['fehlerklasse']
        );
    }

    /**
     * Eine geloeschte WebDAV-Instanz beim Vorab-Lesen legt beim Anhaengen
     * ebenfalls einen Ausstand an (Issue #505 Befund #10) - anders als beim
     * Ueberschreiben (write_context_file_test::test_deleted_webdav_instance_records_ausstand)
     * fehlte diese Behandlung bislang: das Vorab-Lesen des Anhaengens nutzte
     * einen Lesezweig ohne die dortige Ausfall-Toleranz.
     */
    public function test_external_append_records_ausstand_on_deleted_instance(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $pointerlocation = context_files::resolve_pointer_location();
        $DB->delete_records('repository_instances', ['id' => $pointerlocation->instanceid]);

        try {
            $this->append('journal.md', 'x');
            $this->fail('Geloeschte Instanz haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdavinstancemissing', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
    }

    /**
     * Jeder Eintrag der Ausstandsnotiz nennt die Kurs-ID (Issue #516
     * Akzeptanzkriterium) - auch beim Anhaengen.
     */
    public function test_ausstand_entry_carries_course_id(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            append_context_file::execute('journal.md', 'x', '', '', 42);
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(42, $ausstaende[0]['eintraege'][0]['kursid']);
    }

    /**
     * Pruefung 8 (IServ-Bereich, Issue #497/#516, Spec #486 §2/§8) erzeugt
     * beim Anhaengen ebenfalls einen Ausstand.
     */
    public function test_iserv_pruefung_8_records_ausstand_on_append(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Groups/Klasse7a', [
            'server' => $this->fixtureserver,
            'basispfad' => $this->fixturebasispfad,
            'konto' => $this->fixturekonto,
            'iserv' => true,
        ]);
        $fake = new fake_webdav_transport();
        webdav_instance::set_transport($fake);

        try {
            $this->append('journal.md', 'x');
            $this->fail('Pfad ausserhalb von "Files/" haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdaviservfilesonly', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
        $this->assertSame([], $fake->requests());
    }

    /**
     * Ein ungueltiger Pfad bleibt ein Aufruffehler, auch wenn zugleich
     * Pruefung 8 (IServ) den Ort scheitern liesse - siehe das Gegenstueck in
     * write_context_file_test.php (Issue #541 Code-Review-Befund).
     */
    public function test_iserv_pruefung_8_does_not_shadow_an_invalid_path(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Groups/Klasse7a', [
            'server' => $this->fixtureserver,
            'basispfad' => $this->fixturebasispfad,
            'konto' => $this->fixturekonto,
            'iserv' => true,
        ]);
        $fake = new fake_webdav_transport();
        webdav_instance::set_transport($fake);

        try {
            $this->append('notiz.txt', 'x');
            $this->fail('Eine unerlaubte Endung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
        $this->assertSame([], $fake->requests());
    }

    /**
     * `ausstand=<Kennung>` hakt den Eintrag beim erfolgreichen Nachtragen
     * per Anhaengen ab (ADR 0023 Punkt 3).
     */
    public function test_ausstand_parameter_dismisses_entry_on_successful_append(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['eintraege'][0]['kennung'];

        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        webdav_instance::set_transport($fake2);

        $result = append_context_file::execute('journal.md', 'x', $kennung);
        $result = external_api::clean_returnvalue(append_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * `moodle/user:manageownfiles` und die Nutzerquote wirken extern nicht
     * (Issue #491, Spec #486 §6).
     */
    public function test_external_append_ignores_moodle_quota_and_capability(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
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
     * Der Endpunkt haengt am Coursepilot-Dienst und steht in der Allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_append_context_file',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_append_context_file',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_append_context_file'));
    }

    /**
     * @param string $path
     * @param string $content
     * @return array Bereinigte Antwort des Endpunkts.
     */
    private function append(string $path, string $content, string $expectedcontenthash = ''): array {
        $result = append_context_file::execute($path, $content, '', $expectedcontenthash);
        return external_api::clean_returnvalue(append_context_file::execute_returns(), $result);
    }

    /**
     * @param fake_webdav_transport $fake
     * @param string $path
     * @return string
     */
    private function external_content(fake_webdav_transport $fake, string $path): string {
        // Kein oeffentlicher Lesezugriff auf den internen Speicher des Fakes -
        // ueber den Client selbst nachlesen, exakt wie ein echter Aufrufer.
        $client = new \local_coursepilot\webdav\webdav_client($fake);
        return $client->get('https://fake.example' . $path);
    }

    /**
     * @return string Inhalt mit Frontmatter-Markierung "personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n---\n# S. M., 7a";
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
