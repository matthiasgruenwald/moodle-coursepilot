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

namespace local_kurspilot;

/**
 * Der Zweitort-Beweis (Issue #444): ersetzt den frueheren Konstantenvergleich
 * zwischen {@see context_files} und {@see material_files} (der nur zeigte,
 * dass die beiden EINZIGEN heute existierenden Bereiche verschiedene Wurzeln
 * haben koennen). Hier tritt ein DRITTER, ausschliesslich in diesem Test
 * definierter {@see storage_area} gegen den gemeinsamen {@see storage_anchor}
 * an - ohne dass context_files, material_files oder irgendein Endpunkt
 * angefasst wird (die Fehlerschluessel des Testbereichs leihen sich zwar
 * vorhandene Sprachstrings von context_files, siehe {@see second_place()},
 * aber Einstellungsname/Standardwurzel/Namensregel sind frei erfunden). Das
 * belegt, dass der Anker wirklich bereichsunabhaengig ist, nicht nur, dass
 * zwei feste Bereiche sich nicht gegenseitig stoeren.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(storage_anchor::class)]
final class storage_anchor_test extends \advanced_testcase {

    /**
     * Ein frei erfundener zweiter Ablageort: eigene Einstellung, eigene
     * Standardwurzel, eigene Namensregel (nur `.txt`-Dateien statt `.md` oder
     * einer Endungs-Whitelist) - diese drei Groessen kommen aus keinem der
     * beiden bestehenden Bereiche. Die Fehlerschluessel leihen sich bewusst
     * die vorhandenen, generischen Sprachstrings von context_files (kein
     * Test-Bereich rechtfertigt eigene lang-Strings); der Beweis betrifft
     * die Bereichsunabhaengigkeit von storage_anchor, nicht die Wortwahl der
     * Fehlermeldung.
     *
     * @return storage_area
     */
    private function second_place(): storage_area {
        return new storage_area(
            rootsetting: 'zweitortroot',
            defaultroot: 'zweitort',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_-]+\.txt$/', $filename)) {
                    throw new \moodle_exception('contextfilenotmarkdown', 'local_kurspilot', '', $filename);
                }
            },
        );
    }

    public function test_second_place_resolves_its_own_default_root(): void {
        $this->resetAfterTest();
        $this->assertSame('/zweitort/', storage_anchor::resolve_directory($this->second_place(), ''));
    }

    public function test_second_place_root_is_configurable_independently(): void {
        $this->resetAfterTest();
        set_config('zweitortroot', 'anderswo', 'local_kurspilot');
        set_config('contextroot', 'unveraendert', 'local_kurspilot');

        $this->assertSame('/anderswo/', storage_anchor::resolve_directory($this->second_place(), ''));
        $this->assertSame('/unveraendert/', context_files::resolve_directory(''));
    }

    public function test_second_place_rejects_traversal_segments(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        storage_anchor::resolve_directory($this->second_place(), 'ordner/../../../etc');
    }

    public function test_second_place_resolves_file_in_both_directions(): void {
        $this->resetAfterTest();
        $area = $this->second_place();

        [$directory, $filename] = storage_anchor::resolve_file($area, 'faecher/mathe/notiz.txt');
        $this->assertSame('/zweitort/faecher/mathe/', $directory);
        $this->assertSame('notiz.txt', $filename);

        $this->assertSame(
            'faecher/mathe/notiz.txt',
            storage_anchor::relative_file($area, $directory, $filename)
        );
    }

    public function test_second_place_applies_its_own_writable_name_rule(): void {
        $this->resetAfterTest();
        $area = $this->second_place();

        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');
        $this->assertSame('/zweitort/', $directory);
        $this->assertSame('notiz.txt', $filename);

        try {
            storage_anchor::resolve_writable_file($area, 'notiz.md');
            $this->fail('Die .md-Regel des Kontextbereichs haette hier nicht gelten duerfen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('notiz.md', $e->getMessage());
        }
    }

    public function test_second_place_enforces_its_own_quota_error_key(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 100;

        $this->expectException(\moodle_exception::class);
        storage_anchor::require_quota($this->second_place(), 200);
    }

    public function test_second_place_writes_and_reads_back_via_replace(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = storage_anchor::own_context()->id;
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');

        storage_anchor::replace(
            null,
            storage_anchor::filerecord($contextid, $directory, $filename),
            'Inhalt am zweiten Ort'
        );

        $stored = get_file_storage()->get_file(
            $contextid,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            $directory,
            $filename
        );
        $this->assertNotFalse($stored);
        $this->assertSame('Inhalt am zweiten Ort', $stored->get_content());

        // Beweis, dass es tatsaechlich ein ANDERER Ort ist: der Kontextbereich
        // sieht die Datei unter demselben relativen Pfad nicht.
        $this->assertFalse(get_file_storage()->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            '/kurspilot/',
            'notiz.txt'
        ));
    }

    /**
     * Legt einen Kontextpointer im festen Anker ab (Issue #445) - derselbe
     * Ordner, in dem der Kontextbereich ohne Pointer ohnehin liegt.
     *
     * @param string $content Roher Dateiinhalt.
     */
    private function put_pointer(string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => storage_anchor::own_context()->id,
            'component' => storage_anchor::COMPONENT,
            'filearea' => storage_anchor::FILEAREA,
            'itemid' => storage_anchor::ITEMID,
            'filepath' => '/kurspilot/',
            'filename' => storage_anchor::POINTER_FILENAME,
        ], $content);
    }

    public function test_missing_pointer_leaves_default_roots_untouched(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame('/kurspilot/', context_files::resolve_directory(''));
        $this->assertSame('/kurspilot-material/', material_files::resolve_directory(''));
    }

    public function test_valid_pointer_redirects_both_areas_together(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => 'custom-context',
            'materialordner' => 'custom-material',
        ]));

        $this->assertSame('/custom-context/', context_files::resolve_directory(''));
        $this->assertSame('/custom-material/', material_files::resolve_directory(''));
    }

    public function test_unreadable_pointer_throws_named_error_without_fallback(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer('das ist kein JSON {');

        try {
            context_files::resolve_directory('');
            $this->fail('Ein unlesbarer Pointer haette werfen muessen, statt auf den Standard zurueckzufallen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(storage_anchor::POINTER_FILENAME, $e->getMessage());
        }
    }

    public function test_incomplete_pointer_throws_even_for_the_present_field(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        // Nur "kontextbereich" gesetzt - context_files braucht genau dieses
        // Feld, muss aber trotzdem scheitern: beide Felder ziehen gemeinsam
        // um, ein Pointer mit nur einem der beiden ist immer unvollstaendig.
        $this->put_pointer(json_encode(['kontextbereich' => 'custom-context']));

        try {
            context_files::resolve_directory('');
            $this->fail('Ein unvollstaendiger Pointer haette werfen muessen, statt auf den Standard zurueckzufallen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(storage_anchor::POINTER_FILENAME, $e->getMessage());
        }
    }

    /**
     * write_pointer() (Issue #446) legt eine Pointer-Datei an, die
     * resolve_pointer() (ueber context_files/material_files) unveraendert
     * zurueckliest - derselbe Mechanismus, den der Zustimmungsdialog nutzt.
     */
    public function test_write_pointer_is_readable_back_via_resolve_directory(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        storage_anchor::write_pointer('mein-kontext', 'mein-material');

        $this->assertSame('/mein-kontext/', context_files::resolve_directory(''));
        $this->assertSame('/mein-material/', material_files::resolve_directory(''));
    }

    /**
     * write_pointer() wendet dieselbe Segmentpruefung wie resolve_pointer()
     * an - ein Ortswechsel mit Traversal-Segment scheitert sofort beim
     * Schreiben, statt erst beim naechsten Lesen.
     */
    public function test_write_pointer_rejects_traversal_segment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        storage_anchor::write_pointer('../etc', 'mein-material');
    }

    /**
     * write_pointer() weist einen leeren Ordnernamen ab statt eine kaputte
     * Pointer-Datei anzulegen.
     */
    public function test_write_pointer_rejects_empty_value(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        storage_anchor::write_pointer('', 'mein-material');
    }

    /**
     * Backslashes zaehlen als Pfadtrenner - genau wie in {@see segments()}
     * fuer Client-Pfade. Ohne diese Normalisierung passierte "..\etc" die
     * Segmentpruefung als ein einziges, harmlos aussehendes Segment.
     */
    public function test_write_pointer_rejects_backslash_traversal_segment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        storage_anchor::write_pointer('..\\etc', 'mein-material');
    }

    /**
     * Dieselbe Normalisierung beim Lesen: ein von Hand mit Backslash
     * geschriebener Pointer faellt nicht still auf den Standard zurueck,
     * sondern wirft benannt wie jeder andere unerreichbare Ort.
     */
    public function test_pointer_with_backslash_traversal_throws_without_fallback(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => '..\\etc',
            'materialordner' => 'custom-material',
        ]));

        $this->expectException(\moodle_exception::class);
        context_files::resolve_directory('');
    }

    public function test_pointer_with_traversal_segment_throws_without_fallback(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => '../etc',
            'materialordner' => 'custom-material',
        ]));

        try {
            context_files::resolve_directory('');
            $this->fail('Ein unerreichbarer Pointer-Ort haette werfen muessen, statt auf den Standard zurueckzufallen.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(storage_anchor::POINTER_FILENAME, $e->getMessage());
        }
    }

    /**
     * Kontextpointer, zweite Fassung (Issue #490, Spec #486 §2): ein Pointer
     * der ersten Fassung (zwei Pfade) gilt vollstaendig als *in Moodle* -
     * kein Upgrade-Schritt schreibt ihn um, resolve_directory() liest ihn
     * unveraendert wie vor Issue #490.
     */
    public function test_legacy_pointer_still_resolves_as_moodle_location(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => 'custom-context',
            'materialordner' => 'custom-material',
        ]));

        $this->assertSame('/custom-context/', context_files::resolve_directory(''));
    }

    /**
     * Ein Pointer der zweiten Fassung mit Ziel *in Moodle* loest genauso auf
     * wie die erste Fassung - nur die Struktur ist neu.
     */
    public function test_v2_pointer_with_moodle_target_resolves_like_legacy(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'mein-kontext'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ]));

        $this->assertSame('/mein-kontext/', context_files::resolve_directory(''));
        $this->assertSame('/mein-material/', material_files::resolve_directory(''));
    }

    /**
     * Ein extern liegendes Ziel unterstuetzt {@see storage_anchor::resolve_directory()}
     * (und damit Schreiben/Materialbestand) in diesem Issue noch nicht - ein
     * benannter Fehler statt eines stillen Rueckfalls auf die Standardwurzel
     * (Spec §2: "nie ein stiller Rueckfall").
     */
    public function test_external_pointer_target_rejects_root_resolution_with_named_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => 3,
                'pfad' => 'Kontext',
                'pruefmerkmal' => ['server' => 's', 'basispfad' => 'b', 'konto' => 'k'],
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'mein-material'],
        ]));

        try {
            context_files::resolve_directory('');
            $this->fail('Ein externes Ziel haette hier werfen muessen, statt auf die Standardwurzel zurueckzufallen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pointerexternalnotsupported', $e->errorcode);
        }
    }

    /**
     * Die neuen Dateioperationen (Issue #487): list_entries()/read_content()/
     * write()/append() sind ortsneutral - kein stored_file verlaesst sie, nur
     * Werte. Der Zweitort-Beweis laeuft ueber denselben, nur im Test
     * definierten {@see second_place()}, wie die restlichen Zweitort-Tests
     * oben: die Operationen selbst nehmen gar keinen storage_area entgegen,
     * sie arbeiten auf einem bereits aufgeloesten Verzeichnis - das beweist
     * die Bereichsunabhaengigkeit staerker als eine gleichlautende Signatur.
     */
    public function test_list_entries_returns_location_neutral_file_and_folder_entries(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = storage_anchor::own_context()->id;
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');
        storage_anchor::replace(null, storage_anchor::filerecord($contextid, $directory, $filename), 'Inhalt');
        get_file_storage()->create_file_from_string(
            storage_anchor::filerecord($contextid, $directory . 'unterordner/', 'tief.txt'),
            'tief'
        );

        $entries = storage_anchor::list_entries($directory);

        $file = $this->find_entry($entries, 'notiz.txt');
        $this->assertNotNull($file);
        $this->assertSame('file', $file['type']);
        $this->assertSame(strlen('Inhalt'), $file['size']);
        $this->assertSame(sha1('Inhalt'), $file['contenthash']);
        $this->assertGreaterThan(0, $file['timemodified']);
        $this->assertArrayNotHasKey('locked', $file);

        $folder = $this->find_entry($entries, 'unterordner');
        $this->assertNotNull($folder);
        $this->assertSame('folder', $folder['type']);
        $this->assertSame('', $folder['contenthash']);
        $this->assertSame(0, $folder['timemodified']);
    }

    public function test_list_entries_excludes_the_context_pointer_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_pointer(json_encode([
            'kontextbereich' => 'kurspilot',
            'materialordner' => 'kurspilot-material',
        ]));

        $entries = storage_anchor::list_entries(context_files::resolve_directory(''));

        $this->assertNull($this->find_entry($entries, storage_anchor::POINTER_FILENAME));
    }

    public function test_read_content_returns_null_for_missing_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $area = $this->second_place();

        $this->assertNull(storage_anchor::read_content(
            storage_anchor::resolve_directory($area, ''),
            'nichtvorhanden.txt'
        ));
    }

    public function test_read_content_returns_no_stored_file_object(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = storage_anchor::own_context()->id;
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');
        storage_anchor::replace(null, storage_anchor::filerecord($contextid, $directory, $filename), 'Zweitort-Inhalt');

        $result = storage_anchor::read_content($directory, $filename);

        $this->assertSame('Zweitort-Inhalt', $result['content']);
        $this->assertSame(sha1('Zweitort-Inhalt'), $result['contenthash']);
        $this->assertSame(strlen('Zweitort-Inhalt'), $result['size']);
        $this->assertGreaterThan(0, $result['timemodified']);
        foreach ($result as $value) {
            $this->assertNotInstanceOf(\stored_file::class, $value);
        }
    }

    public function test_write_creates_new_file_at_the_second_place(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');

        storage_anchor::write($directory, $filename, 'frischer Inhalt');

        $this->assertSame('frischer Inhalt', storage_anchor::read_content($directory, $filename)['content']);
    }

    public function test_write_replaces_existing_content(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');
        storage_anchor::write($directory, $filename, 'alt');

        storage_anchor::write($directory, $filename, 'neu');

        $this->assertSame('neu', storage_anchor::read_content($directory, $filename)['content']);
    }

    public function test_append_creates_new_file_when_none_exists(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'journal.txt');

        $newsize = storage_anchor::append($directory, $filename, 'erster Eintrag');

        $this->assertSame('erster Eintrag', storage_anchor::read_content($directory, $filename)['content']);
        $this->assertSame(strlen('erster Eintrag'), $newsize);
    }

    /**
     * append() meldet die tatsaechlich geschriebene Gesamtgroesse, nicht eine
     * vom Aufrufer aus einem frueheren Lesen hochgerechnete - siehe Docblock
     * von {@see storage_anchor::append()}.
     */
    public function test_append_adds_to_existing_content(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'journal.txt');
        storage_anchor::write($directory, $filename, 'erster Eintrag');

        $newsize = storage_anchor::append($directory, $filename, ' zweiter Eintrag');

        $this->assertSame(strlen('erster Eintrag zweiter Eintrag'), $newsize);

        $this->assertSame(
            'erster Eintrag zweiter Eintrag',
            storage_anchor::read_content($directory, $filename)['content']
        );
    }

    /**
     * Rekursive Listung (Issue #488): ein Treffer in einem Unterordner traegt
     * seinen vollen Verzeichnispfad, keinen Ordnereintrag daneben (anders als
     * {@see storage_anchor::list_entries()}, die nur eine Ebene sieht).
     */
    public function test_list_entries_recursive_finds_nested_file(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = storage_anchor::own_context()->id;
        $directory = storage_anchor::resolve_directory($area, '');
        get_file_storage()->create_file_from_string(
            storage_anchor::filerecord($contextid, $directory . 'unterordner/', 'tief.txt'),
            'tiefer Inhalt'
        );

        $entries = storage_anchor::list_entries_recursive($directory);

        $this->assertCount(1, $entries);
        $this->assertSame('tief.txt', $entries[0]['name']);
        $this->assertSame($directory . 'unterordner/', $entries[0]['directory']);
        $this->assertSame(strlen('tiefer Inhalt'), $entries[0]['size']);
        $this->assertSame(sha1('tiefer Inhalt'), $entries[0]['contenthash']);
        $this->assertGreaterThan(0, $entries[0]['timecreated']);
    }

    public function test_list_entries_recursive_returns_empty_for_empty_directory(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], storage_anchor::list_entries_recursive(storage_anchor::resolve_directory($area, '')));
    }

    public function test_delete_removes_existing_file(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');
        storage_anchor::write($directory, $filename, 'Inhalt');

        $this->assertTrue(storage_anchor::delete($directory, $filename));
        $this->assertNull(storage_anchor::read_content($directory, $filename));
    }

    public function test_delete_returns_false_for_missing_file(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        $directory = storage_anchor::resolve_directory($area, '');

        $this->assertFalse(storage_anchor::delete($directory, 'nichtvorhanden.txt'));
    }

    /**
     * Zusatzfelder im Dateisatz (Issue #488, z.B. das `source`-Feld eines
     * Bildausschnitts) landen unveraendert auf der geschriebenen Datei, ohne
     * dass der Aufrufer selbst einen Dateisatz zusammenbaut.
     */
    public function test_write_applies_record_overrides(): void {
        $this->resetAfterTest();
        $area = $this->second_place();
        $this->setUser($this->getDataGenerator()->create_user());
        [$directory, $filename] = storage_anchor::resolve_writable_file($area, 'notiz.txt');

        storage_anchor::write($directory, $filename, 'Inhalt', ['source' => 'herkunft']);

        $stored = get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            $directory,
            $filename
        );
        $this->assertNotFalse($stored);
        $this->assertSame('herkunft', $stored->get_source());
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
}
