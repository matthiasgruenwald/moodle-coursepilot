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
 * Pfadaufloesung, Konstantensatz, Whitelists und Quotengrenzen des
 * Materialordners (Spec 0018 §2, §6, §8.1, Issue #428) - Geschwister zu
 * tests/context_files_test.php.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(material_files::class)]
final class material_files_test extends \advanced_testcase {

    public function test_resolve_directory_defaults_to_root(): void {
        $this->resetAfterTest();
        $this->assertSame('/kurspilot-material/', material_files::resolve_directory(''));
    }

    public function test_resolve_directory_builds_subpath(): void {
        $this->resetAfterTest();
        $this->assertSame('/kurspilot-material/faecher/mathe/', material_files::resolve_directory('faecher/mathe'));
    }

    public function test_resolve_directory_rejects_dotdot_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_directory('faecher/../../../etc');
    }

    public function test_resolve_directory_rejects_single_dot_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_directory('./faecher');
    }

    public function test_resolve_file_splits_directory_and_filename(): void {
        $this->resetAfterTest();
        [$directory, $filename] = material_files::resolve_file('screenshot.png');
        $this->assertSame('/kurspilot-material/', $directory);
        $this->assertSame('screenshot.png', $filename);

        [$directory, $filename] = material_files::resolve_file('faecher/mathe/blatt.pdf');
        $this->assertSame('/kurspilot-material/faecher/mathe/', $directory);
        $this->assertSame('blatt.pdf', $filename);
    }

    public function test_resolve_file_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_file('../secret.txt');
    }

    public function test_resolve_file_rejects_empty_path(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_file('');
    }

    /**
     * Der Ablageort ist derselbe wie der Kontextbereich (Moodles Private
     * Files, Spec 0018 §2.1) - aber ein eigener Konstantensatz, kein
     * Endpunkt bezieht sich auf COMPONENT/FILEAREA/ITEMID direkt.
     */
    public function test_storage_anchor_is_private_files(): void {
        $this->assertSame('user', material_files::COMPONENT);
        $this->assertSame('private', material_files::FILEAREA);
        $this->assertSame(0, material_files::ITEMID);
    }

    /**
     * Der Ortswechsel-/Zweitort-Beweis (Issue #444: "ein
     * zweiter, im Test definierter Ablageort landet bei denselben Aufrufen")
     * steht seit der Zusammenfuehrung auf den gemeinsamen storage_anchor in
     * tests/storage_anchor_test.php - dort mit einem dritten, nur im Test
     * erfundenen Bereich statt eines reinen Konstantenvergleichs zwischen
     * context_files und material_files.
     */
    public function test_root_defaults_to_kurspilot_material(): void {
        $this->resetAfterTest();
        $this->assertSame('kurspilot-material', get_config('local_kurspilot', 'materialroot') ?: 'kurspilot-material');
    }

    /**
     * Beide Bestandslisten aus Spec 0018 §6 - SVG bleibt in beiden.
     */
    public function test_allowed_extensions_cover_both_whitelists(): void {
        $extensions = material_files::allowed_extensions();
        foreach (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'html', 'png', 'jpg'] as $general) {
            $this->assertContains($general, $extensions, $general . ' fehlt aus der allgemeinen Whitelist.');
        }
        foreach (['png', 'jpg', 'gif', 'svg', 'webp'] as $embeddable) {
            $this->assertContains($embeddable, $extensions, $embeddable . ' fehlt aus der Bild-Whitelist.');
        }
    }

    public function test_is_allowed_extension_accepts_svg(): void {
        $this->assertTrue(material_files::is_allowed_extension('diagramm.svg'));
    }

    public function test_is_allowed_extension_rejects_unknown_type(): void {
        $this->assertFalse(material_files::is_allowed_extension('programm.exe'));
    }

    public function test_resolve_writable_file_accepts_allowed_extension(): void {
        $this->resetAfterTest();
        [$directory, $filename] = material_files::resolve_writable_file('screenshot.png');
        $this->assertSame('/kurspilot-material/', $directory);
        $this->assertSame('screenshot.png', $filename);
    }

    public function test_resolve_writable_file_rejects_disallowed_extension(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_writable_file('programm.exe');
    }

    public function test_resolve_writable_file_rejects_bad_folder_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        material_files::resolve_writable_file('mit leerzeichen/screenshot.png');
    }

    /**
     * Restplatz nach Nutzerquote - dieselbe, root-unabhaengige Rechnung wie
     * context_files::remaining_quota().
     */
    public function test_remaining_quota_reports_free_space(): void {
        global $CFG;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $CFG->userquota = 1000;

        $this->assertSame(1000, material_files::remaining_quota());

        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot-material/',
            'filename' => 'gross.pdf',
        ], str_repeat('x', 400));

        $this->assertSame(600, material_files::remaining_quota());
    }

    public function test_remaining_quota_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $this->assertNull(material_files::remaining_quota());
    }

    /**
     * Harter Fehler bei voller/gesprengter Quote (Spec 0018 §8.1).
     */
    public function test_require_quota_throws_when_exceeded(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $this->expectException(\moodle_exception::class);
        material_files::require_quota(1001);
    }

    public function test_require_quota_passes_when_within_limit(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        material_files::require_quota(1000);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Volle Quote (Restplatz 0) ist der Sonderfall von "ueberschritten" -
     * jeder positive Zuwachs scheitert (Spec 0018 §8.1).
     */
    public function test_require_quota_throws_when_quota_is_full(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 500;
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot-material/',
            'filename' => 'voll.pdf',
        ], str_repeat('x', 500));

        $this->expectException(\moodle_exception::class);
        material_files::require_quota(1);
    }

    /**
     * Warnung unter 10% Restplatz, mit Restplatz in MB (Spec 0018 §8.1, Form
     * wie Spec 0016 §5.4).
     */
    public function test_quota_warning_below_ten_percent(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        // 950 von 1000 Byte belegt nach dem Schreiben (5% Restplatz).
        $warning = material_files::quota_warning(950);
        $this->assertNotNull($warning);
        $this->assertStringContainsString('0.0', $warning);
    }

    public function test_quota_warning_is_null_with_enough_room(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $this->assertNull(material_files::quota_warning(100));
    }

    public function test_quota_warning_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $this->assertNull(material_files::quota_warning(100));
    }

    public function test_require_manage_own_files_passes_for_standard_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        material_files::require_manage_own_files();
        $this->expectNotToPerformAssertions();
    }

    public function test_require_manage_own_files_rejects_user_without_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $user->id, \context_system::instance()->id);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_system::instance()->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        material_files::require_manage_own_files();
    }

    public function test_own_context_follows_current_user(): void {
        global $USER;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertEquals(\context_user::instance($user->id)->id, material_files::own_context()->id);
        $this->assertSame((int) $user->id, (int) $USER->id);
    }

    /**
     * replace() ist bei context_files bereits vollstaendig geprueft
     * (Rettungsreihenfolge, Zwischendatei) - hier nur der glueckliche Weg,
     * damit die Delegation belegt ist.
     */
    public function test_replace_creates_new_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = material_files::own_context()->id;

        material_files::replace(
            null,
            material_files::filerecord($contextid, '/kurspilot-material/', 'neu.pdf'),
            'Inhalt'
        );

        $stored = get_file_storage()->get_file(
            $contextid,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/kurspilot-material/',
            'neu.pdf'
        );
        $this->assertNotFalse($stored);
        $this->assertSame('Inhalt', $stored->get_content());
    }

    /**
     * Der Verweisweg (Spec 0018 §4.2, Issue #429): eine liegende
     * Materialdatei landet im Dateimanager-Entwurf, der 1:1 als
     * *_update_instance()-Feldwert weiterverwendet wird.
     */
    public function test_resolve_into_draft_copies_material_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );
        $targetcontextid = \context_system::instance()->id;

        $draftitemid = material_files::resolve_into_draft(
            $targetcontextid,
            'mod_assign',
            'introattachment',
            0,
            ['blatt.pdf']
        );

        $draftfile = get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'blatt.pdf'
        );
        $this->assertNotFalse($draftfile);
        $this->assertSame('Arbeitsblattinhalt', $draftfile->get_content());
    }

    /**
     * Bereits vorhandene Anhaenge am Ziel bleiben erhalten - ein Aufruf
     * haengt an, ersetzt nicht (Spec 0018 §4.2).
     */
    public function test_resolve_into_draft_preserves_existing_target_files(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'neu.pdf'),
            'neuer Inhalt'
        );
        $targetcontextid = \context_system::instance()->id;
        get_file_storage()->create_file_from_string([
            'contextid' => $targetcontextid,
            'component' => 'mod_assign',
            'filearea' => 'introattachment',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'schon-da.pdf',
        ], 'alter Inhalt');

        $draftitemid = material_files::resolve_into_draft(
            $targetcontextid,
            'mod_assign',
            'introattachment',
            0,
            ['neu.pdf']
        );

        $fs = get_file_storage();
        $usercontextid = material_files::own_context()->id;
        $this->assertNotFalse($fs->get_file($usercontextid, 'user', 'draft', $draftitemid, '/', 'schon-da.pdf'));
        $this->assertNotFalse($fs->get_file($usercontextid, 'user', 'draft', $draftitemid, '/', 'neu.pdf'));
    }

    /**
     * Ein Verweis auf eine nicht existierende Materialdatei scheitert mit
     * einer Meldung, die den erwarteten Pfad nennt (Abnahmekriterium #429).
     */
    public function test_resolve_into_draft_throws_when_material_file_missing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            material_files::resolve_into_draft(
                \context_system::instance()->id,
                'mod_assign',
                'introattachment',
                0,
                ['fehlt.pdf']
            );
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('fehlt.pdf', $e->getMessage());
        }
    }

    /**
     * Der Verweisweg liest nur - die Materialdatei bleibt nach dem Aufruf
     * unveraendert liegen (Spec 0018 §4.2: "kein Verlust im Fehlerfall").
     */
    public function test_resolve_into_draft_leaves_material_file_untouched(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        material_files::resolve_into_draft(\context_system::instance()->id, 'mod_assign', 'introattachment', 0, ['blatt.pdf']);

        $stillthere = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/kurspilot-material/',
            'blatt.pdf'
        );
        $this->assertNotFalse($stillthere);
        $this->assertSame('Arbeitsblattinhalt', $stillthere->get_content());
    }

    /**
     * Ein Objekt-Eintrag mit "zielordner" legt die Datei im Draft in einen
     * Unterordner statt der Wurzel - "Zielverzeichnis innerhalb des Ordners
     * waehlbar" (Issue #434).
     */
    public function test_resolve_into_draft_places_file_in_target_subfolder(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            [['pfad' => 'blatt.pdf', 'zielordner' => 'unterordner']]
        );

        $draftfile = get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/unterordner/',
            'blatt.pdf'
        );
        $this->assertNotFalse($draftfile);
        $this->assertSame('Arbeitsblattinhalt', $draftfile->get_content());
    }

    /**
     * Ein String-Eintrag bleibt unveraendert im Wurzelverzeichnis - reine
     * Pfadlisten (bestehende Aufrufer) muessen sich nicht aendern.
     */
    public function test_resolve_into_draft_defaults_string_entries_to_root(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            ['blatt.pdf']
        );

        $draftfile = get_file_storage()->get_file(
            material_files::own_context()->id,
            'user',
            'draft',
            $draftitemid,
            '/',
            'blatt.pdf'
        );
        $this->assertNotFalse($draftfile);
    }

    /**
     * Ein Traversal-Versuch ueber "zielordner" (z.B. "..") scheitert wie ein
     * gewoehnlicher Materialordner-Pfad (Issue #434: "gleiche Zeichenregeln
     * wie beim Materialordner selbst").
     */
    public function test_resolve_into_draft_rejects_target_folder_traversal(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );

        $this->expectException(\moodle_exception::class);
        material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            [['pfad' => 'blatt.pdf', 'zielordner' => '../ausserhalb']]
        );
    }

    /**
     * Ein Objekt-Eintrag ohne "pfad" scheitert mit einer klaren Meldung
     * statt eines PHP-Fehlers.
     */
    public function test_resolve_into_draft_rejects_entry_without_pfad(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_folder',
            'content',
            0,
            [['zielordner' => 'unterordner']]
        );
    }

    /**
     * Der Entwurf belastet die Nutzerquote nicht, stattdessen gilt
     * $CFG->maxbytes je Datei (Spec #486 §7, Issue #496) - eine zu grosse
     * Quelldatei scheitert VOR dem Kopieren, egal wie viel Quote noch frei
     * waere.
     */
    public function test_resolve_into_draft_rejects_file_larger_than_cfg_maxbytes(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'gross.pdf'),
            'zwoelf Byte!'
        );
        $CFG->maxbytes = 5;

        try {
            material_files::resolve_into_draft(
                \context_system::instance()->id,
                'mod_assign',
                'introattachment',
                0,
                ['gross.pdf']
            );
            $this->fail('Erwartete moodle_exception wegen $CFG->maxbytes blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialembedtoolarge', $e->errorcode);
        }
    }

    /**
     * Die $CFG->maxbytes-Grenze gilt nur fuer "bestand" (Spec #486 §7 spricht
     * nur vom "Entwurf" der Bestand-Einbettung) - eine Werkbank-Datei blieb
     * schon vor Issue #496 nur an der Servergrenze beim Hochladen
     * (upload_material_file, get_max_upload_file_size()) begrenzt. Eine
     * zusaetzliche $CFG->maxbytes-Pruefung hier wuerde eine bereits liegende,
     * groessere Werkbank-Datei nachtraeglich am Einbetten hindern - kein
     * Ziel dieses Tickets (Review-Fund).
     */
    public function test_resolve_into_draft_ignores_cfg_maxbytes_for_werkbank_source(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'gross.pdf'),
            'zwoelf Byte!'
        );
        $CFG->maxbytes = 5;

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_assign',
            'introattachment',
            0,
            ['gross.pdf'],
            material_files::ORT_WERKBANK
        );

        $this->assertNotFalse(get_file_storage()->get_file(
            material_files::own_context()->id, 'user', 'draft', $draftitemid, '/', 'gross.pdf'));
    }

    /**
     * $CFG->maxbytes <= 0 bedeutet "keine eigene Grenze" (Moodle-Konvention).
     */
    public function test_resolve_into_draft_allows_any_size_when_cfg_maxbytes_is_zero(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/kurspilot-material/', 'blatt.pdf'),
            'Arbeitsblattinhalt'
        );
        $CFG->maxbytes = 0;

        $draftitemid = material_files::resolve_into_draft(
            \context_system::instance()->id,
            'mod_assign',
            'introattachment',
            0,
            ['blatt.pdf']
        );

        $this->assertNotFalse(get_file_storage()->get_file(
            material_files::own_context()->id, 'user', 'draft', $draftitemid, '/', 'blatt.pdf'));
    }

    /**
     * Issue #508: eine Quelle fuer den Webservice-Parameter "ort" - Typ,
     * Default und Beschreibung kommen aus derselben Konstante wie das
     * tools/list-Schema.
     */
    public function test_ort_parameter_uses_shared_default_and_description(): void {
        $param = material_files::ort_parameter();

        $this->assertSame(PARAM_ALPHA, $param->type);
        $this->assertSame(material_files::ORT_BESTAND, $param->default);
        $this->assertSame(material_files::ORT_DESCRIPTION, $param->desc);
        $this->assertSame(VALUE_DEFAULT, $param->required);
    }

    /**
     * Issue #508: dieselbe Beschreibung, derselbe Wertebereich - fuer die
     * KI-Werkzeugliste (tool_registry-Schemas) wie fuer den Webservice.
     */
    public function test_ort_schema_matches_shared_description_and_values(): void {
        $schema = material_files::ort_schema();

        $this->assertSame('string', $schema['type']);
        $this->assertSame([material_files::ORT_BESTAND, material_files::ORT_WERKBANK], $schema['enum']);
        $this->assertSame(material_files::ORT_DESCRIPTION, $schema['description']);
    }
}
