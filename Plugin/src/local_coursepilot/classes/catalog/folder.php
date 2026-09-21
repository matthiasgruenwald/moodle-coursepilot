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

/**
 * Feldkatalog fuer mod_folder (Spec 0015 §4.1, Spec 0018 §4/§7: Pseudofeld
 * "files" beim Anlegen als Liste von Materialordner-Pfaden, optional mit
 * Zielunterordner).
 *
 * Fallstricke aus dem Bestand (Ticket #380, Issue #434):
 * - "files" (Dateimanager-Draft-Itemid) ist ein Pseudofeld, vollstaendig
 *   katalogisiert: der Wert ist eine Liste von Materialordner-Pfaden (Spec
 *   0018 §4.2), je Eintrag entweder ein reiner Pfad-String (landet im
 *   Wurzelverzeichnis des Ordners) oder ein Objekt
 *   `{"pfad": "...", "zielordner": "..."}` fuer einen Zielunterordner
 *   ({@see \local_coursepilot\material_files::resolve_into_draft()}).
 * - Anders als bei resource ist ein LEERER Ordner gueltig
 *   (mod/folder/lib.php: `$draftitemid = $data->files;` wird nur bei
 *   Wahrheitswert verarbeitet) - "files" bleibt deshalb optional.
 * - display=1 (Inline) vertraegt sich nicht mit automatischer
 *   Abschluss-Verfolgung bei Ansicht - Moodle lehnt das im Formular ab.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class folder implements module_catalog {

    public static function modname(): string {
        return 'folder';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Anzeigename des Verzeichnisses.',
                true,
                null,
                null,
                null,
                'mod/folder/mod_form.php:38-41 (PARAM_TEXT bzw. PARAM_CLEANHTML je nach $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Beschreibungstext (Intro).',
                false,
                null,
                null,
                null,
                'mod/folder/db/install.xml (folder.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/folder/db/install.xml (folder.introformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Darstellung: eigene Seite (0) oder eingebettet auf der Kursseite (1, "Inline").',
                false,
                0,
                [0, 1],
                null,
                'mod/folder/lib.php:29,31 (FOLDER_DISPLAY_PAGE/FOLDER_DISPLAY_INLINE); Spalte '
                    . 'mod/folder/db/install.xml (folder.display)'
            ),
            new field(
                'showexpanded',
                'PARAM_BOOL',
                'Unterordner beim Oeffnen ein- (1) oder zusammengeklappt (0) anzeigen.',
                false,
                1,
                [0, 1],
                null,
                'mod/folder/db/install.xml (folder.showexpanded); Default get_config(\'folder\', \'showexpanded\')'
            ),
            new field(
                'showdownloadfolder',
                'PARAM_BOOL',
                'Schaltflaeche "Alles als ZIP herunterladen" anzeigen.',
                false,
                1,
                [0, 1],
                null,
                'mod/folder/db/install.xml (folder.showdownloadfolder)'
            ),
            new field(
                'forcedownload',
                'PARAM_BOOL',
                'Einzelne Dateien beim Anklicken herunterladen statt im Browser oeffnen.',
                false,
                1,
                [0, 1],
                null,
                'mod/folder/db/install.xml (folder.forcedownload)'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::for_modname(self::modname(), $instanceid, $cmid, $fullcontent);
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'files',
                'Liste von Materialordner-Pfaden (JSON-Array)',
                'Die im Ordner abzulegenden Dateien - je Eintrag ein Pfad in den Materialordner (Spec 0018 §4.2, '
                    . 'z.B. ["arbeitsblatt.pdf"]) oder ein Objekt {"pfad": "...", "zielordner": "unterordner"} fuer '
                    . 'ein Zielverzeichnis innerhalb des Ordners. Mehrere Eintraege in einem Aufruf moeglich. Ein '
                    . 'LEERER Ordner ist gueltig - anders als bei resource blockiert das Fehlen einer Datei das '
                    . 'Anlegen nicht. NUR beim Anlegen (create_module) nutzbar - ein spaeterer Patch ueber '
                    . 'update_module_settings scheitert bewusst (folderfilespatchunsupported), statt still '
                    . 'wirkungslos zu bleiben: fuer weitere Dateien einen weiteren folder anlegen.',
                false,
                null,
                null,
                null,
                'mod/folder/lib.php (folder_add_instance(): $data->files als Draft-Itemid; folder_update_instance() '
                    . 'liest stattdessen file_get_submitted_draft_itemid() aus $_REQUEST); '
                    . 'local_coursepilot\material_files::resolve_into_draft()'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'revision',
        ];
    }

    public static function combination_rules(): array {
        return [
            'display=1 (Inline) vertraegt sich nicht mit automatischer Abschluss-Verfolgung bei Ansicht '
                . '(completion=automatic + completionview) - Moodle lehnt das im Formular ab '
                . '(mod/folder/mod_form.php: validation()).',
        ];
    }

    public static function side_effects(): array {
        return [
            'folder ist auch ohne "files" anlegbar - ein leerer Ordner ist gueltig, anders als resource ohne '
                . 'Hauptdatei (Spec 0015 §4.3).',
        ];
    }

    public static function bundles(): array {
        return [];
    }

    public static function schreibweg(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        return ['FOLDER_DISPLAY_PAGE', 'FOLDER_DISPLAY_INLINE'];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
