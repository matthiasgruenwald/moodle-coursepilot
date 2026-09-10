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
 * Gemeinsamer Ablageort-Anker (Issue #444, Spec: Ablageort als eine Sache
 * #442 §1/§4/§5): haelt, was
 * {@see context_files} und {@see material_files} bislang doppelt trugen -
 * Komponente/Dateibereich/Itembezug, eigener Nutzerkontext, Wurzelaufloesung,
 * Segmentpruefung, Verzeichnis-/Dateiaufloesung in beide Richtungen, Recht
 * auf die eigenen Dateien, Restquote, Quotenpruefung, Dateisatz und die
 * Schreibchoreografie mit Zwischendatei.
 *
 * Ein Bereich ({@see storage_area}) ist ein Wertesatz, kein Typ - es gibt
 * bewusst keinen Ortsadapter mit eigener Schnittstelle, solange nur ein
 * Ablageort (Moodles Private Files) existiert (siehe ADR zu Issue #444).
 * context_files und material_files bleiben die oeffentliche Schnittstelle
 * fuer ihre rund 20 Aufrufer unveraendert und delegieren intern hierher.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class storage_anchor {

    /** @var string Moodle-Dateikomponente - Moodles Private Files. */
    public const COMPONENT = 'user';

    /** @var string Alleiniger, fuer die KI erreichbarer Dateibereich. */
    public const FILEAREA = 'private';

    /** @var int Fester Item-Bezug - kein Bereich kennt weitere Items. */
    public const ITEMID = 0;

    /** @var string Namensvorsatz der Zwischendatei in {@see replace()}. */
    public const TEMP_PREFIX = '.kurspilot-neu-';

    /**
     * @var string Einstellungsname des Ankers (Issue #445): der einzige, nicht
     *      per Kontextpointer ueberschreibbare Ort - sonst waere die Aufloesung
     *      zirkulaer. Identisch mit dem Wurzel-Einstellungsnamen des
     *      Kontextbereichs {@see context_files}, der den Anker damit woertlich
     *      *ist* statt ihn nur zu benennen.
     */
    public const ANCHOR_ROOTSETTING = 'contextroot';

    /** @var string Standardwurzel des Ankers, falls die Einstellung leer ist. */
    public const ANCHOR_DEFAULT_ROOT = 'kurspilot';

    /**
     * @var string Dateiname des Kontextpointers im Anker-Ordner (Issue #445,
     *      Spec: Ablageort als eine Sache #442 §2). Fuehrender Punkt und
     *      `.json`-Endung halten ihn ausserhalb der `.md`-Regel des
     *      Kontextbereichs und der Endungs-Whitelist des Materialordners -
     *      keine der beiden Schreibendpunkte kann ihn ueberschreiben, er wird
     *      ausschliesslich von Hand ueber "Meine Dateien" angelegt.
     */
    public const POINTER_FILENAME = '.kurspilot-ort.json';

    /**
     * @var string[] Pflichtfelder des Kontextpointers. Beide werden bei jedem
     *      Lesen validiert, unabhaengig davon, welcher Bereich gerade
     *      aufloest - Kontextbereich und Materialordner ziehen gemeinsam um,
     *      ein Pointer mit nur einem der beiden Felder ist immer unvollstaendig.
     */
    private const POINTER_KEYS = ['kontextbereich', 'materialordner'];

    /**
     * Der eigene Nutzerkontext der angemeldeten Person - niemals aus
     * Client-Eingaben ableitbar.
     *
     * @return \context_user
     */
    public static function own_context(): \context_user {
        global $USER;
        return \context_user::instance($USER->id);
    }

    /**
     * Wurzelordner eines Bereichs. Zweistufig aufgeloest (Issue #445, Spec:
     * Ablageort als eine Sache #442 §2): erst die per Plugin-Einstellung
     * konfigurierte Standardwurzel, dann - falls der Bereich einen
     * {@see storage_area::$pointerkey} hat und im festen Anker ein
     * Kontextpointer liegt - der dort genannte tatsaechliche Ort. Kein
     * Pointer im Anker heisst schlicht: die Standardwurzel gilt, wie schon
     * vor diesem Issue.
     *
     * @param storage_area $area
     * @return string Immer mit fuehrendem und abschliessendem "/".
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable -
     *         nie ein stiller Rueckfall auf die Standardwurzel, sobald der
     *         Pointer existiert, aber fehlerhaft ist (siehe {@see resolve_pointer()}).
     */
    private static function root(storage_area $area): string {
        $configured = self::configured_root($area->rootsetting, $area->defaultroot);
        if ($area->pointerkey === null) {
            return $configured;
        }

        $pointer = self::resolve_pointer();
        return $pointer[$area->pointerkey] ?? $configured;
    }

    /**
     * Die per Plugin-Einstellung konfigurierte Standardwurzel eines Ortes -
     * ohne Pointer-Aufloesung. Wird sowohl fuer die Standardwurzel eines
     * Bereichs als auch fuer den Anker selbst benutzt (Issue #445).
     *
     * @param string $settingname
     * @param string $defaultvalue
     * @return string Immer mit fuehrendem und abschliessendem "/".
     */
    private static function configured_root(string $settingname, string $defaultvalue): string {
        $configured = trim((string) (get_config('local_kurspilot', $settingname) ?: $defaultvalue), '/');
        return $configured === '' ? '/' : '/' . $configured . '/';
    }

    /**
     * Liest und validiert den Kontextpointer aus dem festen Anker-Ordner
     * (Issue #445). Fehlt die Datei, gilt das als "kein Pointer" - der
     * einzige Fall, der auf die Standardwurzel zurueckfaellt (siehe
     * {@see root()}). Jeder andere Fehler (kein gueltiges JSON-Objekt, ein
     * Pflichtfeld fehlt/ist leer, ein Feldwert enthaelt einen unerreichbaren
     * Pfad) wirft eine benannte moodle_exception - bewusst **ohne**
     * Rueckfall: ein stiller Rueckfall legte einen zweiten, halben
     * Kontextbereich an.
     *
     * @return array<string, string>|null Feldname => aufgeloeste Wurzel (mit
     *         fuehrendem/abschliessendem "/"), oder null wenn kein Pointer
     *         existiert.
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable
     */
    private static function resolve_pointer(): ?array {
        global $USER;

        // Ohne angemeldete Person gibt es keine "eigenen" Private Files, in
        // denen ein Pointer liegen koennte - reine Pfadaufloesung (z.B. in
        // Tests ohne setUser()) bleibt deshalb DB-frei und verhaelt sich wie
        // vor Issue #445 (Standardwurzel, kein own_context()-Zugriff).
        if (empty($USER->id)) {
            return null;
        }

        $anchor = self::configured_root(self::ANCHOR_ROOTSETTING, self::ANCHOR_DEFAULT_ROOT);
        $file = get_file_storage()->get_file(
            self::own_context()->id,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $anchor,
            self::POINTER_FILENAME
        );
        if (!$file) {
            return null;
        }

        $decoded = json_decode($file->get_content(), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || array_is_list($decoded)) {
            throw new \moodle_exception('pointerunreadable', 'local_kurspilot', '', self::POINTER_FILENAME);
        }

        $resolved = [];
        foreach (self::POINTER_KEYS as $key) {
            $resolved[$key] = '/' . self::validate_pointer_field($decoded[$key] ?? null) . '/';
        }
        return $resolved;
    }

    /**
     * Prueft einen einzelnen Pointer-Feldwert - geteilt zwischen dem Lesen
     * ({@see resolve_pointer()}) und dem Schreiben ({@see write_pointer()},
     * Issue #446): nicht leer, keine `.`/`..`-Segmente. Dieselben Regeln, die
     * ein von Hand kaputt bearbeiteter Pointer beim Lesen verletzt, weist der
     * Zustimmungsdialog schon beim Schreiben ab - kein ungueltiger Pointer
     * entsteht dort, wo er vorher nicht entstehen konnte.
     *
     * Backslashes gelten wie in {@see segments()} als Pfadtrenner. Ohne diese
     * Normalisierung passierte `..\etc` die Segmentpruefung als ein einziges,
     * harmlos aussehendes Segment.
     *
     * @param mixed $value
     * @return string Getrimmter Pfad, ohne fuehrenden/abschliessenden Schraegstrich.
     * @throws \moodle_exception pointerincomplete/pointerunreachable
     */
    private static function validate_pointer_field($value): string {
        if (!is_string($value)) {
            throw new \moodle_exception('pointerincomplete', 'local_kurspilot', '', self::POINTER_FILENAME);
        }
        $normalised = str_replace('\\', '/', $value);
        if (trim($normalised, '/') === '') {
            throw new \moodle_exception('pointerincomplete', 'local_kurspilot', '', self::POINTER_FILENAME);
        }
        $trimmed = trim($normalised, '/');
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \moodle_exception('pointerunreachable', 'local_kurspilot', '', self::POINTER_FILENAME);
            }
        }
        return $trimmed;
    }

    /**
     * Schreibt den Kontextpointer im festen Anker neu (Issue #446, Spec:
     * Ablageort als eine Sache #442 §3): der einzige Schreibweg fuer den
     * Pointer, aufgerufen ausschliesslich von der bewussten Ortswahl im
     * Zustimmungsdialog beim Verbindungsaufbau (oauth_lib::apply_storage_location_choice()).
     * Kein Kurspilot-Endpunkt ruft dies auf - die Verwaltung des Pointers
     * ausserhalb dieses einen Dialogs bleibt beim Moodle-Core ("Meine
     * Dateien"), wo er von Hand loeschbar ist.
     *
     * Bewegt keine Datei - schreibt ausschliesslich die kleine Pointer-Datei
     * selbst, per {@see replace()} mit der ueblichen Zwischendatei-Choreografie.
     *
     * @param string $kontextbereich
     * @param string $materialordner
     * @throws \moodle_exception pointerincomplete/pointerunreachable bei
     *         ungueltigen Ordnernamen.
     */
    public static function write_pointer(string $kontextbereich, string $materialordner): void {
        $content = json_encode([
            'kontextbereich' => self::validate_pointer_field($kontextbereich),
            'materialordner' => self::validate_pointer_field($materialordner),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $anchor = self::configured_root(self::ANCHOR_ROOTSETTING, self::ANCHOR_DEFAULT_ROOT);
        $contextid = self::own_context()->id;
        $existing = get_file_storage()->get_file(
            $contextid,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $anchor,
            self::POINTER_FILENAME
        );
        self::replace($existing ?: null, self::filerecord($contextid, $anchor, self::POINTER_FILENAME), $content);
    }

    /**
     * Zerlegt einen Client-Pfad in saubere Segmente und weist jedes `.`/`..`
     * ab - das erzwingt "kein Pfad, der herausfuehrt" direkt im Plugincode.
     *
     * @param storage_area $area
     * @param string $path
     * @return string[]
     */
    private static function segments(storage_area $area, string $path): array {
        $normalised = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new \moodle_exception($area->invalidpathkey, 'local_kurspilot');
            }
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Loest einen optionalen Client-Unterordner zu einem vollstaendigen
     * Moodle-Dateipfad innerhalb des Bereichs auf.
     *
     * @param storage_area $area
     * @param string $path Relativer Unterordner, z.B. "" oder "faecher/mathe".
     * @return string Immer mit fuehrendem und abschliessendem "/".
     */
    public static function resolve_directory(storage_area $area, string $path): string {
        $segments = self::segments($area, $path);
        return rtrim(self::root($area) . implode('/', $segments), '/') . '/';
    }

    /**
     * Der Client-Pfad zu einem aufgeloesten Verzeichnis - relativ zur
     * Wurzel, also in derselben Schreibweise, die jedes Werkzeug auch
     * entgegennimmt. Die Wurzel selbst ist der leere Pfad.
     *
     * @param storage_area $area
     * @param string $directory Ergebnis von {@see resolve_directory()}
     * @return string
     */
    public static function relative_directory(storage_area $area, string $directory): string {
        return trim(substr($directory, strlen(self::root($area))), '/');
    }

    /**
     * Der Client-Pfad einer Datei - wie {@see relative_directory()}, nur mit
     * Dateinamen. Eine Datei an der Wurzel ist schlicht ihr Dateiname.
     *
     * @param storage_area $area
     * @param string $directory Ergebnis von {@see resolve_directory()}
     * @param string $filename
     * @return string
     */
    public static function relative_file(storage_area $area, string $directory, string $filename): string {
        $relative = self::relative_directory($area, $directory);
        return $relative === '' ? $filename : $relative . '/' . $filename;
    }

    /**
     * Loest einen Client-Dateipfad (Ordner + Dateiname) auf.
     *
     * @param storage_area $area
     * @param string $path z.B. "vorlagen.md" oder "faecher/mathe/notiz.md".
     * @return array{0: string, 1: string} [Ordnerpfad, Dateiname]
     */
    public static function resolve_file(storage_area $area, string $path): array {
        $segments = self::segments($area, $path);
        if (empty($segments)) {
            throw new \moodle_exception($area->invalidpathkey, 'local_kurspilot');
        }
        $filename = array_pop($segments);
        return [self::resolve_directory($area, implode('/', $segments)), $filename];
    }

    /**
     * Wie {@see resolve_file()}, aber mit den engeren Schreibregeln:
     * Ordnersegmente nur aus `[A-Za-z0-9_-]`, Dateiname geprueft ueber die
     * bereichseigene Namensregel ({@see storage_area::$checkwritablename}) -
     * die eine echte, bereichsspezifische Policy-Methode. Lesen bleibt
     * bewusst grosszuegiger - der Altbestand und von Hand angelegte Dateien
     * sollen lesbar bleiben, auch wenn Kurspilot sie so nie geschrieben haette.
     *
     * @param storage_area $area
     * @param string $path z.B. "plan.md" oder "faecher/mathe/profil.md".
     * @return array{0: string, 1: string} [Ordnerpfad, Dateiname]
     * @throws \moodle_exception invalidpathkey des Bereichs / bereichseigener Namensfehler
     */
    public static function resolve_writable_file(storage_area $area, string $path): array {
        [$directory, $filename] = self::resolve_file($area, $path);

        // Nur die Ordnersegmente - der Dateiname darf als einziger einen
        // Punkt tragen und wird gleich mit seiner eigenen Regel geprueft.
        $folders = self::segments($area, $path);
        array_pop($folders);
        foreach ($folders as $segment) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                throw new \moodle_exception($area->invalidpathkey, 'local_kurspilot');
            }
        }
        ($area->checkwritablename)($filename);
        return [$directory, $filename];
    }

    /**
     * Standard-Nutzerrecht auf die eigenen Dateien - fuer alle
     * Schreibendpunkte, unabhaengig vom Bereich: beide schreiben in denselben
     * Bereich wie "Meine Dateien", also gilt dieselbe Freigabe.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        require_capability('moodle/user:manageownfiles', self::own_context());
    }

    /**
     * Restplatz in Byte nach Nutzerquote - `file_storage` setzt
     * `$CFG->userquota` nicht selbst durch, nur die Core-UI tut das.
     * Root-unabhaengig: bezieht sich auf die gesamte Nutzerquote, nicht auf
     * einen Unterordner.
     *
     * @return int|null Restplatz in Byte, oder null wenn keine Grenze gilt
     *         (Quote aus, unbegrenzt, oder `moodle/user:ignoreuserquota`).
     */
    public static function remaining_quota(): ?int {
        global $CFG;

        $quota = (int) ($CFG->userquota ?? 0);
        if ($quota <= 0 || has_capability('moodle/user:ignoreuserquota', self::own_context())) {
            return null;
        }
        return max(0, $quota - (int) file_get_user_used_space());
    }

    /**
     * Weist einen Schreibvorgang ab, der die Nutzerquote sprengen wuerde.
     *
     * @param storage_area $area
     * @param int $additionalbytes Zuwachs gegenueber dem bisherigen Stand.
     * @throws \moodle_exception quotaerrorkey des Bereichs
     */
    public static function require_quota(storage_area $area, int $additionalbytes): void {
        $remaining = self::remaining_quota();
        if ($remaining === null || $additionalbytes <= $remaining) {
            return;
        }
        throw new \moodle_exception($area->quotaerrorkey, 'local_kurspilot', '', (object) [
            'remaining' => format_float($remaining / 1048576, 1),
            'needed' => format_float($additionalbytes / 1048576, 1),
        ]);
    }

    /**
     * Moodle-Dateisatz fuer eine Datei in einem Bereich.
     *
     * @param int $contextid
     * @param string $directory
     * @param string $filename
     * @return array
     */
    public static function filerecord(int $contextid, string $directory, string $filename): array {
        return [
            'contextid' => $contextid,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => self::ITEMID,
            'filepath' => $directory,
            'filename' => $filename,
        ];
    }

    /**
     * Listet eine Ebene eines aufgeloesten Verzeichnisses - ortsneutral
     * (Issue #487): Name, Typ, Groesse, MIME-Typ, `contenthash` und
     * Aenderungszeit je Eintrag, kein Moodle-Dateiobjekt verlaesst diese
     * Methode. Der Kontextpointer ({@see POINTER_FILENAME}) ist keine
     * Arbeitsdatei und bleibt wie bisher aussen vor.
     *
     * Bewusst ohne Personenbezugs-Markierung ("locked") - das ist eine
     * Policy des Kontextbereichs (ADR 0011), nicht des Ankers. Ein Aufrufer,
     * der sie braucht, liest sie ueber {@see read_content()} je Eintrag nach.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @return array<int, array{name: string, type: string, size: int, mimetype: string,
     *         contenthash: string, timemodified: int}>
     */
    /**
     * Die Moodle-Datei hinter Verzeichnis+Dateiname, oder null - der eine
     * Nachschlagevorgang, den sich {@see read_content()}, {@see write()} und
     * {@see append()} teilen.
     *
     * @param string $directory
     * @param string $filename
     * @return \stored_file|null Nie ein Ordner-Platzhalter.
     */
    private static function find_file(string $directory, string $filename): ?\stored_file {
        $file = get_file_storage()->get_file(
            self::own_context()->id,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $directory,
            $filename
        );
        return ($file && !$file->is_directory()) ? $file : null;
    }

    public static function list_entries(string $directory): array {
        $entries = [];
        foreach (get_file_storage()->get_directory_files(
            self::own_context()->id,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $directory,
            false,
            true,
            'filepath, filename'
        ) as $file) {
            if (!$file->is_directory() && $file->get_filename() === self::POINTER_FILENAME) {
                continue;
            }
            if ($file->is_directory()) {
                // get_directory_files() schliesst den eigenen Ordner-
                // Platzhalter (":dirid") bereits aus - hier landen nur
                // unmittelbare Unterordner.
                $entries[] = [
                    'name' => trim(substr($file->get_filepath(), strlen($directory)), '/'),
                    'type' => 'folder',
                    'size' => 0,
                    'mimetype' => '',
                    'contenthash' => '',
                    'timemodified' => 0,
                ];
                continue;
            }
            $entries[] = [
                'name' => $file->get_filename(),
                'type' => 'file',
                'size' => (int) $file->get_filesize(),
                'mimetype' => (string) ($file->get_mimetype() ?? ''),
                'contenthash' => $file->get_contenthash(),
                'timemodified' => (int) $file->get_timemodified(),
            ];
        }
        return $entries;
    }

    /**
     * Liest den Inhalt einer Datei - ortsneutral (Issue #487): kein Moodle-
     * Dateiobjekt verlaesst diese Methode, nur seine Werte.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @return array{content: string, mimetype: string, size: int, contenthash: string,
     *         timemodified: int}|null null, wenn die Datei fehlt oder ein Ordner ist.
     */
    public static function read_content(string $directory, string $filename): ?array {
        $file = self::find_file($directory, $filename);
        if (!$file) {
            return null;
        }
        return [
            'content' => $file->get_content(),
            'mimetype' => (string) ($file->get_mimetype() ?? ''),
            'size' => (int) $file->get_filesize(),
            'contenthash' => $file->get_contenthash(),
            'timemodified' => (int) $file->get_timemodified(),
        ];
    }

    /**
     * Legt eine Datei an oder ersetzt ihren Inhalt vollstaendig - ortsneutral
     * (Issue #487). Absagen (Pfad, Endung, Groesse, Personenbezug,
     * Gleichzeitigkeit, Quote) sind Sache des Aufrufers, der dafuer den
     * bisherigen Stand ueber {@see read_content()} liest, bevor er hier
     * schreibt; diese Methode fuehrt nur noch den einen Schreibvorgang aus.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @param string $content Vollstaendiger neuer Inhalt.
     */
    public static function write(string $directory, string $filename, string $content): void {
        $contextid = self::own_context()->id;
        $existing = self::find_file($directory, $filename);
        self::replace($existing, self::filerecord($contextid, $directory, $filename), $content);
    }

    /**
     * Haengt Inhalt an eine Datei an, legt sie an, falls sie noch nicht
     * existiert - ortsneutral (Issue #487). Wie bei {@see write()} sind
     * Absagen Sache des Aufrufers; diese Methode liest den bisherigen Inhalt
     * selbst noch einmal, um ihn mit dem Anhaengsel zusammenzufuegen - der
     * Aufrufer bekam seinen eigenen Stand zuvor nur als Wertekopie ueber
     * {@see read_content()}, kein stored_file, das sich hier wiederverwenden
     * liesse. Gibt die tatsaechlich geschriebene Gesamtgroesse zurueck, nicht
     * die aus dem fruehreren Lesen des Aufrufers hochgerechnete - die beiden
     * koennen bei echter Gleichzeitigkeit auseinanderlaufen (Spec 0016 §5.3
     * verbietet ohnehin Locks), und die Antwort soll immer den tatsaechlich
     * geschriebenen Stand melden.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @param string $content Anzuhaengender Inhalt.
     * @return int Gesamtgroesse der Datei nach dem Anhaengen, in Byte.
     */
    public static function append(string $directory, string $filename, string $content): int {
        $contextid = self::own_context()->id;
        $existing = self::find_file($directory, $filename);
        $newcontent = $existing ? $existing->get_content() . $content : $content;
        self::replace($existing, self::filerecord($contextid, $directory, $filename), $newcontent);
        return strlen($newcontent);
    }

    /**
     * Setzt den Inhalt einer Datei neu - der eine Schreibvorgang, den sich
     * alle Schreibendpunkte teilen.
     *
     * Der neue Inhalt kommt zuerst unter einem Zwischennamen in den Dateipool,
     * erst danach faellt die alte Datei weg. Die naheliegende Reihenfolge -
     * loeschen, dann neu anlegen - ist nicht rettbar: `stored_file::delete()`
     * entfernt den Blob der letzten Referenz physisch aus dem Dateipool, und
     * eine umschliessende Transaktion holt ihn nicht zurueck. Sie stellt beim
     * Rollback nur die Datenbankzeile wieder her, die dann auf einen Blob
     * zeigt, den es nicht mehr gibt - die Lehrkraft haette ihre Datei
     * verloren, ohne dass jemand sie ueberschrieben hat.
     *
     * Bricht es zwischen Loeschen und Umbenennen ab, bleibt die Zwischendatei
     * mit dem vollstaendigen neuen Inhalt in "Meine Dateien" liegen. Sichtbar
     * und unschoen, aber nichts ist weg - der Zweck der Uebung.
     *
     * @param \stored_file|null $existing Bisherige Datei, falls vorhanden.
     * @param array $filerecord Ziel aus {@see filerecord()}.
     * @param string $content Vollstaendiger neuer Inhalt.
     */
    public static function replace(?\stored_file $existing, array $filerecord, string $content): void {
        $fs = get_file_storage();
        if (!$existing) {
            $fs->create_file_from_string($filerecord, $content);
            return;
        }

        $temprecord = $filerecord;
        $temprecord['filename'] = self::TEMP_PREFIX . uniqid() . '-' . $filerecord['filename'];
        $new = $fs->create_file_from_string($temprecord, $content);
        $existing->delete();
        $new->rename($filerecord['filepath'], $filerecord['filename']);
    }
}
