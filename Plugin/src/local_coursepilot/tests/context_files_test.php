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

/**
 * Pfadaufloesung des Kontextbereichs (Issue #343). Sicherheitsrelevant:
 * jeder Pfad, der aus der Wurzel herausfuehren wuerde, muss abgewiesen
 * werden - Angriffstests, nicht nur Happy-Path.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(context_files::class)]
final class context_files_test extends \advanced_testcase {

    public function test_resolve_directory_defaults_to_root(): void {
        $this->resetAfterTest();
        $this->assertSame('/coursepilot/', context_files::resolve_directory(''));
    }

    public function test_resolve_directory_builds_subpath(): void {
        $this->resetAfterTest();
        $this->assertSame('/coursepilot/faecher/mathe/', context_files::resolve_directory('faecher/mathe'));
    }

    public function test_resolve_directory_rejects_dotdot_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        // Ein rohes '..'-Segment, wie es ankaeme, wenn PARAM_PATH es je nicht
        // schon vorher entfernt haette - die eigene Pruefung darf sich nicht
        // allein auf die Moodle-Parametersaeuberung verlassen.
        context_files::resolve_directory('faecher/../../../etc');
    }

    public function test_resolve_directory_rejects_single_dot_segment(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        context_files::resolve_directory('./faecher');
    }

    public function test_resolve_file_splits_directory_and_filename(): void {
        $this->resetAfterTest();
        [$directory, $filename] = context_files::resolve_file('vorlagen.md');
        $this->assertSame('/coursepilot/', $directory);
        $this->assertSame('vorlagen.md', $filename);

        [$directory, $filename] = context_files::resolve_file('faecher/mathe/notiz.md');
        $this->assertSame('/coursepilot/faecher/mathe/', $directory);
        $this->assertSame('notiz.md', $filename);
    }

    public function test_resolve_file_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        context_files::resolve_file('../secret.txt');
    }

    public function test_resolve_file_rejects_empty_path(): void {
        $this->resetAfterTest();
        $this->expectException(\moodle_exception::class);
        context_files::resolve_file('');
    }

    /**
     * Der Ablageort ist Moodles Private Files (Spec 0016 §1.2, Issue #407) -
     * damit die Lehrkraft ihre Arbeitsdateien in "Meine Dateien" ohne
     * Coursepilot-Endpunkt verwalten kann.
     */
    public function test_storage_anchor_is_private_files(): void {
        $this->assertSame('user', context_files::COMPONENT);
        $this->assertSame('private', context_files::FILEAREA);
        $this->assertSame(0, context_files::ITEMID);
    }

    /**
     * Die alte Filearea bleibt als Konstante erhalten - Altbestand und
     * Privacy-Provider adressieren sie weiterhin (Spec 0016 §3).
     */
    public function test_legacy_anchor_still_addressable(): void {
        $this->assertSame('local_coursepilot', context_files::LEGACY_COMPONENT);
        $this->assertSame('coursepilot_context', context_files::LEGACY_FILEAREA);
    }

    /**
     * Schreibendpunkte brauchen das Standard-Nutzerrecht auf die eigenen
     * Dateien (Spec 0016 §1.1) - ohne es bricht die Pruefung ab.
     */
    public function test_require_manage_own_files_passes_for_standard_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        context_files::require_manage_own_files();
        $this->expectNotToPerformAssertions();
    }

    /**
     * Ohne das Recht bricht die Pruefung ab - der Schreibpfad bleibt zu.
     */
    public function test_require_manage_own_files_rejects_user_without_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // CAP_PROHIBIT schlaegt jede andere Zuweisung - der sauberste Weg,
        // das Standardrecht in einem Test wegzunehmen.
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
        context_files::require_manage_own_files();
    }

    /**
     * Restplatz nach Nutzerquote (Spec 0016 §1.3): file_storage setzt
     * $CFG->userquota nicht selbst durch, Coursepilot muss es tun.
     */
    public function test_remaining_quota_reports_free_space(): void {
        global $CFG;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $CFG->userquota = 1000;

        $this->assertSame(1000, context_files::remaining_quota());

        get_file_storage()->create_file_from_string([
            'contextid' => context_files::own_context()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => 'gross.md',
        ], str_repeat('x', 400));

        $this->assertSame(600, context_files::remaining_quota());
    }

    /**
     * Ohne gesetzte Quote (0 = unbegrenzt) gibt es keinen Restplatz-Wert.
     */
    public function test_remaining_quota_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $this->assertNull(context_files::remaining_quota());
    }

    /**
     * Admins duerfen die Quote ignorieren (moodle/user:ignoreuserquota) -
     * dann gibt es keine Grenze zu melden.
     */
    public function test_remaining_quota_is_null_for_quota_ignorers(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->userquota = 1000;

        $this->assertNull(context_files::remaining_quota());
    }

    /**
     * Der Umzug (Spec 0016 §3.1) kopiert den Altbestand in die Private
     * Files - unter demselben relativen Pfad, damit er weiter erreichbar
     * bleibt.
     */
    public function test_migrate_legacy_files_copies_into_private_files(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = context_files::own_context()->id;
        $this->create_legacy_file($contextid, '/coursepilot/', 'vorlagen.md', '# Vorlagen');

        $this->assertSame(1, context_files::migrate_legacy_files());

        $copied = get_file_storage()->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'vorlagen.md'
        );
        $this->assertNotFalse($copied);
        $this->assertSame('# Vorlagen', $copied->get_content());
    }

    /**
     * Der Altbestand bleibt liegen - er ist der Rueckweg, und die Lehrkraft
     * entscheidet selbst, wann sie ihn raeumt (Spec 0016 §3.1).
     */
    public function test_migrate_legacy_files_keeps_the_original(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = context_files::own_context()->id;
        $this->create_legacy_file($contextid, '/coursepilot/', 'vorlagen.md', '# Vorlagen');

        context_files::migrate_legacy_files();

        $this->assertNotFalse(get_file_storage()->get_file(
            $contextid,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'vorlagen.md'
        ));
    }

    /**
     * Kollision = ueberspringen, nichts ueberschreiben (Spec 0016 §3.1).
     */
    public function test_migrate_legacy_files_skips_collisions(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = context_files::own_context()->id;
        $this->create_legacy_file($contextid, '/coursepilot/', 'vorlagen.md', '# Alt');
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => context_files::COMPONENT,
            'filearea' => context_files::FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => '/coursepilot/',
            'filename' => 'vorlagen.md',
        ], '# Neu');

        ob_start();
        $copied = context_files::migrate_legacy_files();
        $log = ob_get_clean();

        $this->assertSame(0, $copied);
        $this->assertStringContainsString('vorlagen.md', $log);
        $this->assertSame('# Neu', get_file_storage()->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'vorlagen.md'
        )->get_content());
    }

    /**
     * Altbestand ausserhalb des Wurzelordners bleibt liegen - er wuerde sonst
     * lose in der Wurzel von "Meine Dateien" landen, wo Coursepilot ihn ohnehin
     * nicht mehr sieht.
     */
    public function test_migrate_legacy_files_skips_files_outside_the_root(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = context_files::own_context()->id;
        $this->create_legacy_file($contextid, '/', 'streuner.md', '# Streuner');

        ob_start();
        $copied = context_files::migrate_legacy_files();
        $log = ob_get_clean();

        $this->assertSame(0, $copied);
        $this->assertStringContainsString('streuner.md', $log);
        $this->assertFalse(get_file_storage()->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            '/',
            'streuner.md'
        ));
    }

    /**
     * @param int $contextid
     * @param string $filepath
     * @param string $filename
     * @param string $content
     */
    private function create_legacy_file(int $contextid, string $filepath, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => context_files::LEGACY_COMPONENT,
            'filearea' => context_files::LEGACY_FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }

    /**
     * Die Zusage aus Spec 0016 §5.3, Teil eins: scheitert das Anlegen des
     * neuen Inhalts, bleibt die Zieldatei unangetastet. Das ist der Grund
     * fuer die Reihenfolge in replace() - andersherum waere der Bestand der
     * Lehrkraft fuer die Dauer eines Schrittes ungesichert, und eine
     * Transaktion wuerde ihn nicht retten: stored_file::delete() entfernt den
     * Blob physisch aus dem Dateipool, ein Rollback holt ihn nicht zurueck.
     *
     * Erzwungen ueber einen ungueltigen Ordnerpfad im Ziel - den weist
     * file_storage beim Anlegen zurueck, also vor dem Loeschen.
     */
    public function test_replace_keeps_target_when_creation_fails(): void {
        $this->resetAfterTest();
        $contextid = $this->context_with_journal('wichtiger Bestand');

        try {
            context_files::replace(
                $this->journal($contextid),
                context_files::filerecord($contextid, 'kein-absoluter-pfad', 'journal.md'),
                'neuer Inhalt'
            );
            $this->fail('Ein ungueltiger Ordnerpfad haette den Vorgang abbrechen muessen.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $survivor = $this->journal($contextid);
        $this->assertNotNull($survivor, 'Die Zieldatei wurde geloescht, obwohl das Anlegen fehlschlug.');
        $this->assertSame('wichtiger Bestand', $survivor->get_content());
    }

    /**
     * Teil zwei, das verbleibende Restrisiko: bricht es zwischen Loeschen und
     * Umbenennen ab, ist die Zieldatei weg - aber der vollstaendige neue
     * Inhalt liegt als Zwischendatei in "Meine Dateien". Unschoen und
     * sichtbar, nicht verloren. Genau diese Zusage macht der Docblock von
     * replace(), also wird sie auch geprueft.
     */
    public function test_replace_leaves_content_behind_when_rename_fails(): void {
        $this->resetAfterTest();
        $contextid = $this->context_with_journal('alter Bestand');

        try {
            context_files::replace(
                $this->journal($contextid),
                context_files::filerecord($contextid, '/coursepilot/', ''),
                'neuer Inhalt'
            );
            $this->fail('Ein leerer Dateiname haette den Vorgang abbrechen muessen.');
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $leftovers = array_filter(
            get_file_storage()->get_area_files(
                $contextid,
                context_files::COMPONENT,
                context_files::FILEAREA,
                context_files::ITEMID,
                'filename',
                false
            ),
            fn(\stored_file $file) => str_starts_with($file->get_filename(), context_files::TEMP_PREFIX)
        );

        $this->assertCount(1, $leftovers, 'Der neue Inhalt muesste als Zwischendatei liegengeblieben sein.');
        $this->assertSame('neuer Inhalt', reset($leftovers)->get_content());
    }

    /**
     * @param string $content
     * @return int Kontext-ID der frisch angemeldeten Lehrkraft.
     */
    private function context_with_journal(string $content): int {
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = context_files::own_context()->id;
        get_file_storage()->create_file_from_string(
            context_files::filerecord($contextid, '/coursepilot/', 'journal.md'),
            $content
        );
        return $contextid;
    }

    /**
     * @param int $contextid
     * @return \stored_file|null
     */
    private function journal(int $contextid): ?\stored_file {
        return get_file_storage()->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'journal.md'
        ) ?: null;
    }

    public function test_own_context_follows_current_user(): void {
        global $USER;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertEquals(\context_user::instance($user->id)->id, context_files::own_context()->id);
        $this->assertSame((int) $user->id, (int) $USER->id);
    }

    /**
     * Kein Toolname nennt einen Speicherort oder einen verbotenen
     * Namensbestandteil (Akzeptanzkriterium aus #343).
     */
    public function test_tool_names_reveal_no_storage_location(): void {
        $forbidden = ['private', 'pluginfile', 'nextcloud', 'webdav', 'moodlefile', 'filesystem'];
        $names = array_merge(
            array_keys(privacy_surface::allowed_tools()),
            array_values(privacy_surface::allowed_tools())
        );

        foreach ($names as $name) {
            foreach ($forbidden as $token) {
                $this->assertStringNotContainsStringIgnoringCase($token, $name, $name . ' nennt einen Speicherort.');
            }
        }
    }
}
