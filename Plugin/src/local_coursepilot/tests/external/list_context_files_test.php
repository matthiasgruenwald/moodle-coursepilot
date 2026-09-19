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
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Auflisten des Kontextbereichs (Issue #343). Sicherheitsrelevant: gedeckt
 * werden neben dem Happy-Path echte Angriffstests fuer Pfadausbruch und
 * Personen-Isolation. Seit Issue #490 zusaetzlich der externe Zweig ueber
 * einen Kontextpointer der zweiten Fassung und den WebDAV-Transport-Fake.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(list_context_files::class)]
final class list_context_files_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        webdav_instance::use_test_transport(null);
        parent::tearDown();
    }

    /**
     * Die Vorlagendatei an der Wurzel ist ohne Sonderweg erreichbar - der
     * Standardaufruf ohne "path" listet die Wurzel.
     */
    public function test_lists_root_including_template_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Vorlagen');
        $this->create_context_file($user, '/coursepilot/', 'index.md', '# Index');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('vorlagen.md', $names);
        $this->assertContains('index.md', $names);
    }

    /**
     * Jeder Dateieintrag traegt contenthash und timemodified (Spec 0016 §2);
     * Ordnereintraege haben beides leer bzw. 0.
     */
    public function test_file_entries_carry_contenthash_and_timemodified(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Vorlagen');
        $this->create_context_file($user, '/coursepilot/faecher/', 'profil.md', '# Profil');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $file = $this->find_entry($result['entries'], 'vorlagen.md');
        $this->assertSame(sha1('# Vorlagen'), $file['contenthash']);
        $this->assertGreaterThan(0, $file['timemodified']);

        $folder = $this->find_entry($result['entries'], 'faecher');
        $this->assertSame('', $folder['contenthash']);
        $this->assertSame(0, $folder['timemodified']);
    }

    /**
     * Ein Unterordner erscheint an der Wurzel als Ordnereintrag und ist
     * ueber "path" selbst auflistbar.
     */
    public function test_lists_subfolder_contents(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/faecher/mathe/', 'profil.md', '# Mathe');

        $root = list_context_files::execute();
        $root = external_api::clean_returnvalue(list_context_files::execute_returns(), $root);
        $folders = array_filter($root['entries'], fn($entry) => $entry['type'] === 'folder');
        $this->assertContains('faecher', array_column($folders, 'name'));

        $sub = list_context_files::execute('faecher/mathe');
        $sub = external_api::clean_returnvalue(list_context_files::execute_returns(), $sub);
        $this->assertContains('profil.md', array_column($sub['entries'], 'name'));
    }

    /**
     * Der zurueckgegebene "path" ist derselbe Pfad, den die Werkzeuge auch
     * entgegennehmen: relativ zur Kontextwurzel, die Wurzel selbst leer.
     *
     * Vorher wurde der Wurzelordner mitgeliefert ("coursepilot"); wer daraus
     * einen Unterpfad baute, schrieb nach "coursepilot/..." und landete in
     * /coursepilot/coursepilot/... - genau der Fehler aus #425 F1.
     */
    public function test_returned_path_is_relative_to_the_context_root(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/fragetypen/', 'match.md', '# match');

        $root = list_context_files::execute();
        $root = external_api::clean_returnvalue(list_context_files::execute_returns(), $root);
        $this->assertSame('', $root['path']);

        $sub = list_context_files::execute('fragetypen');
        $sub = external_api::clean_returnvalue(list_context_files::execute_returns(), $sub);
        $this->assertSame('fragetypen', $sub['path']);
    }

    /**
     * Seit dem Umzug auf Private Files (#407) kann die Lehrkraft ueber
     * "Meine Dateien" beliebige Dateien im Ordner ablegen. Die Auflistung
     * liest deren Inhalt nicht ein - die Personenbezug-Markierung steht nur
     * im Frontmatter einer Markdown-Datei - und listet sie ungesperrt.
     */
    public function test_non_markdown_files_are_listed_without_reading_them(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Der Frontmatter-Marker steht hier drin, greift aber nicht: keine
        // .md-Datei, also auch keine Kontextdatei mit Frontmatter.
        $this->create_context_file($user, '/coursepilot/', 'notizen.txt', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'notizen.txt');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['locked']);
    }

    /**
     * Ein Pfad, der aus dem Bereich herausfuehren wuerde, wird abgewiesen
     * (CRITICAL) - Moodles eigene Parametervalidierung lehnt ein "../"-
     * Segment bereits an der API-Grenze ab, bevor der Aufloesungscode
     * ueberhaupt laeuft.
     */
    public function test_traversal_attempt_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Ausserhalb der organisatorischen Wurzel "/coursepilot/", aber noch
        // im selben Dateibereich - darf unter keinen Umstaenden ueber einen
        // Ausbruchsversuch erreichbar sein.
        $this->create_context_file($user, '/', 'secret.txt', 'geheim');

        $this->expectException(\moodle_exception::class);
        list_context_files::execute('../../secret');
    }

    /**
     * Auch ein Pfad, der die API-Grenze irgendwie passieren wuerde (z.B.
     * weil eine kuenftige Aenderung PARAM_PATH lockert), landet nicht
     * ausserhalb der Wurzel - die eigene Aufloesung in context_files prueft
     * unabhaengig davon jedes Segment.
     */
    public function test_resolved_directory_never_leaves_root(): void {
        $this->resetAfterTest();
        $this->assertStringStartsWith('/coursepilot/', context_files::resolve_directory('faecher/mathe'));
    }

    /**
     * Person A erreicht unter keinen Umstaenden den Bereich von Person B
     * (CRITICAL) - es gibt keinen Parameter, der einen fremden Bereich
     * adressieren koennte.
     */
    public function test_person_a_never_sees_person_bs_files(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $this->create_context_file($teachera, '/coursepilot/', 'lerngruppe-a.md', 'A');

        $this->setUser($teacherb);
        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $this->assertNotContains('lerngruppe-a.md', array_column($result['entries'], 'name'));
    }

    /**
     * Kein Parameter erlaubt es, einen anderen Dateibereich, eine fremde
     * itemid oder contextid anzugeben - strukturell erzwungen, nicht per
     * Konvention.
     */
    public function test_execute_parameters_expose_no_area_selector(): void {
        $definition = list_context_files::execute_parameters()->keys;
        $this->assertSame(['path', 'vorheriger_ort'], array_keys($definition));
    }

    /**
     * "vorheriger_ort" ohne offenen Altbestand ist ein benannter Fehler,
     * kein stilles leeres Ergebnis (Issue #498, Spec #486 §6: "wirkt nur,
     * solange Altbestand offen ist").
     */
    public function test_vorheriger_ort_switch_without_open_altbestand_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            list_context_files::execute('', true);
            $this->fail('Ohne offenen Altbestand haette der Schalter abgewiesen werden muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('altbestandclosed', $e->errorcode);
        }
    }

    /**
     * Mit offenem Altbestand listet der Schalter den vorherigen Ort, nicht
     * den aktuellen.
     */
    public function test_vorheriger_ort_switch_lists_the_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'aktuell.md', '# Aktuell');
        $this->create_context_file($user, '/altbestand/', 'alt.md', '# Alt');
        $this->write_pointer_with_vorheriger_ort($user, 'altbestand');

        $current = list_context_files::execute();
        $current = external_api::clean_returnvalue(list_context_files::execute_returns(), $current);
        $this->assertContains('aktuell.md', array_column($current['entries'], 'name'));

        $previous = list_context_files::execute('', true);
        $previous = external_api::clean_returnvalue(list_context_files::execute_returns(), $previous);
        $this->assertContains('alt.md', array_column($previous['entries'], 'name'));
        $this->assertNotContains('aktuell.md', array_column($previous['entries'], 'name'));
    }

    /**
     * Der gesperrte Eintrag erscheint sichtbar gesperrt in der Liste, nicht
     * weggelassen (#344, ADR 0011).
     */
    public function test_personal_data_marked_file_appears_locked_in_listing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'lerngruppe.md');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['locked']);
    }

    /**
     * Bei eingeschaltetem Schalter ist derselbe Eintrag entsperrt gelistet.
     */
    public function test_personal_data_marked_file_unlocked_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'lerngruppe.md');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['locked']);
    }

    /**
     * Unmarkierte Dateien sind in beiden Stellungen des Schalters
     * unveraendert entsperrt gelistet.
     */
    public function test_unmarked_file_never_locked(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'sachtext.md', '# Sachtext ohne Frontmatter');

        $resultoff = list_context_files::execute();
        $resultoff = external_api::clean_returnvalue(list_context_files::execute_returns(), $resultoff);
        $this->assertFalse($this->find_entry($resultoff['entries'], 'sachtext.md')['locked']);

        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $resulton = list_context_files::execute();
        $resulton = external_api::clean_returnvalue(list_context_files::execute_returns(), $resulton);
        $this->assertFalse($this->find_entry($resulton['entries'], 'sachtext.md')['locked']);
    }

    /**
     * Der Kontextpointer (Issue #445) ist keine Arbeitsdatei und taucht
     * deshalb nicht in der Auflistung auf, obwohl er physisch im selben
     * Ordner liegt wie ohne Pointer die Kontextdateien selbst.
     */
    public function test_pointer_file_is_excluded_from_listing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Vorlagen');
        $this->create_context_file(
            $user,
            '/coursepilot/',
            \local_coursepilot\storage_anchor::POINTER_FILENAME,
            '{"kontextbereich":"coursepilot","materialordner":"coursepilot-material"}'
        );

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('vorlagen.md', $names);
        $this->assertNotContains(\local_coursepilot\storage_anchor::POINTER_FILENAME, $names);
    }

    /**
     * Der externe Kontextbereich (Issue #490, Spec #486 §2/§6) listet ueber
     * den WebDAV-Client, statt ueber Moodles Dateispeicher - dieselbe
     * Werkzeugantwort wie im Moodle-Zweig.
     */
    public function test_lists_external_context_files_via_webdav(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $this->assertContains('vorlagen.md', array_column($result['entries'], 'name'));
    }

    /**
     * Konfliktschutz (Issue #513, Spec #486 §4/§6): Auflisten liefert extern
     * einen nicht leeren Pruefwert - ohne ihn koennte die KI beim Schreiben
     * nie ein "expected_contenthash" mitgeben, das den externen Speicher
     * tatsaechlich prueft.
     */
    public function test_external_listing_returns_a_nonempty_checkvalue(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'vorlagen.md');
        $this->assertNotNull($entry);
        $this->assertNotSame('', $entry['contenthash']);
    }

    /**
     * Ohne ETag (IServ) traegt der Pruefwert die Aenderungszeit - schwaecher,
     * aber ebenfalls nicht leer (Issue #513, Spec §4: "getlastmodified als
     * schwacher Ersatz").
     */
    public function test_external_listing_returns_a_nonempty_checkvalue_without_etag(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'vorlagen.md');
        $this->assertNotNull($entry);
        $this->assertNotSame('', $entry['contenthash']);
    }

    /**
     * Eine noch nicht angelegte externe Ebene ist leer, nie ein Fehler -
     * dieselbe Bedeutung wie eine fehlende Moodle-Wurzel.
     */
    public function test_listing_missing_external_directory_is_empty(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        // "/Coursepilot/Kontext" bleibt unangelegt.

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $this->assertSame([], $result['entries']);
    }

    /**
     * Die Personenbezug-Prüfung wirkt extern am Inhalt genauso wie in
     * Moodle (Spec §6) - ein markierter Eintrag erscheint gesperrt gelistet.
     */
    public function test_personal_data_marked_file_appears_locked_in_external_listing(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'lerngruppe.md');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['locked']);
    }

    /**
     * Weder Werkzeugname noch -antwort verraten den Speicherort (Spec §6/§15):
     * kein Server, kein Konto, keine Instanz-ID in der Antwort.
     */
    public function test_external_listing_response_reveals_no_storage_location(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);
        $encoded = json_encode($result);

        $this->assertStringNotContainsString($this->fixtureserver, $encoded);
        $this->assertStringNotContainsString($this->fixturekonto, $encoded);
    }

    /**
     * Markierungsgedaechtnis (Issue #493, Spec #486 §6): eine zweite
     * Auflistung ohne Aenderung holt die `.md`-Datei nicht erneut - genau ein
     * GET ueber beide Aufrufe hinweg.
     */
    public function test_second_listing_without_change_does_not_refetch_marked_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        list_context_files::execute();
        list_context_files::execute();

        $gets = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertCount(1, $gets);
    }

    /**
     * Eine geaenderte Datei (neuer Inhalt, damit neue Groesse/ETag) wird bei
     * der naechsten Auflistung neu gelesen - das Gedaechtnis erkennt den
     * veralteten Schluessel.
     */
    public function test_changed_file_is_refetched_on_next_listing(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $first = list_context_files::execute();
        $first = external_api::clean_returnvalue(list_context_files::execute_returns(), $first);
        $this->assertTrue($this->find_entry($first['entries'], 'lerngruppe.md')['locked']);

        // Handaenderung: die Markierung entfaellt, Groesse und ETag aendern sich.
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', '# Unmarkiert, neu geschrieben');

        $second = list_context_files::execute();
        $second = external_api::clean_returnvalue(list_context_files::execute_returns(), $second);
        $this->assertFalse($this->find_entry($second['entries'], 'lerngruppe.md')['locked']);

        $gets = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertCount(2, $gets);
    }

    /**
     * Bei eingeschaltetem #344-Schalter entfaellt die Pruefung ganz - kein
     * GET fuer die `.md`-Datei, weil "locked" ohnehin immer false ist.
     */
    public function test_switch_on_never_fetches_marked_file_content(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        list_context_files::execute();

        $gets = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertCount(0, $gets);
    }

    /**
     * @return string Kontextdatei-Inhalt mit Frontmatter-Markierung
     *         "coursepilot.personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n  weitergabe: nicht_weitergeben\n---\n# S. M., 7a";
    }

    /**
     * @param array $entries
     * @param string $name
     * @return array|null
     */
    private function find_entry(array $entries, string $name): ?array {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }
        return null;
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
