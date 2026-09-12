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
 * Anker des Kontextbereichs (Karte #297, Issue #343, Umzug #407): Moodles
 * Private Files der aufrufenden Lehrkraft - `component=user`,
 * `filearea=private`, `itemid=0`, `contextid=context_user::instance($USER->id)`,
 * eingegrenzt auf den Unterordner aus {@see area()} (Default `kurspilot`).
 *
 * Die Isolation kommt nicht aus einer Pfadpruefung, sondern daraus, dass
 * component/filearea/itemid/contextid **nie** aus Client-Eingaben stammen -
 * es gibt schlicht keinen Parameter, ueber den sich ein anderer Bereich
 * adressieren liesse ("harte Grenze im Plugincode"). Seit dem Umzug auf
 * `user/private` (Spec 0016 §1) traegt zusaetzlich der fixierte Wurzelordner:
 * kein anderer Unterordner der Private Files ist erreichbar. Die Pfadpruefung
 * in {@see resolve_directory()}/{@see resolve_file()} ist die Verteidigung
 * gegen `../`-Segmente, die aus diesem Ordner herausfuehren wuerden.
 *
 * Diese Klasse ist seit Issue #444 eine duenne Bereichsdefinition ueber dem
 * gemeinsamen {@see storage_anchor}: sie erklaert nur noch, was den
 * Kontextbereich von {@see material_files} unterscheidet (Wurzel-
 * Einstellungsname, Standardwurzel, Namensregel beim Schreiben,
 * Fehlerschluessel), und reicht den Rest unveraendert durch. Ihre
 * oeffentliche Schnittstelle - Konstanten, Methodennamen, Signaturen,
 * geworfene Fehlerschluessel - bleibt fuer ihre rund 20 Aufrufer identisch.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class context_files {

    /** @var string Moodle-Dateikomponente - Moodles Private Files (Spec 0016 §1.2). */
    public const COMPONENT = storage_anchor::COMPONENT;

    /** @var string Alleiniger, fuer die KI erreichbarer Dateibereich. */
    public const FILEAREA = storage_anchor::FILEAREA;

    /** @var int Fester Item-Bezug - der Bereich kennt keine weiteren Items. */
    public const ITEMID = storage_anchor::ITEMID;

    /** @var int Harte Groessengrenze je Schreibvorgang (Spec 0016 §5.2). */
    public const MAX_WRITE_BYTES = 1024 * 1024;

    /** @var string Namensvorsatz der Zwischendatei in {@see replace()}. */
    public const TEMP_PREFIX = storage_anchor::TEMP_PREFIX;

    /** @var string Komponente des Altbestands vor dem Umzug (#407). */
    public const LEGACY_COMPONENT = 'local_kurspilot';

    /** @var string Dateibereich des Altbestands vor dem Umzug (#407). */
    public const LEGACY_FILEAREA = 'kurspilot_context';

    /**
     * Die Bereichsdefinition des Kontextbereichs (Issue #444): Wurzel-
     * Einstellungsname, Standardwurzel, Fehlerschluessel und die eine echte
     * Policy-Methode dieses Bereichs - die `.md`-Namensregel beim Schreiben
     * (Spec 0016 §5.1).
     *
     * Wurzel-Einstellungsname und Standardwurzel sind seit Issue #445
     * identisch mit {@see storage_anchor::ANCHOR_ROOTSETTING}/
     * {@see storage_anchor::ANCHOR_DEFAULT_ROOT}: der Kontextbereich-Ordner
     * *ist* der feste Anker, in dem ein Kontextpointer gesucht wird, nicht
     * bloss zufaellig gleich benannt.
     *
     * @return storage_area
     */
    public static function area(): storage_area {
        return new storage_area(
            rootsetting: storage_anchor::ANCHOR_ROOTSETTING,
            defaultroot: storage_anchor::ANCHOR_DEFAULT_ROOT,
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_-]+\.md$/', $filename)) {
                    throw new \moodle_exception('contextfilenotmarkdown', 'local_kurspilot', '', $filename);
                }
            },
            pointerkey: 'kontextbereich',
        );
    }

    /**
     * Der eigene Nutzerkontext der angemeldeten Person - niemals aus
     * Client-Eingaben ableitbar.
     *
     * @return \context_user
     */
    public static function own_context(): \context_user {
        return storage_anchor::own_context();
    }

    /**
     * Loest einen optionalen Client-Unterordner zu einem vollstaendigen
     * Moodle-Dateipfad innerhalb des Kontextbereichs auf.
     *
     * @param string $path Relativer Unterordner, z.B. "" oder "faecher/mathe".
     * @return string Immer mit fuehrendem und abschliessendem "/".
     */
    public static function resolve_directory(string $path): string {
        return storage_anchor::resolve_directory(self::area(), $path);
    }

    /**
     * Der Client-Pfad zu einem aufgeloesten Verzeichnis - relativ zur
     * Wurzel, also in derselben Schreibweise, die jedes Werkzeug auch
     * entgegennimmt. Die Wurzel selbst ist der leere Pfad.
     *
     * Wird eine Antwort stattdessen mit dem Wurzelordner darin ausgeliefert
     * ("kurspilot"), bildet ein Client daraus Unterpfade wie
     * "kurspilot/fragetypen/match.md" und landet in /kurspilot/kurspilot/...
     * (#425 F1). Eingabe und Ausgabe muessen dasselbe Koordinatensystem
     * benutzen.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}
     * @return string
     */
    public static function relative_directory(string $directory): string {
        return storage_anchor::relative_directory(self::area(), $directory);
    }

    /**
     * Der Client-Pfad einer Datei - wie {@see relative_directory()}, nur mit
     * Dateinamen. Eine Datei an der Wurzel ist schlicht ihr Dateiname.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}
     * @param string $filename
     * @return string
     */
    public static function relative_file(string $directory, string $filename): string {
        return storage_anchor::relative_file(self::area(), $directory, $filename);
    }

    /**
     * Loest einen Client-Dateipfad (Ordner + Dateiname) auf.
     *
     * @param string $path z.B. "vorlagen.md" oder "faecher/mathe/notiz.md".
     * @return array{0: string, 1: string} [Ordnerpfad, Dateiname]
     */
    public static function resolve_file(string $path): array {
        return storage_anchor::resolve_file(self::area(), $path);
    }

    /**
     * Wie {@see resolve_file()}, aber mit den engeren Schreibregeln aus
     * Spec 0016 §5.1: Ordnersegmente nur aus `[A-Za-z0-9_-]`, Dateiname
     * derselbe Zeichenvorrat mit der Endung `.md`. Lesen bleibt bewusst
     * grosszuegiger - der Altbestand und von Hand angelegte Dateien sollen
     * lesbar bleiben, auch wenn Kurspilot sie so nie geschrieben haette.
     *
     * @param string $path z.B. "plan.md" oder "faecher/mathe/profil.md".
     * @return array{0: string, 1: string} [Ordnerpfad, Dateiname]
     * @throws \moodle_exception invalidcontextpath / contextfilenotmarkdown
     */
    public static function resolve_writable_file(string $path): array {
        return storage_anchor::resolve_writable_file(self::area(), $path);
    }

    /**
     * Listet eine Ebene des Kontextbereichs - ortsneutral (Issue #487).
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @return array<int, array{name: string, type: string, size: int, mimetype: string,
     *         contenthash: string, timemodified: int}>
     */
    public static function list_entries(string $directory): array {
        return storage_anchor::list_entries($directory);
    }

    /**
     * Listet eine Ebene des Kontextbereichs zeigerbewusst (Issue #490, Spec
     * #486 §2/§6) - folgt dem Kontextpointer nach Moodle oder extern. Anders
     * als {@see list_entries()} nimmt diese Methode den noch unaufgeloesten
     * Client-Pfad entgegen, weil erst die Pointer-Aufloesung entscheidet, ob
     * ueberhaupt ein Moodle-Verzeichnis existiert.
     *
     * @param string $path
     * @return array{directory: string, entries: array}
     */
    public static function list_entries_pointer_aware(string $path): array {
        return pointer_reader::list_entries(self::area(), $path);
    }

    /**
     * Listet eine Ebene des vorherigen Ortes (Issue #498, Spec #486 §6/§9) -
     * der Nur-Lese-Schalter fuer den Altbestand: derselbe Lesezweig wie
     * {@see list_entries_pointer_aware()}, nur mit einem anderen, vom
     * Aufrufer bereits aufgeloesten Ort statt der aktiven Pointer-Aufloesung.
     *
     * @param string $path
     * @param pointer_location $location Aus {@see \local_kurspilot\altbestand::require_open_location()}.
     * @return array{directory: string, entries: array}
     */
    public static function list_entries_previous_location(string $path, pointer_location $location): array {
        return pointer_reader::list_entries(self::area(), $path, $location);
    }

    /**
     * Liest den Inhalt einer Kontextdatei - ortsneutral (Issue #487).
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @return array{content: string, mimetype: string, size: int, contenthash: string,
     *         timemodified: int}|null null, wenn die Datei fehlt oder ein Ordner ist.
     */
    public static function read_content(string $directory, string $filename): ?array {
        return storage_anchor::read_content($directory, $filename);
    }

    /**
     * Liest eine Kontextdatei zeigerbewusst (Issue #490, Spec #486 §2/§6) -
     * siehe {@see list_entries_pointer_aware()}.
     *
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read_content_pointer_aware(string $path): ?array {
        return pointer_reader::read_content(self::area(), $path);
    }

    /**
     * Liest eine Datei des vorherigen Ortes (Issue #498, Spec #486 §6/§9) -
     * siehe {@see list_entries_previous_location()}.
     *
     * @param string $path
     * @param pointer_location $location Aus {@see \local_kurspilot\altbestand::require_open_location()}.
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read_content_previous_location(string $path, pointer_location $location): ?array {
        return pointer_reader::read_content(self::area(), $path, $location);
    }

    /**
     * Der aufgeloeste Pointer-Zustand des Kontextbereichs (Issue #491) - fuer
     * die Schreibendpunkte, die vor jedem Schreibvorgang wissen muessen, ob
     * der Moodle- oder der externe Zweig gilt (unterschiedliche Policy:
     * Nutzerrecht, Quote). `null` heisst *offen* - dieselbe Bedeutung wie bei
     * {@see \local_kurspilot\storage_anchor::resolve_pointer_location()}.
     *
     * @return pointer_location|null
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable
     */
    public static function resolve_pointer_location(): ?pointer_location {
        return storage_anchor::resolve_pointer_location(self::area());
    }

    /**
     * Legt eine externe Kontextdatei an oder ueberschreibt sie bedingt
     * (Issue #491, Spec #486 §4/§6) - siehe {@see pointer_writer::write()}.
     * Nur fuer einen bereits als *extern* erkannten Pointer-Zustand
     * ({@see resolve_pointer_location()}).
     *
     * @param string $path
     * @param string $content
     * @param bool $createonly Nur anlegen, nie ueberschreiben (Issue #498,
     *        Spec #486 §9: Kopieren aus dem Altbestand).
     * @return array{path: string, created: bool, size: int, oldsize: int}
     */
    public static function write_pointer_aware(string $path, string $content, bool $createonly = false): array {
        return pointer_writer::write(self::area(), $path, $content, $createonly);
    }

    /**
     * Haengt an eine externe Kontextdatei an (Issue #491, Spec #486 §4/§6) -
     * siehe {@see pointer_writer::append()}.
     *
     * @param string $path
     * @param string $content
     * @return array{path: string, created: bool, size: int}
     */
    public static function append_pointer_aware(string $path, string $content): array {
        return pointer_writer::append(self::area(), $path, $content);
    }

    /**
     * Legt eine Kontextdatei an oder ersetzt ihren Inhalt vollstaendig -
     * ortsneutral (Issue #487).
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @param string $content Vollstaendiger neuer Inhalt.
     */
    public static function write(string $directory, string $filename, string $content): void {
        storage_anchor::write($directory, $filename, $content);
    }

    /**
     * Haengt Inhalt an eine Kontextdatei an - ortsneutral (Issue #487).
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @param string $content Anzuhaengender Inhalt.
     * @return int Gesamtgroesse der Datei nach dem Anhaengen, in Byte.
     */
    public static function append(string $directory, string $filename, string $content): int {
        return storage_anchor::append($directory, $filename, $content);
    }

    /**
     * Harte Groessengrenze je Schreibvorgang (Spec 0016 §5.2) - gilt fuer das
     * jeweils uebertragene Stueck (voller Inhalt bei write, nur das
     * Anhaengsel bei append), nicht fuer die Zieldatei. Bis Issue #506 in
     * `write_context_file`/`append_context_file` wortgleich dupliziert.
     *
     * @param string $content
     * @throws \moodle_exception contextfiletoolarge
     */
    public static function require_size_within_limit(string $content): void {
        $size = strlen($content);
        if ($size > self::MAX_WRITE_BYTES) {
            throw new \moodle_exception('contextfiletoolarge', 'local_kurspilot', '', (object) [
                'size' => $size,
                'max' => self::MAX_WRITE_BYTES,
            ]);
        }
    }

    /**
     * Standard-Nutzerrecht auf die eigenen Dateien (Spec 0016 §1.1) - fuer
     * die Schreibendpunkte aus Phase 2. Seit dem Umzug auf `user/private`
     * schreibt Kurspilot in denselben Bereich wie "Meine Dateien", also gilt
     * dieselbe Freigabe.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        storage_anchor::require_manage_own_files();
    }

    /**
     * Restplatz in Byte nach Nutzerquote (Spec 0016 §1.3) - `file_storage`
     * setzt `$CFG->userquota` nicht selbst durch, nur die Core-UI tut das.
     * Kurspilot schriebe sonst als einziger an der Schulgrenze vorbei.
     *
     * @return int|null Restplatz in Byte, oder null wenn keine Grenze gilt
     *         (Quote aus, unbegrenzt, oder `moodle/user:ignoreuserquota`).
     */
    public static function remaining_quota(): ?int {
        return storage_anchor::remaining_quota();
    }

    /**
     * Weist einen Schreibvorgang ab, der die Nutzerquote sprengen wuerde.
     *
     * @param int $additionalbytes Zuwachs gegenueber dem bisherigen Stand.
     * @throws \moodle_exception contextquotaexceeded
     */
    public static function require_quota(int $additionalbytes): void {
        storage_anchor::require_quota(self::area(), $additionalbytes);
    }

    /**
     * Moodle-Dateisatz fuer eine Datei im Kontextbereich.
     *
     * @param int $contextid
     * @param string $directory
     * @param string $filename
     * @return array
     */
    public static function filerecord(int $contextid, string $directory, string $filename): array {
        return storage_anchor::filerecord($contextid, $directory, $filename);
    }

    /**
     * Setzt den Inhalt einer Kontextdatei neu - der eine Schreibvorgang, den
     * sich alle Schreibendpunkte teilen. Siehe {@see storage_anchor::replace()}
     * fuer die vollstaendige Begruendung der Zwischendatei-Choreografie.
     *
     * @param \stored_file|null $existing Bisherige Datei, falls vorhanden.
     * @param array $filerecord Ziel aus {@see filerecord()}.
     * @param string $content Vollstaendiger neuer Inhalt.
     */
    public static function replace(?\stored_file $existing, array $filerecord, string $content): void {
        storage_anchor::replace($existing, $filerecord, $content);
    }

    /**
     * Kopiert den Altbestand aus der alten Filearea in die Private Files
     * (Spec 0016 §3.1, Einmal-Fall beim Plugin-Upgrade). Der relative Pfad
     * bleibt gleich - die Dateien lagen schon in der alten Filearea unter
     * dem Wurzelordner und sind danach unter demselben Kurspilot-Pfad
     * erreichbar. Kollision = ueberspringen und ins Upgrade-Log schreiben;
     * der Altbestand wird **nicht** geloescht (Rueckweg).
     *
     * Dateien ausserhalb des Wurzelordners bleiben liegen: sie wuerden sonst
     * lose in der Wurzel von "Meine Dateien" landen, wo Kurspilot sie ohnehin
     * nicht mehr sieht. Sie sind im Upgrade-Log genannt, damit die Lehrkraft
     * sie bei Bedarf selbst holen kann.
     *
     * Die Nutzerquote wird hier bewusst nicht geprueft: der Umzug ist ein
     * einmaliger Systemvorgang und darf nicht am Kontostand einer einzelnen
     * Person scheitern - er kopiert nur, was die Person ohnehin schon belegt.
     *
     * Ortswissen (Whitelist, Pruefsummen, Umzug) bleibt bewusst oberhalb des
     * gemeinsamen Ankers - {@see storage_anchor} kennt keinen Altbestand.
     *
     * @return int Zahl der kopierten Dateien.
     */
    public static function migrate_legacy_files(): int {
        global $DB;

        $fs = get_file_storage();
        $root = self::resolve_directory('');
        $copied = 0;
        $legacy = $DB->get_recordset('files', [
            'component' => self::LEGACY_COMPONENT,
            'filearea' => self::LEGACY_FILEAREA,
        ]);
        foreach ($legacy as $record) {
            if ($record->filename === '.') {
                // Ordner-Platzhalter - create_file_from_storedfile() legt die
                // Ordner der kopierten Dateien ohnehin selbst an.
                continue;
            }
            if (!str_starts_with($record->filepath, $root)) {
                mtrace('local_kurspilot: Kontextdatei uebersprungen (liegt ausserhalb von "' . $root . '"): '
                    . $record->filepath . $record->filename . ' (Kontext ' . $record->contextid . ')');
                continue;
            }
            $target = [
                'contextid' => $record->contextid,
                'component' => self::COMPONENT,
                'filearea' => self::FILEAREA,
                'itemid' => self::ITEMID,
                'filepath' => $record->filepath,
                'filename' => $record->filename,
            ];
            if ($fs->file_exists(...array_values($target))) {
                mtrace('local_kurspilot: Kontextdatei uebersprungen (existiert bereits in "Meine Dateien"): '
                    . $record->filepath . $record->filename . ' (Kontext ' . $record->contextid . ')');
                continue;
            }
            $fs->create_file_from_storedfile($target, (int) $record->id);
            $copied++;
        }
        $legacy->close();

        return $copied;
    }
}
