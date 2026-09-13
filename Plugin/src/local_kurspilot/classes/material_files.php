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

use core_external\external_value;

/**
 * Anker des Materialordners (Spec 0018 §2, Issue #428): Geschwisterordner zu
 * {@see context_files} in denselben Private Files der aufrufenden Lehrkraft -
 * `component=user`, `filearea=private`, `itemid=0`, eingegrenzt auf den
 * Unterordner aus {@see area()} (Default `kurspilot-material`).
 *
 * Der Ort steckt bewusst hinter genau diesem Konstantensatz (Spec 0018 §2.3):
 * kein Endpunkt kennt COMPONENT/FILEAREA/ITEMID/Wurzel selbst, sie kommen
 * ausschliesslich von hier. Ein spaeterer Umzug (z.B. an ein angebundenes
 * Repository) aendert nur den gemeinsamen {@see storage_anchor} - siehe
 * tests/storage_anchor_test.php, das genau das mit einem eigenen, im Test
 * definierten zweiten Bereich beweist, ohne einen Endpunkttest anzufassen
 * (Issue #444, Zweitort-Beweis).
 *
 * Anders als der Kontextbereich (nur `.md`, Spec 0016 §5.1) fuehrt der
 * Materialordner Binaerdateien nach Whitelist (Spec 0018 §6) - deshalb eigene
 * Namensregel statt Wiederverwendung der `.md`-Regel des Kontextbereichs.
 *
 * Diese Klasse ist seit Issue #444 eine duenne Bereichsdefinition ueber dem
 * gemeinsamen {@see storage_anchor} fuer alles, was Ortswissen ist. Was
 * echte, bereichseigene Substanz ist - Whitelists, Materialpfad-Aufloesung in
 * Dateimanager-Entwuerfe, verwendete Inhalts-Pruefsummen, Quotenwarnung -
 * bleibt hier oben unveraendert.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class material_files {

    /** @var string Ort-Parameterwert "Materialbestand" (Issue #495, Default) - pointerbewusst, nur lesend. */
    public const ORT_BESTAND = 'bestand';

    /** @var string Ort-Parameterwert "Werkbank" - fest am Anker, ignoriert den Pointer, einziges Schreibziel. */
    public const ORT_WERKBANK = 'werkbank';

    /**
     * Gemeinsame KI-Beschreibung des Parameters "ort" (Issue #508, Spec 0486 §7)
     * - eine Quelle fuer alle Material- und Einbettungswerkzeuge, damit eine
     * Aenderung an Wertebereich oder Beschreibung alle zugleich trifft.
     *
     * @var string
     */
    public const ORT_DESCRIPTION = '"bestand" (Standard, der gewachsene Materialbestand der Lehrkraft, nur lesend) '
        . 'oder "werkbank" (Kurspilots eigene Zwischenstation)';

    /** @var string Moodle-Dateikomponente - Moodles Private Files (Spec 0018 §2.1). */
    public const COMPONENT = storage_anchor::COMPONENT;

    /** @var string Alleiniger, fuer die KI erreichbarer Dateibereich. */
    public const FILEAREA = storage_anchor::FILEAREA;

    /** @var int Fester Item-Bezug - der Bereich kennt keine weiteren Items. */
    public const ITEMID = storage_anchor::ITEMID;

    /** @var string Default-Wurzelordner, ueberschreibbar per Plugin-Einstellung. */
    private const DEFAULT_ROOT = 'kurspilot-material';

    /**
     * component/filearea je Aktivitaetsart fuer deren "content"-Dateibereich
     * (Issue #434) - eine Quelle statt zweier auseinanderlaufender Kopien in
     * {@see \local_kurspilot\external\create_module::MATERIAL_REFERENCE_PSEUDOFIELDS}
     * und {@see \local_kurspilot\external\update_module_settings::MATERIAL_REFERENCE_PSEUDOFIELDS}
     * (beide referenzieren "resource"/"folder" identisch).
     *
     * @var array<string, array{component: string, filearea: string}>
     */
    public const CONTENT_FILEAREAS = [
        'resource' => ['component' => 'mod_resource', 'filearea' => 'content'],
        'folder' => ['component' => 'mod_folder', 'filearea' => 'content'],
    ];

    /**
     * Allgemeine Upload-Whitelist (Spec 0018 §6) - unveraendert aus dem
     * lokalen Weg uebernommen, siehe lib/assign-tools.js UPLOAD_MIME_TYPES.
     *
     * @var string[]
     */
    private const ALLOWED_UPLOAD_EXTENSIONS = [
        'pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'html', 'htm',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'txt', 'csv', 'zip',
    ];

    /**
     * Engere Whitelist einbettbarer Bilder (Spec 0018 §6) - unveraendert aus
     * dem lokalen Weg uebernommen, siehe lib/assign-tools.js EMBED_IMAGE_MIME_TYPES.
     * SVG bleibt bewusst zulaessig (Spec 0018 §6).
     *
     * @var string[]
     */
    private const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

    /**
     * Anteil der Nutzerquote, unter dem eine Warnung erscheint (Spec 0018 §8.1).
     */
    private const QUOTA_WARNING_RATIO = 0.1;

    /**
     * Gemeinsamer "ort"-Webservice-Parameter (Issue #508): Wertebereich,
     * Default und KI-Beschreibung an einer Stelle statt je Werkzeug einer
     * eigenen, leicht auseinanderlaufenden Kopie.
     *
     * @return external_value
     */
    public static function ort_parameter(): external_value {
        return new external_value(PARAM_ALPHA, self::ORT_DESCRIPTION, VALUE_DEFAULT, self::ORT_BESTAND);
    }

    /**
     * Dieselbe Definition als Rohdaten fuer tool_registry-Schemas, die die
     * KI-Werkzeugliste bilden (Issue #508).
     *
     * @return array{type: string, enum: string[], description: string}
     */
    public static function ort_schema(): array {
        return [
            'type' => 'string',
            'enum' => [self::ORT_BESTAND, self::ORT_WERKBANK],
            'description' => self::ORT_DESCRIPTION,
        ];
    }

    /**
     * Die Bereichsdefinition des Materialordners (Issue #444): Wurzel-
     * Einstellungsname, Standardwurzel, Fehlerschluessel und die eine echte
     * Policy-Methode dieses Bereichs - die Endungs-Whitelist beim Schreiben
     * (Spec 0018 §2.4/§6).
     *
     * @return storage_area
     */
    public static function area(): storage_area {
        return new storage_area(
            rootsetting: 'materialroot',
            defaultroot: self::DEFAULT_ROOT,
            invalidpathkey: 'invalidmaterialpath',
            quotaerrorkey: 'materialquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $filename) || !self::is_allowed_extension($filename)) {
                    throw new \moodle_exception('materialfiledisallowedtype', 'local_kurspilot', '', (object) [
                        'filename' => $filename,
                        'allowed' => implode(', ', self::allowed_extensions()),
                    ]);
                }
            },
            pointerkey: 'materialordner',
        );
    }

    /**
     * Die Bereichsdefinition der Werkbank (Issue #495, angepasst in Issue #520,
     * Spec #486 §1): derselbe Wertesatz wie {@see area()} - liegt der
     * Materialbestand in Moodle, auch mit eigenem Pfad aus einem
     * Kontextpointer, ist die Werkbank derselbe Ordner (Chat-Anhaenge bleiben
     * so im gewohnten Ordner fuer Aufraeumen/Loeschen erreichbar). Nur bei
     * *externem* Materialbestand faellt die Werkbank auf die konfigurierte
     * Standardwurzel im Anker zurueck ({@see storage_area::$externalfallback}) -
     * ein externes Ziel kennt noch kein schreibendes Materialwerkzeug (Issue
     * #490 baut nur den Lesepfad). Jedes schreibende Materialwerkzeug loest
     * ausschliesslich ueber diesen Bereich auf ({@see resolve_directory()}/
     * {@see resolve_file()}/{@see resolve_writable_file()} und ihre
     * relative_*()-Gegenstuecke) - der Materialbestand ({@see area()}) ist nur
     * ueber die eigenen pointerbewussten Lesemethoden erreichbar
     * ({@see list_entries_for_ort()}/{@see read_content_for_ort()}), nie als
     * Schreibziel.
     *
     * @return storage_area
     */
    public static function werkbank_area(): storage_area {
        $area = self::area();
        return new storage_area(
            rootsetting: $area->rootsetting,
            defaultroot: $area->defaultroot,
            invalidpathkey: $area->invalidpathkey,
            quotaerrorkey: $area->quotaerrorkey,
            checkwritablename: $area->checkwritablename,
            pointerkey: $area->pointerkey,
            externalfallback: true,
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
     * Moodle-Dateipfad innerhalb der Werkbank auf (Issue #520: die Werkbank
     * folgt dem Materialbestand-Pointer, solange dieser in Moodle liegt,
     * siehe {@see werkbank_area()}).
     *
     * @param string $path Relativer Unterordner, z.B. "" oder "faecher/mathe".
     * @return string Immer mit fuehrendem und abschliessendem "/".
     */
    public static function resolve_directory(string $path): string {
        return storage_anchor::resolve_directory(self::werkbank_area(), $path);
    }

    /**
     * Der Client-Pfad zu einem aufgeloesten Verzeichnis - relativ zur
     * Wurzel. Die Wurzel selbst ist der leere Pfad.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}
     * @return string
     */
    public static function relative_directory(string $directory): string {
        return storage_anchor::relative_directory(self::werkbank_area(), $directory);
    }

    /**
     * Der Client-Pfad einer Datei - wie {@see relative_directory()}, nur mit
     * Dateinamen.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}
     * @param string $filename
     * @return string
     */
    public static function relative_file(string $directory, string $filename): string {
        return storage_anchor::relative_file(self::werkbank_area(), $directory, $filename);
    }

    /**
     * Loest einen Client-Dateipfad (Ordner + Dateiname) auf - lesend, ohne
     * Endungspruefung (Altbestand/von Hand abgelegte Dateien bleiben lesbar).
     *
     * @param string $path z.B. "screenshot.png" oder "faecher/mathe/blatt.pdf".
     * @return array{0: string, 1: string} [Ordnerpfad, Dateiname]
     */
    public static function resolve_file(string $path): array {
        return storage_anchor::resolve_file(self::werkbank_area(), $path);
    }

    /**
     * Listet eine Ebene des Materialordners - ortsneutral (Issue #488).
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @return array<int, array{name: string, type: string, size: int, mimetype: string,
     *         contenthash: string, timemodified: int}>
     */
    public static function list_entries(string $directory): array {
        return storage_anchor::list_entries($directory);
    }

    /**
     * Listet den Materialordner rekursiv, nur Dateien - ortsneutral (Issue
     * #488), fuer {@see \local_kurspilot\external\report_loose_material_files}.
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @return array<int, array{directory: string, name: string, size: int,
     *         contenthash: string, timecreated: int}>
     */
    public static function list_entries_recursive(string $directory): array {
        return storage_anchor::list_entries_recursive($directory);
    }

    /**
     * Liest den Inhalt einer Materialdatei - ortsneutral (Issue #488).
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
     * Loescht eine Materialdatei, falls sie existiert - ortsneutral (Issue #488).
     *
     * @param string $directory Ergebnis von {@see resolve_directory()}.
     * @param string $filename
     * @return bool true, wenn eine Datei geloescht wurde; false, wenn keine existierte.
     */
    public static function delete(string $directory, string $filename): bool {
        return storage_anchor::delete($directory, $filename);
    }

    /**
     * Alle zulaessigen Dateiendungen (Spec 0018 §6), Vereinigung beider
     * Whitelists.
     *
     * @return string[]
     */
    public static function allowed_extensions(): array {
        return array_values(array_unique(array_merge(self::ALLOWED_UPLOAD_EXTENSIONS, self::ALLOWED_IMAGE_EXTENSIONS)));
    }

    /**
     * Ob eine Dateiendung in einer der beiden Whitelists steht.
     *
     * @param string $filename
     * @return bool
     */
    public static function is_allowed_extension(string $filename): bool {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::allowed_extensions(), true);
    }

    /**
     * Die engere Einbett-Whitelist (Spec 0018 §6, Issue #433) - dieselbe
     * Menge wie ALLOWED_IMAGE_EXTENSIONS, oeffentlich fuer die Pruefung beim
     * Einbetten in eine Aktivitaetsbeschreibung: ein PDF darf im
     * Materialordner liegen und als "Zusaetzliche Datei" angehaengt werden
     * (Issue #429), aber nicht als `<img>` in den Intro-Text.
     *
     * @return string[]
     */
    public static function allowed_embed_image_extensions(): array {
        return self::ALLOWED_IMAGE_EXTENSIONS;
    }

    /**
     * Ob eine Dateiendung in der Einbett-Whitelist steht (Spec 0018 §6).
     *
     * @param string $filename
     * @return bool
     */
    public static function is_allowed_embed_image_extension(string $filename): bool {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true);
    }

    /**
     * Wie {@see resolve_file()}, aber mit den Schreibregeln aus Spec 0018 §2.4
     * (uebernommen aus Spec 0016 §5.1): Ordnersegmente nur aus
     * `[A-Za-z0-9_-]`, Dateiname derselbe Zeichenvorrat plus eine zulaessige
     * Endung (Spec 0018 §6) statt der `.md`-Regel des Kontextbereichs.
     *
     * @param string $path z.B. "screenshot.png" oder "faecher/mathe/blatt.pdf".
     * @return array{0: string, 1: string} [Ordnerpfad, Dateiname]
     * @throws \moodle_exception invalidmaterialpath / materialfiledisallowedtype
     */
    public static function resolve_writable_file(string $path): array {
        return storage_anchor::resolve_writable_file(self::werkbank_area(), $path);
    }

    /**
     * Listet eine Ebene des angefragten Orts (Issue #495, Spec #486 §2/§7):
     * "werkbank" bleibt die bisherige, pointerfreie Auflistung; "bestand"
     * (Default) folgt dem Kontextpointer (Moodle oder extern, siehe
     * {@see pointer_reader::list_entries()}). Beide Zweige weisen einen Pfad
     * am oder unter dem Kontextbereich ab (ortsunabhaengig) und markieren
     * einen unmittelbaren Kindordner, der selbst der Kontextbereich ist, als
     * Eintragstyp "kontextbereich" statt "folder".
     *
     * @param string $ort {@see ORT_BESTAND}/{@see ORT_WERKBANK}.
     * @param string $path
     * @return array{directory: string, entries: array}
     * @throws \moodle_exception invalidmaterialort, materialpathiskontext, sowie
     *         wie {@see pointer_reader::list_entries()}.
     */
    public static function list_entries_for_ort(string $ort, string $path): array {
        $location = self::location_for_ort($ort);
        $kontext = storage_anchor::effective_location(context_files::area());
        $normalisedpath = self::normalise_path($path);
        self::guard_not_kontextbereich($location, $kontext, $normalisedpath);

        if ($ort === self::ORT_WERKBANK) {
            $directory = self::resolve_directory($path);
            $result = ['directory' => self::relative_directory($directory), 'entries' => self::list_entries($directory)];
        } else {
            $result = pointer_reader::list_entries(self::area(), $path);
        }

        $result['entries'] = array_map(
            static fn (array $entry): array => self::mark_kontextbereich_entry($entry, $location, $kontext, $normalisedpath),
            $result['entries']
        );
        return $result;
    }

    /**
     * Liest eine Datei des angefragten Orts (Issue #495) - siehe
     * {@see list_entries_for_ort()} fuer die Ort-/Kontextbereich-Logik.
     *
     * @param string $ort {@see ORT_BESTAND}/{@see ORT_WERKBANK}.
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     * @throws \moodle_exception invalidmaterialort, materialpathiskontext, sowie
     *         wie {@see pointer_reader::read_content()}.
     */
    public static function read_content_for_ort(string $ort, string $path): ?array {
        $location = self::location_for_ort($ort);
        $kontext = storage_anchor::effective_location(context_files::area());
        $normalisedpath = self::normalise_path($path);
        self::guard_not_kontextbereich($location, $kontext, $normalisedpath);

        if ($ort === self::ORT_WERKBANK) {
            [$directory, $filename] = self::resolve_file($path);
            $content = self::read_content($directory, $filename);
            return $content === null ? null : ($content + ['path' => self::relative_file($directory, $filename)]);
        }
        return pointer_reader::read_content(self::area(), $path);
    }

    /**
     * Der Client-Pfad, segmentgeprueft, aber nicht an eine Wurzel gebunden -
     * fuer Fehlermeldungen und Vergleichsschluessel, bevor feststeht, ob der
     * Pfad ueberhaupt aufloesbar ist.
     *
     * @param string $path
     * @return string
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function normalise_path(string $path): string {
        return storage_anchor::normalise_client_path(self::area(), $path);
    }

    /**
     * @param string $ort
     * @return pointer_location
     * @throws \moodle_exception invalidmaterialort
     */
    private static function location_for_ort(string $ort): pointer_location {
        if (!in_array($ort, [self::ORT_BESTAND, self::ORT_WERKBANK], true)) {
            throw new \moodle_exception('invalidmaterialort', 'local_kurspilot', '', $ort);
        }
        return $ort === self::ORT_WERKBANK
            ? storage_anchor::effective_location(self::werkbank_area())
            : storage_anchor::effective_location(self::area());
    }

    /**
     * Materialwege lehnen jeden Pfad am oder unter dem Kontextbereich ab
     * (Issue #495, Spec #486 §2/§7) - ortsunabhaengig, ueber den
     * Vergleichsschluessel aus {@see pointer_location::comparison_key()}.
     *
     * @param pointer_location $location Ort, an dem $subpath aufgeloest wird.
     * @param pointer_location $kontext Aufgeloester Kontextbereich.
     * @param string $subpath Bereits segmentgeprueft, siehe {@see normalise_path()}.
     * @throws \moodle_exception materialpathiskontext
     */
    private static function guard_not_kontextbereich(pointer_location $location, pointer_location $kontext, string $subpath): void {
        if (str_starts_with($location->comparison_key($subpath), $kontext->comparison_key())) {
            throw new \moodle_exception('materialpathiskontext', 'local_kurspilot');
        }
    }

    /**
     * Markiert einen unmittelbaren Kindordner, der selbst der Kontextbereich
     * ist, als eigenen Eintragstyp "kontextbereich" statt "folder" (Issue
     * #495, Spec #486 §2/§7) - sichtbar gelistet, aber ueber die Materialwege
     * nicht zu betreten (das erzwingt {@see guard_not_kontextbereich()} beim
     * naechsten Zugriff).
     *
     * @param array $entry
     * @param pointer_location $location Ort, an dem $parentpath liegt.
     * @param pointer_location $kontext Aufgeloester Kontextbereich.
     * @param string $parentpath Aufgeloester Elternpfad, segmentgeprueft.
     * @return array
     */
    private static function mark_kontextbereich_entry(
        array $entry,
        pointer_location $location,
        pointer_location $kontext,
        string $parentpath
    ): array {
        if ($entry['type'] !== 'folder') {
            return $entry;
        }
        $childpath = $parentpath === '' ? $entry['name'] : $parentpath . '/' . $entry['name'];
        if ($location->comparison_key($childpath) === $kontext->comparison_key()) {
            $entry['type'] = 'kontextbereich';
        }
        return $entry;
    }

    /**
     * Standard-Nutzerrecht auf die eigenen Dateien (wie context_files) - der
     * Materialordner liegt im selben Bereich wie "Meine Dateien".
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        storage_anchor::require_manage_own_files();
    }

    /**
     * Restplatz in Byte nach Nutzerquote - dieselbe, root-unabhaengige
     * Berechnung wie {@see context_files::remaining_quota()}; im gemeinsamen
     * {@see storage_anchor} wiederverwendet statt verdoppelt, weil sie sich
     * auf die gesamte Nutzerquote bezieht, nicht auf einen Unterordner.
     *
     * @return int|null Restplatz in Byte, oder null wenn keine Grenze gilt.
     */
    public static function remaining_quota(): ?int {
        return storage_anchor::remaining_quota();
    }

    /**
     * Weist einen Schreibvorgang ab, der die Nutzerquote sprengen wuerde -
     * das deckt auch die volle Quote ab (Restplatz 0, jeder positive Zuwachs
     * scheitert), Spec 0018 §8.1.
     *
     * @param int $additionalbytes Zuwachs gegenueber dem bisherigen Stand.
     * @throws \moodle_exception materialquotaexceeded
     */
    public static function require_quota(int $additionalbytes): void {
        storage_anchor::require_quota(self::area(), $additionalbytes);
    }

    /**
     * Warnmeldung, wenn nach einem Schreibvorgang weniger als 10% der
     * Nutzerquote uebrig bleiben (Spec 0018 §8.1, Form wie Spec 0016 §5.4).
     *
     * @param int $additionalbytes Zuwachs gegenueber dem bisherigen Stand.
     * @return string|null Warnmeldung, oder null wenn keine Warnung noetig ist.
     */
    public static function quota_warning(int $additionalbytes): ?string {
        global $CFG;

        $remaining = self::remaining_quota();
        $quota = (int) ($CFG->userquota ?? 0);
        if ($remaining === null || $quota <= 0) {
            return null;
        }
        $remainingafter = max(0, $remaining - $additionalbytes);
        if ($remainingafter / $quota >= self::QUOTA_WARNING_RATIO) {
            return null;
        }
        return get_string('materialquotawarning', 'local_kurspilot', format_float($remainingafter / 1048576, 1));
    }

    /**
     * Groessengrenze je Datei fuer {@see resolve_into_draft()} (Spec #486 §7,
     * Issue #496): der Entwurf zaehlt nicht gegen die Nutzerquote (eine
     * Bestandsdatei durchlaeuft nie {@see require_quota()}), stattdessen gilt
     * $CFG->maxbytes - dieselbe Grenze, die Moodles eigener Formularweg
     * (Dateimanager/-picker) fuer Uploads in eine Aktivitaet anlegt.
     * $CFG->maxbytes <= 0 bedeutet "keine eigene Grenze" (Moodle-Konvention,
     * siehe get_max_upload_file_size()).
     *
     * @param int $bytes
     * @return void
     * @throws \moodle_exception materialembedtoolarge
     */
    private static function guard_embed_size(int $bytes): void {
        global $CFG;

        $maxbytes = (int) ($CFG->maxbytes ?? 0);
        if ($maxbytes <= 0 || $bytes <= $maxbytes) {
            return;
        }
        throw new \moodle_exception('materialembedtoolarge', 'local_kurspilot', '', (object) [
            'size' => $bytes,
            'max' => $maxbytes,
        ]);
    }

    /**
     * Moodle-Dateisatz fuer eine Datei im Materialordner.
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
     * Loest eine Liste von Materialordner-Pfaden zu einem Dateimanager-Entwurf
     * auf (Spec 0018 §4.2/§7: "der Verweisweg ist fuer alle Herkuenfte
     * derselbe Pfad ab dem Materialordner"). Der Entwurf wird zuerst mit den
     * bereits an $targetcontextid/$component/$filearea/$itemid haengenden
     * Dateien vorbelegt (file_prepare_draft_area) - bestehende Anhaenge
     * bleiben also erhalten, ein Aufruf haengt nur an, ersetzt nicht.
     *
     * Rein lesend gegenueber dem Materialordner: jede Quelldatei wird
     * kopiert, nie verschoben oder geloescht - scheitert der Aufrufer danach
     * beim eigentlichen Schreiben, bleibt die Materialdatei unangetastet
     * liegen (Spec 0018 §4.2 "kein Verlust im Fehlerfall").
     *
     * Jeder Listeneintrag ist entweder ein reiner Materialordner-Pfad
     * (String, landet im Draft-Wurzelverzeichnis "/") oder ein Objekt
     * `['pfad' => <materialordner-pfad>, 'zielordner' => <unterordner>]`
     * (Issue #434, "Zielverzeichnis innerhalb des Ordners wählbar" -
     * mod_folder fuehrt echte Unterordner, mod_assign/mod_resource-Fileareas
     * sind flach und nutzen deshalb nur den String-Fall). "zielordner"
     * durchlaeuft dieselbe Segmentpruefung wie ein Materialordner-Pfad
     * (kein separates Regelwerk fuer den Draft-Zielpfad).
     *
     * Seit Issue #496 (Spec #486 §7) liest die Quelle ueber
     * {@see read_content_for_ort()} statt fest ueber die Werkbank - "bestand"
     * (Default) kopiert also direkt aus dem gewachsenen Materialbestand der
     * Lehrkraft, ohne Umweg ueber die Werkbank. Fuer diesen Zweig zaehlt der
     * Entwurf nicht gegen die Nutzerquote (Spec #486 §7: "Der Entwurf
     * belastet die Nutzerquote nicht"), stattdessen gilt je Datei die Grenze
     * {@see self::guard_embed_size()} ($CFG->maxbytes) - eine Werkbank-Datei
     * bleibt bei ihrer bestehenden Grenze (Servergrenze beim Hochladen ueber
     * upload_material_file), keine neue Grenze durch diesen Kopiervorgang.
     *
     * @param int $targetcontextid Kontext der Zielaktivitaet (Modulkontext).
     * @param string $component z.B. "mod_assign".
     * @param string $filearea z.B. "introattachment".
     * @param int $itemid
     * @param array $paths Materialordner-Pfade, z.B. ["arbeitsblatt.pdf"], oder
     *        `['pfad' => ..., 'zielordner' => ...]`-Objekte.
     * @param string $ort {@see ORT_BESTAND}/{@see ORT_WERKBANK} - Quelle der Pfade (Issue #496).
     * @return int Entwurfs-Itemid, direkt als *_update_instance()-Feldwert nutzbar.
     * @throws \moodle_exception invalidmaterialpath / invalidmaterialort / materialpathiskontext /
     *         materialfilenotfound / materialembedtoolarge
     */
    public static function resolve_into_draft(
        int $targetcontextid,
        string $component,
        string $filearea,
        int $itemid,
        array $paths,
        string $ort = self::ORT_BESTAND
    ): int {
        $fs = get_file_storage();
        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, $targetcontextid, $component, $filearea, $itemid);

        $usercontext = self::own_context();
        foreach ($paths as $entry) {
            self::embed_draft_entry($fs, $usercontext->id, $draftitemid, $entry, $ort);
        }

        return $draftitemid;
    }

    /**
     * Loest einen einzelnen Listeneintrag auf und kopiert ihn in den Entwurf
     * (Issue #523: aus resolve_into_draft() ausgelagert, um die Funktion
     * unter der 50-Zeilen-Grenze zu halten).
     *
     * @param \file_storage $fs
     * @param int $usercontextid
     * @param int $draftitemid
     * @param string|array $entry
     * @param string $ort
     */
    private static function embed_draft_entry(
        \file_storage $fs,
        int $usercontextid,
        int $draftitemid,
        $entry,
        string $ort
    ): void {
        [$path, $targetdirectory] = self::split_draft_entry($entry);
        $source = self::read_content_for_ort($ort, $path);
        if ($source === null) {
            throw new \moodle_exception('materialfilenotfound', 'local_kurspilot', '', self::normalise_path($path));
        }
        if ($ort === self::ORT_BESTAND) {
            // Nur der Bestand-Zweig braucht diese Grenze (Spec #486 §7):
            // eine Werkbank-Datei durchlief bereits die Servergrenze von
            // upload_material_file (get_max_upload_file_size()) beim
            // Hochladen - eine zusaetzliche $CFG->maxbytes-Pruefung hier
            // wuerde eine bereits abgelegte, groessere Werkbank-Datei
            // nachtraeglich am Einbetten hindern (Verhaltensaenderung
            // ohne Grundlage in der Spec, Review-Fund zu Issue #496).
            self::guard_embed_size(strlen($source['content']));
        }
        $filename = basename($source['path']);

        $existing = $fs->get_file($usercontextid, 'user', 'draft', $draftitemid, $targetdirectory, $filename);
        if ($existing) {
            // Gleicher Dateiname erneut referenziert - juengste Version gewinnt.
            $existing->delete();
        }
        // ponytail: read_content() gibt bewusst kein stored_file zurueck
        // (Issue #487/#488, siehe storage_anchor::read_content()) - der
        // Kopiervorgang traegt deshalb nur noch Mimetype explizit weiter,
        // nicht Lizenz/Autor des Originals (Moodle-Defaults gelten dann
        // fuer den Entwurf). In der Praxis identisch, weil Materialdateien
        // ausschliesslich ueber diese Werkzeuge angelegt werden und dabei
        // ohnehin nie eine eigene Lizenz/einen eigenen Autor setzen.
        // Aufwerten (stored_file-Kopie mit vollen Metadaten), sobald ein
        // echter Fall auftritt, in dem das einen Unterschied macht.
        $fs->create_file_from_string([
            'contextid' => $usercontextid,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => $targetdirectory,
            'filename' => $filename,
            'mimetype' => $source['mimetype'] !== '' ? $source['mimetype'] : null,
        ], $source['content']);
    }

    /**
     * Zerlegt einen {@see self::resolve_into_draft()}-Listeneintrag in
     * Materialordner-Pfad und Draft-Zielordner (Issue #434).
     *
     * @param mixed $entry String oder `['pfad' => ..., 'zielordner' => ...]`.
     * @return array{0: string, 1: string} [Materialordner-Pfad, Draft-Zielordner mit "/"-Rahmen].
     * @throws \moodle_exception invalidmaterialpath
     */
    private static function split_draft_entry($entry): array {
        $path = self::entry_path($entry);
        if (is_string($entry)) {
            return [$path, '/'];
        }
        $zielordner = $entry['zielordner'] ?? '';
        if (!is_string($zielordner)) {
            throw new \moodle_exception('invalidmaterialpath', 'local_kurspilot');
        }
        // Dieselbe Segmentpruefung wie ein gewoehnlicher Materialordner-Pfad
        // (kein separates Regelwerk fuer den Draft-Zielpfad): resolve_directory()
        // wirft bei "."/".."-Segmenten; relative_directory() zieht die
        // Materialwurzel wieder ab, uebrig bleiben die geprueften Segmente.
        $relative = self::relative_directory(self::resolve_directory($zielordner));
        $targetdirectory = $relative === '' ? '/' : '/' . $relative . '/';
        return [$path, $targetdirectory];
    }

    /**
     * Der reine Materialordner-Pfad eines {@see self::resolve_into_draft()}-
     * Listeneintrags, ohne Zielordner - oeffentlich, damit Aufrufer wie
     * {@see \local_kurspilot\external\update_module_settings::trash_files_about_to_be_replaced()}
     * denselben String/Objekt-Fall nicht ein zweites Mal von Hand
     * unterscheiden muessen (Issue #434).
     *
     * @param mixed $entry String oder `['pfad' => ..., 'zielordner' => ...]`.
     * @return string
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function entry_path($entry): string {
        if (is_string($entry)) {
            return $entry;
        }
        if (!is_array($entry) || !isset($entry['pfad']) || !is_string($entry['pfad'])) {
            throw new \moodle_exception('invalidmaterialpath', 'local_kurspilot');
        }
        return $entry['pfad'];
    }

    /**
     * Alle `contenthash`-Werte, die in einer Aktivitaets-Filearea eines
     * Kurses auftauchen, in dem die aufrufende Person Kurspilot nutzen darf
     * (Spec 0018 §8.2, Issue #438) - kein neuer Zustand, jeder Aufruf fragt
     * frisch ab, statt eine Verwendungstabelle zu fuehren, die driften
     * koennte.
     *
     * Erfasst wird der komplette Kurskontext-Teilbaum (jede Aktivitaet,
     * jede Fragebank innerhalb des Kurses), nicht nur `mod_resource`/
     * `mod_folder`: eine eingebettete Datei in einer Aufgabenbeschreibung
     * oder einem Fragetext ist genauso "verwendet".
     *
     * @return string[]
     */
    public static function used_contenthashes(): array {
        global $DB;

        $hashes = [];
        foreach (enrol_get_my_courses('id') as $course) {
            $coursecontext = \context_course::instance($course->id);
            if (!has_capability('local/kurspilot:use', $coursecontext)) {
                continue;
            }
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT f.contenthash
                   FROM {files} f
                   JOIN {context} c ON c.id = f.contextid
                  WHERE f.filename <> '.'
                    AND (c.id = :courseid OR " . $DB->sql_like('c.path', ':pathlike') . ")",
                ['courseid' => $coursecontext->id, 'pathlike' => $coursecontext->path . '/%']
            );
            foreach ($rows as $row) {
                $hashes[$row->contenthash] = true;
            }
        }
        return array_keys($hashes);
    }

    /**
     * Setzt den Inhalt einer Materialdatei neu - {@see storage_anchor::replace()}
     * wiederverwendet statt verdoppelt: die Funktion ist rein
     * dateisystemisch (Zwischendatei, dann erst die alte weg, Spec 0016 §5.3)
     * und kennt weder Kontext- noch Materialwurzel.
     *
     * @param \stored_file|null $existing Bisherige Datei, falls vorhanden.
     * @param array $filerecord Ziel aus {@see filerecord()}.
     * @param string $content Vollstaendiger neuer Inhalt.
     */
    public static function replace(?\stored_file $existing, array $filerecord, string $content): void {
        storage_anchor::replace($existing, $filerecord, $content);
    }

    /**
     * Quotengeprueftes Schreiben einer Materialdatei - gemeinsamer Kern fuer
     * jeden Aufrufer, der Bytes in den Materialordner legt (Spec 0018 §8.1):
     * {@see \local_kurspilot\external\upload_material_file} (Chat-Anhang,
     * mit vorgelagerter Endungs-Whitelist + Gleichzeitigkeitsschutz) und
     * {@see \local_kurspilot\external\export_questions_xml} (vollstaendiger
     * XML-Export, ohne Endungs-Whitelist - ".xml" steht bewusst nicht auf
     * der Upload-Whitelist, siehe {@see resolve_writable_file()}). Vorher
     * war diese Groessen-/Quote-/Schreib-Choreografie in beiden Endpunkten
     * dupliziert (Ticket #437 Standards-Review).
     *
     * @param string $directory Ergebnis von {@see resolve_file()}/{@see resolve_writable_file()}.
     * @param string $filename
     * @param string $content Vollstaendiger neuer Inhalt.
     * @param int $oldsize Bisherige Dateigroesse in Byte, 0 wenn die Datei noch nicht existiert
     *        (Aufrufer kennt sie meist schon, z.B. fuer eine eigene Gleichzeitigkeits- oder
     *        "created"-Pruefung ueber {@see read_content()}).
     * @param array $recordoverrides Zusaetzliche/ueberschreibende Dateisatz-Felder, siehe
     *        {@see storage_anchor::write()} - z.B. das `source`-Feld eines Bildausschnitts.
     * @return string|null Quotenwarnung, oder null wenn keine Warnung noetig ist.
     * @throws \moodle_exception materialquotaexceeded
     */
    public static function write(
        string $directory,
        string $filename,
        string $content,
        int $oldsize,
        array $recordoverrides = []
    ): ?string {
        $additionalbytes = strlen($content) - $oldsize;

        self::require_quota($additionalbytes);
        $warning = self::quota_warning($additionalbytes);

        storage_anchor::write($directory, $filename, $content, $recordoverrides);

        return $warning;
    }
}
