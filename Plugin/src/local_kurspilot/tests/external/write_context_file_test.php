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
     * Eine personenbezogen markierte Datei geht extern nicht an einen nicht
     * zugelassenen Speicher, auch wenn der #344-Schalter an ist (Issue #493,
     * ADR 0021 §3) - und es entsteht kein PUT.
     */
    public function test_rejects_marked_content_at_disallowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_kurspilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        try {
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Nicht zugelassener Speicher haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }

        $this->assertSame([], array_values(array_filter(
            $fake->requests(),
            static fn (array $r): bool => $r['method'] === 'PUT'
        )));
    }

    /**
     * Am zugelassenen Speicher (Domain samt Unterdomain) geht dieselbe
     * markierte Datei durch.
     */
    public function test_accepts_marked_content_at_allowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_kurspilot');
        set_config('personaldatahosts', 'example.test', 'local_kurspilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $result = $this->write('lerngruppe.md', $this->marked_content());

        $this->assertTrue($result['created']);
    }

    /**
     * Bei ausgeschaltetem #344-Schalter greift weiterhin "contextfilelocked",
     * nicht die Speicher-Zulassungspruefung - beide Gruende sind unabhaengig
     * voneinander.
     */
    public function test_marked_content_at_disallowed_host_with_switch_off_reports_locked(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        try {
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Personenbezug haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }
    }

    /**
     * Unmarkierter Inhalt geht an jeden Speicher, unabhaengig von
     * `personaldatahosts` - die Pruefung gilt nur der Markierung.
     */
    public function test_unmarked_content_ignores_host_allowlist(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $result = $this->write('plan.md', '# Unmarkiert');

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
     * Konfliktschutz mit dem gelesenen Pruefwert (Issue #513, Spec #486
     * §4/§6): Ein zweiter Chat schreibt zwischen dem Lesen und dem Schreiben
     * des ersten - nicht innerhalb des Schreibaufrufs, sondern lange davor
     * (Transport-Fake: die Handaenderung passiert direkt am Fake-Speicher,
     * kein Decorator noetig). Der mitgegebene "expected_contenthash" aus dem
     * fruehen Lesen passt dann nicht mehr zum aktuellen Stand - `Konflikt`,
     * der urspruengliche Inhalt bleibt die Handaenderung, nicht der Versuch.
     */
    public function test_stale_checkvalue_from_earlier_read_is_rejected_as_conflict(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        $gelesen = read_context_file::execute('plan.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        // Der zweite Chat schreibt, lange bevor der erste ueberhaupt zum
        // Schreiben kommt - nicht im Aufruf selbst.
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'handaenderung');

        try {
            $this->write('plan.md', '# Neuer Plan', $gelesen['contenthash']);
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }

        $this->assertSame('handaenderung', $this->external_content($fake, '/Kurspilot/Kontext/plan.md'));
    }

    /**
     * Passt der mitgegebene Pruefwert zum aktuellen Stand, geht das
     * Ueberschreiben wie gewohnt durch (Issue #513).
     */
    public function test_matching_checkvalue_allows_overwrite(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        $gelesen = read_context_file::execute('plan.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $result = $this->write('plan.md', '# Neuer Plan', $gelesen['contenthash']);

        $this->assertFalse($result['created']);
        $this->assertSame('# Neuer Plan', $this->external_content($fake, '/Kurspilot/Kontext/plan.md'));
    }

    /**
     * Ohne ETag (IServ) wirkt der Vergleich ueber die Aenderungszeit (Issue
     * #513, Spec §4) - derselbe Konfliktschutz, nur mit dem schwaecheren
     * Ersatzmerkmal.
     */
    public function test_stale_checkvalue_without_etag_is_rejected_as_conflict_iserv_mode(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        $gelesen = read_context_file::execute('plan.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'handaenderung');

        try {
            $this->write('plan.md', '# Neuer Plan', $gelesen['contenthash']);
            $this->fail('Konflikt haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }
    }

    /**
     * Nachtragen mit "ausstand=" ueberschreibt nie ungeprueft (Entscheidung
     * zu Issue #513): Fehlt der Pruefwert, obwohl die Zieldatei bereits
     * existiert, geht das Nachtragen als Konflikt zurueck statt gewachsenen
     * Bestand stillschweigend zu ersetzen.
     */
    public function test_ausstand_retry_without_checkvalue_is_rejected_when_file_exists(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }
        $kennung = \local_kurspilot\ausstand_notice::list_grouped()[0]['eintraege'][0]['kennung'];

        $fake2 = new \local_kurspilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Kurspilot/Kontext');
        $fake2->seed_file('/Kurspilot/Kontext/plan.md', 'inzwischen gewachsen');
        webdav_instance::use_test_transport($fake2);

        try {
            write_context_file::execute('plan.md', '# Plan', '', $kennung);
            $this->fail('Nachtragen ohne Pruefwert haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfileexternalconflict', $e->errorcode);
        }
        $this->assertSame('inzwischen gewachsen', $this->external_content($fake2, '/Kurspilot/Kontext/plan.md'));
    }

    /**
     * Nachtragen auf eine weiterhin fehlende Zieldatei braucht keinen
     * Pruefwert - "anlegen" ist ueber "If-None-Match: *" bereits sicher.
     */
    public function test_ausstand_retry_creates_missing_file_without_checkvalue(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }
        $kennung = \local_kurspilot\ausstand_notice::list_grouped()[0]['eintraege'][0]['kennung'];

        $fake2 = new \local_kurspilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Kurspilot/Kontext');
        webdav_instance::use_test_transport($fake2);

        $result = write_context_file::execute('plan.md', '# Plan', '', $kennung);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_kurspilot\ausstand_notice::list_grouped());
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
     * Ein voller externer Speicher (507) ist ein Ausfall im Sinne von ADR
     * 0023 (Issue #492): die Antwort nennt Pfad+Vorgang, die Ursache in
     * Lehrkraftsprache, "noch nicht gespeichert" mit Kennung, eine Anweisung
     * an die KI und Verbindung (Instanzname + Host) - nie einen HTTP-Code
     * oder Antwortrumpf. Zusaetzlich entsteht ein Eintrag in der
     * Ausstandsnotiz.
     */
    public function test_external_storage_full_records_ausstand_with_five_part_message(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_kurspilot\ausstand_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['pfad']);
        $this->assertCount(1, $ausstaende[0]['eintraege']);
        $this->assertSame('anlegen', $ausstaende[0]['eintraege'][0]['vorgang']);
        $this->assertSame(
            \local_kurspilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['eintraege'][0]['fehlerklasse']
        );

        // Sprachneutral: die variablen Teile muessen auftauchen, unabhaengig
        // davon, welches Sprachpaket die PHPUnit-Instanz aufloest (Englisch,
        // siehe test_german_messages_carry_the_required_wording()).
        $kennung = $ausstaende[0]['eintraege'][0]['kennung'];
        $this->assertStringContainsString('plan.md', $message);
        $this->assertStringContainsString('anlegen', $message);
        $this->assertStringContainsString($kennung, $message);
        $this->assertStringContainsString('Meine Cloud', $message);
        $this->assertStringContainsString($this->fixtureserver, $message);
        // Geheimnis-Test (Spec #486 Testing Decisions): kein HTTP-Code, kein
        // Antwortrumpf, kein Passwort.
        $this->assertStringNotContainsString('507', $message);
        $this->assertStringNotContainsString($fake->secret(), $message);
    }

    /**
     * Ueberschreiben einer bestehenden externen Datei traegt den Vorgang
     * "überschreiben" in den Ausstand ein, nicht "anlegen".
     */
    public function test_external_overwrite_failure_records_ueberschreiben_operation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        // Nur PUT scheitert - MKCOL (Ordner existiert bereits) und PROPFIND
        // (Existenzpruefung, entscheidet "anlegen" vs. "ueberschreiben")
        // laufen normal durch. fake_webdav_transport::fill_storage() liesse
        // sich hier nicht nutzen: es blockt MKCOL VOR der Existenzpruefung
        // pauschal, bevor pointer_writer ueberhaupt weiss, ob die Datei
        // schon da ist (siehe test_external_storage_full_records_ausstand_with_five_part_message
        // fuer den "anlegen"-Fall, der genau das ausnutzt).
        $onlyputfails = new class($fake) implements \local_kurspilot\webdav\webdav_transport {
            public function __construct(private readonly fake_webdav_transport $inner) {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_kurspilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    return new \local_kurspilot\webdav\webdav_response(507, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        webdav_instance::use_test_transport($onlyputfails);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_kurspilot\ausstand_notice::list_grouped();
        $this->assertSame('überschreiben', $ausstaende[0]['eintraege'][0]['vorgang']);
    }

    /**
     * Eine geloeschte WebDAV-Instanz (ADR 0023: "eine geloeschte Instanz")
     * legt ebenfalls einen Ausstand an - nicht nur ein {@see \local_kurspilot\webdav\webdav_error}.
     */
    public function test_deleted_webdav_instance_records_ausstand(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $pointerlocation = context_files::resolve_pointer_location();
        $DB->delete_records('repository_instances', ['id' => $pointerlocation->instanceid]);

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Geloeschte Instanz haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_kurspilot\ausstand_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdavinstancemissing', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
    }

    /**
     * Eine entzogene WebDAV-Freischaltung (ADR 0023: "eine entzogene
     * Freischaltung") legt ebenfalls einen Ausstand an.
     */
    public function test_revoked_webdav_freischaltung_records_ausstand(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $DB->delete_records('role_capabilities', ['capability' => 'repository/webdav:view']);
        accesslib_clear_all_caches_for_unit_testing();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Entzogene Freischaltung haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_kurspilot\ausstand_notice::list_grouped();
        $this->assertSame('webdavnotenabled', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
    }

    /**
     * Ein geaendertes Pruefmerkmal (ADR 0023: "ein geaendertes
     * Pruefmerkmal") legt ebenfalls einen Ausstand an.
     */
    public function test_changed_fingerprint_records_ausstand(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $DB->set_field(
            'repository_instance_config',
            'value',
            'anderer-server.test',
            ['name' => 'webdav_server']
        );

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Geaendertes Pruefmerkmal haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }

        $ausstaende = \local_kurspilot\ausstand_notice::list_grouped();
        $this->assertSame('webdavfingerprintchanged', $ausstaende[0]['eintraege'][0]['fehlerklasse']);
    }

    /**
     * Konflikt (412) legt ausdruecklich keinen Ausstand an (ADR 0023 Punkt
     * 2: "Ausgenommen sind Aufruffehler und Konflikt").
     */
    public function test_external_conflict_records_no_ausstand_entry(): void {
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

        $this->assertSame([], \local_kurspilot\ausstand_notice::list_grouped());
    }

    /**
     * Ein Aufruffehler (hier: zu grosser Inhalt) legt keinen Ausstand an
     * (ADR 0023 Punkt 2) - der Inhalt liegt noch im Gespraech.
     */
    public function test_call_error_records_no_ausstand_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');

        $this->expectException(\moodle_exception::class);
        try {
            $this->write('plan.md', str_repeat('x', 1024 * 1024 + 1));
        } finally {
            $this->assertSame([], \local_kurspilot\ausstand_notice::list_grouped());
        }
    }

    /**
     * `ausstand=<Kennung>` an einem erfolgreichen Schreibvorgang hakt den
     * Eintrag im selben Aufruf ab (ADR 0023 Punkt 3: Nachtragen).
     */
    public function test_ausstand_parameter_dismisses_entry_on_successful_retry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Speicher voll haette abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('ausstandwritefailed', $e->errorcode);
        }
        $kennung = \local_kurspilot\ausstand_notice::list_grouped()[0]['eintraege'][0]['kennung'];

        // Neuer Fake statt des vollen - "der Speicher antwortet wieder".
        $fake2 = new \local_kurspilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Kurspilot/Kontext');
        webdav_instance::use_test_transport($fake2);

        $result = write_context_file::execute('plan.md', '# Plan', '', $kennung);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_kurspilot\ausstand_notice::list_grouped());
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

        // Fuenfteilige Ausfallantwort (Issue #492, ADR 0023): Pfad+Vorgang,
        // Ursache, "noch nicht gespeichert" mit Kennung, Anweisung an die
        // KI, Instanzname+Host - echte Umlaute, kein ae/oe/ue-Ersatz.
        $this->assertStringContainsString('{$a->path}', $string['ausstandwritefailed']);
        $this->assertStringContainsString('{$a->operation}', $string['ausstandwritefailed']);
        $this->assertStringContainsString('{$a->reason}', $string['ausstandwritefailed']);
        $this->assertStringContainsString('Noch nicht gespeichert', $string['ausstandwritefailed']);
        $this->assertStringContainsString('Kennung {$a->kennung}', $string['ausstandwritefailed']);
        $this->assertStringContainsString('ausstand="{$a->kennung}"', $string['ausstandwritefailed']);
        $this->assertStringContainsString('{$a->target}', $string['ausstandwritefailed']);
        $this->assertStringContainsString('voll', $string['ausstandnotewritefailed']);
        $this->assertStringContainsString('Speicherplatz', $string['ausstandnotequotaexceeded']);

        // Der Fehlertext fuer einen nicht zugelassenen Speicher (Issue #493,
        // ADR 0021 §3) lautet wortwoertlich "Dieser Speicher ist für
        // personenbezogene Daten nicht zugelassen".
        $this->assertStringContainsString(
            'Dieser Speicher ist für personenbezogene Daten nicht zugelassen',
            $string['contextfilehostnotallowed']
        );
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
            ['path', 'content', 'expected_contenthash', 'ausstand', 'nur_anlegen'],
            array_keys(write_context_file::execute_parameters()->keys)
        );
    }

    /**
     * "nur_anlegen" schuetzt eine vorhandene Moodle-Datei vor Ueberschreiben -
     * die Garantie fuer das Kopieren aus dem Altbestand (Issue #498, Spec
     * #486 §9: "am neuen Ort wird also nie ueberschrieben").
     */
    public function test_nur_anlegen_rejects_existing_moodle_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/kurspilot/', 'plan.md', 'alt');

        try {
            write_context_file::execute('plan.md', '# Neu', '', '', true);
            $this->fail('Ueberschreiben haette mit nur_anlegen abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilealreadyexists', $e->errorcode);
        }

        $this->assertSame('alt', $this->read_stored($user, '/kurspilot/', 'plan.md'));
    }

    /**
     * Ohne vorhandene Datei legt "nur_anlegen" ganz normal an.
     */
    public function test_nur_anlegen_creates_new_moodle_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = write_context_file::execute('plan.md', '# Neu', '', '', true);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
    }

    /**
     * Extern schuetzt "nur_anlegen" ebenso vor Ueberschreiben - kein PUT auf
     * die vorhandene Datei.
     */
    public function test_nur_anlegen_rejects_existing_external_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Kurspilot/Kontext');
        $fake->seed_file('/Kurspilot/Kontext/plan.md', 'alt');

        try {
            write_context_file::execute('plan.md', '# Neu', '', '', true);
            $this->fail('Ueberschreiben haette mit nur_anlegen abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilealreadyexists', $e->errorcode);
        }

        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertSame([], $puts);
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
