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

use local_kurspilot\webdav\webdav_error;
use local_kurspilot\webdav\webdav_instance;
use local_kurspilot\webdav\webdav_setup_steps;

/**
 * Die Logik der Ortswahlseite (Issue #494, Spec #486 §5/§10): wo Kontext-
 * bereich und Materialbestand liegen - Dateifenster-Auflistung ueber den
 * eigenen WebDAV-Client (nicht `get_listing()`), Leerzustaende aus dem
 * Schrittkatalog ({@see webdav_setup_steps}), Abschliessen (Ordner Ebene fuer
 * Ebene anlegen, dann - nur bei echter Aenderung - den Kontextpointer
 * schreiben und je geaendertem Ziel eine Zeile in den Ortsverlauf anhaengen).
 *
 * Duenne Schale obenauf (#334-Muster): `ortswahl.php` und `ortswahl_browse.php`
 * tun nur noch Ein-/Ausgabe, die gesamte Logik lebt hier, testbar mit dem
 * WebDAV-Transport-Fake ({@see \local_kurspilot\tests\webdav\fake_webdav_transport}) -
 * nie ueber die Seite selbst (Issue #494 Akzeptanzkriterium).
 *
 * Sperren (Verschachtelung, IServ, gefuellter Ordner) und der vorherige Ort
 * fuer den Altbestand folgen in spaeteren Issues (#497, #498) - hier nur die
 * Grundstruktur: das Ortsverlauf-Feld existiert und wird befuellt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ortswahl_lib {

    /** @var string[] Die beiden Ziele, wie sie im Kontextpointer-Dokument heissen (Spec §2). */
    public const TARGETS = ['kontextbereich', 'materialbestand'];

    /** @var int Wie viele Eintragsnamen die Uebergabe-Bestaetigung (Issue #497) hoechstens zeigt. */
    private const ENTRY_PREVIEW_COUNT = 5;

    /**
     * Der Leerzustand/Bereitschaftszustand der Seite (Issue #494): fehlt die
     * Freischaltung, gilt sie vor "keine Instanz" - eine Lehrkraft ohne
     * Freischaltung sieht nie den falschen Leerzustand, selbst wenn sie
     * zufaellig schon eine Instanz besitzt.
     *
     * @param int $userid
     * @return array{state: string, steps: array}
     */
    public static function setup_state(int $userid): array {
        $steps = webdav_setup_steps::catalog($userid);
        if (!$steps[webdav_setup_steps::STEP_CAPABILITY]['ok']) {
            return ['state' => 'not_enabled', 'steps' => $steps];
        }
        if (empty(self::own_instances())) {
            return ['state' => 'no_instance', 'steps' => $steps];
        }
        return ['state' => 'ready', 'steps' => $steps];
    }

    /**
     * Die eigenen WebDAV-Nutzerinstanzen der angemeldeten Person - Wurzeln
     * des Dateifensters (Issue #494). Traegt seit Issue #497 zusaetzlich, ob
     * eine Instanz ueberhaupt waehlbar ist: https+Basic ist Pflicht (Spec
     * §2 Pruefung 5/§3) - eine Instanz ohne das erscheint mit Begruendung,
     * statt einfach zu fehlen.
     *
     * @return array<int, array{id: int, name: string, selectable: bool, reason: string}>
     */
    public static function own_instances(): array {
        global $DB;

        $contextid = storage_anchor::own_context()->id;
        $records = $DB->get_records_sql(
            'SELECT ri.id, ri.name
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.contextid = :contextid AND r.type = :type
           ORDER BY ri.name ASC',
            ['contextid' => $contextid, 'type' => 'webdav']
        );
        return array_values(array_map(
            static function (\stdClass $r): array {
                $selectable = webdav_instance::has_supported_auth((int) $r->id);
                return [
                    'id' => (int) $r->id,
                    'name' => (string) $r->name,
                    'selectable' => $selectable,
                    'reason' => $selectable ? '' : get_string('ortswahlinstanceauthunsupported', 'local_kurspilot'),
                ];
            },
            $records
        ));
    }

    /**
     * Kopierbarer Text an die Administration (Leerzustand "nicht
     * freigeschaltet", Issue #494): nur die fehlenden Schritte, nichts, was
     * schon erfuellt ist.
     *
     * @param int $userid
     * @return string Ein Schritt je Zeile, leer wenn nichts fehlt.
     */
    public static function missing_steps_text(int $userid): string {
        $lines = [];
        foreach (webdav_setup_steps::catalog($userid) as $step) {
            if (!$step['ok']) {
                $lines[] = $step['instruction'];
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Der optionale Freitext-Hinweis der Schule (Leerzustand "keine
     * Instanz", Issue #494) - neue Einstellung `webdavhint`.
     *
     * @return string Leer, wenn nichts hinterlegt ist.
     */
    public static function school_hint(): string {
        return trim((string) (get_config('local_kurspilot', 'webdavhint') ?: ''));
    }

    /**
     * Listet eine Ebene einer eigenen WebDAV-Nutzerinstanz - nur Ordner
     * navigierbar (das Dateifenster waehlt einen Ort, keine Datei). Ohne
     * Netzzugriff wird nie gespeichert, protokolliert oder sonst irgendwohin
     * gereicht (Spec §5: "nie an die KI").
     *
     * Traegt seit Issue #497 zusaetzlich die Sperren der Ortswahlseite: ob
     * die gerade gebrowste Ebene ueberhaupt waehlbar ist (Wurzel, IServ
     * ausserhalb `Files/`) und - fuer die Uebergabe-Bestaetigung eines
     * gefuellten Ordners (Spec §5) - Gesamtzahl und erste Namen *aller*
     * Eintraege dieser Ebene (Dateien und Ordner).
     *
     * @param int $instanceid
     * @param string $path Relativ zur Instanzwurzel, z.B. "" oder "Unterricht/Kontext".
     * @return array{path: string, folders: array<int, array{name: string}>, iserv: bool,
     *         selectable: bool, reason: string, entrycount: int, entrynames: string[]}
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/webdavexternalerror/invalidcontextpath
     */
    public static function browse(int $instanceid, string $path): array {
        $segments = self::validate_segments($path);
        $relative = implode('/', $segments);
        $instance = webdav_instance::resolve_owned($instanceid);

        try {
            $raw = $instance->client()->propfind($instance->directory_url($relative), 1);
        } catch (webdav_error $e) {
            if ($e->errorclass === webdav_error::NOT_FOUND) {
                $raw = [];
            } else {
                throw pointer_reader::webdav_exception($e);
            }
        }

        $folders = array_values(array_map(
            static fn (array $entry): array => ['name' => $entry['name']],
            array_filter($raw, static fn (array $entry): bool => $entry['type'] === 'folder')
        ));
        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        $iserv = self::iserv_root($segments, $instance, $raw);
        [$selectable, $reason] = self::selectability($segments, $iserv);

        $names = array_values(array_map(static fn (array $entry): string => $entry['name'], $raw));
        usort($names, 'strnatcasecmp');

        return [
            'path' => $relative,
            'folders' => $folders,
            'iserv' => $iserv,
            'selectable' => $selectable,
            'reason' => $reason,
            'entrycount' => count($raw),
            'entrynames' => array_slice($names, 0, self::ENTRY_PREVIEW_COUNT),
        ];
    }

    /**
     * IServ-Erkennung fuer eine gebrowste Ebene (Issue #497, Spec #486 §5):
     * an der Wurzel selbst steht die Antwort schon in `$rootlisting`, tiefer
     * braucht es ein zusaetzliches PROPFIND auf die Wurzel - ein Netzfehler
     * dabei gilt als "nicht erkannt" (die eigentliche Auflistung ist ja
     * bereits gegluecht, das Browsen soll daran nicht scheitern).
     *
     * @param string[] $segments
     * @param \local_kurspilot\webdav\resolved_webdav_instance $instance
     * @param array $rootlisting Nur gueltig, wenn `$segments` leer ist.
     * @return bool
     */
    private static function iserv_root(array $segments, \local_kurspilot\webdav\resolved_webdav_instance $instance, array $rootlisting): bool {
        if (empty($segments)) {
            return webdav_instance::is_iserv_listing($rootlisting);
        }
        try {
            return webdav_instance::is_iserv_listing($instance->client()->propfind($instance->directory_url(''), 1));
        } catch (webdav_error $e) {
            return false;
        }
    }

    /**
     * Waehlbarkeit einer Ebene (Issue #497, Spec #486 §5): weder die Wurzel
     * einer Instanz noch - bei IServ - etwas ausserhalb von `Files/`.
     *
     * @param string[] $segments
     * @param bool $iserv
     * @return array{0: bool, 1: string} [waehlbar, Begruendung (leer wenn waehlbar)]
     */
    private static function selectability(array $segments, bool $iserv): array {
        if (empty($segments)) {
            return [false, get_string('ortswahlrootnotselectable', 'local_kurspilot')];
        }
        if ($iserv && $segments[0] !== webdav_instance::ISERV_FILES_AREA) {
            return [false, get_string('ortswahliservfilesonly', 'local_kurspilot')];
        }
        return [true, ''];
    }

    /**
     * Der aktuelle Ort eines Ziels, fuer die Anzeige auf der Seite und als
     * Vorauswahl im Dateifenster (Issue #494: "Das Fenster oeffnet am Ort aus
     * dem Pointer"). Kein Pointer bzw. ein *in Moodle*-Ziel ohne
     * Aenderungswunsch heisst schlicht: die konfigurierte Standardwurzel.
     *
     * @param string $target "kontextbereich" oder "materialbestand".
     * @return array{ort: string, pfad: string, instanzid?: int, pruefmerkmal?: array, display: string}
     */
    public static function current(string $target): array {
        $value = self::current_pointer_value($target);
        return $value + ['display' => self::describe_pointer_value($value)];
    }

    /**
     * Der Ortsverlauf aus dem Kontextpointer (Issue #494 Grundstruktur,
     * Spec §2) - leer, wenn kein Pointer oder ein Pointer der ersten Fassung
     * vorliegt (die kennt noch keinen Ortsverlauf).
     *
     * @return array<int, array{datum: int, ziel: string, von: string, nach: string}>
     */
    public static function history(): array {
        $document = storage_anchor::read_raw_pointer();
        $entries = $document['ortsverlauf'] ?? [];
        return is_array($entries) ? $entries : [];
    }

    /**
     * Schliesst die Ortswahl ab (Issue #494, Spec §5): legt zuerst - Ebene
     * fuer Ebene - jeden neu gewaehlten externen Ordner an; scheitert das,
     * ist nichts gespeichert (die Ausnahme laeuft ungefangen durch, bevor
     * irgendein Schreibzugriff auf den Pointer stattfindet). Erst danach
     * wird verglichen: nur ein Ziel, das sich wirklich aendert, bekommt eine
     * neue Ortsverlauf-Zeile; ohne echte Aenderung wird der Pointer gar
     * nicht erst angefasst.
     *
     * @param array<string, array{type: string, instanceid?: int, path?: string}> $selection
     *        Je Ziel entweder ['type' => 'moodle'] oder
     *        ['type' => 'extern', 'instanceid' => int, 'path' => string].
     * @return string[] Die tatsaechlich geaenderten Ziele.
     * @throws \moodle_exception bei ungueltiger Auswahl oder einem Ausfall beim Ordner-Anlegen.
     */
    public static function apply(array $selection): array {
        $wanted = [];
        $current = [];
        foreach (self::TARGETS as $target) {
            $wanted[$target] = self::build_target($target, $selection[$target] ?? ['type' => 'moodle']);
            $current[$target] = self::current_pointer_value($target);
        }

        // Verschachtelung (Issue #497, Spec §2 Pruefung 7): vor jedem
        // Schreibzugriff geprueft, nicht erst bei der naechsten Auflosung -
        // sonst liesse sich eine ungueltige Kombination erst gar nicht
        // abschliessen, ohne dass die Seite das sofort sagt.
        self::assert_no_overlap($wanted['kontextbereich'], $wanted['materialbestand']);

        // Nur ein Ziel, das sich wirklich aendert, braucht einen neuen
        // Ordner - ein unveraenderter Ort existiert per Definition schon
        // (Spec: "der neu gewaehlte Ordner"). Erst alles anlegen, dann erst
        // (weiter unten) etwas speichern.
        foreach (self::TARGETS as $target) {
            if ($wanted[$target]['ort'] === 'extern' && !self::same_place($current[$target], $wanted[$target])) {
                self::ensure_directory((int) $wanted[$target]['instanzid'], (string) $wanted[$target]['pfad']);
            }
        }

        $changed = [];
        $ortsverlauf = self::history();
        foreach (self::TARGETS as $target) {
            if (self::same_place($current[$target], $wanted[$target])) {
                continue;
            }
            $changed[] = $target;
            $ortsverlauf[] = [
                'datum' => time(),
                'ziel' => $target,
                'von' => self::describe_pointer_value($current[$target]),
                'nach' => self::describe_pointer_value($wanted[$target]),
            ];
        }

        if (empty($changed)) {
            return [];
        }

        storage_anchor::write_pointer_document([
            'kontextbereich' => $wanted['kontextbereich'],
            'materialbestand' => $wanted['materialbestand'],
            'ortsverlauf' => $ortsverlauf,
        ]);

        return $changed;
    }

    /**
     * Ob die Ortswahl noch offen ist (kein Kontextpointer) und die
     * WebDAV-Freischaltung fuer diese Person vorliegt - der Fakt fuer
     * `kurspilot_list_skills` (Issue #494 Akzeptanzkriterium).
     *
     * @param int $userid
     * @return bool
     */
    public static function open_with_access(int $userid): bool {
        return webdav_setup_steps::enabled_for_user($userid) && storage_anchor::read_raw_pointer() === null;
    }

    /**
     * @param string $target
     * @return storage_area
     */
    private static function area(string $target): storage_area {
        return $target === 'materialbestand' ? material_files::area() : context_files::area();
    }

    /**
     * @param string $target
     * @return array{ort: string, pfad: string, instanzid?: int, pruefmerkmal?: array}
     */
    private static function current_pointer_value(string $target): array {
        $area = self::area($target);
        $location = storage_anchor::resolve_pointer_location($area);
        if ($location === null) {
            return ['ort' => pointer_location::MOODLE, 'pfad' => storage_anchor::default_root($area)];
        }
        if ($location->kind === pointer_location::MOODLE) {
            return ['ort' => pointer_location::MOODLE, 'pfad' => trim((string) $location->path, '/')];
        }
        return [
            'ort' => pointer_location::EXTERN,
            'instanzid' => (int) $location->instanceid,
            'pfad' => (string) $location->relativepath,
            'pruefmerkmal' => $location->fingerprint,
        ];
    }

    /**
     * @param string $target
     * @param array{type?: string, instanceid?: int, path?: string} $selection
     * @return array{ort: string, pfad: string, instanzid?: int, pruefmerkmal?: array}
     * @throws \moodle_exception ortswahlselectioninvalid, oder wie {@see webdav_instance::resolve_owned()}.
     */
    private static function build_target(string $target, array $selection): array {
        $type = $selection['type'] ?? pointer_location::MOODLE;
        if ($type !== pointer_location::EXTERN) {
            $area = self::area($target);
            return ['ort' => pointer_location::MOODLE, 'pfad' => storage_anchor::default_root($area)];
        }

        $instanceid = (int) ($selection['instanceid'] ?? 0);
        if ($instanceid <= 0) {
            throw new \moodle_exception('ortswahlselectioninvalid', 'local_kurspilot');
        }
        // Wirft bei fremder/fehlender/nicht freigeschalteter Instanz - die
        // Instanz-ID kommt aus einer Formulareingabe, nie ungeprueft nutzen.
        webdav_instance::resolve_owned($instanceid);
        $segments = self::validate_segments((string) ($selection['path'] ?? ''));

        // Wurzel nie waehlbar (Issue #497, Spec §5).
        if (empty($segments)) {
            throw new \moodle_exception('ortswahlrootnotselectable', 'local_kurspilot');
        }

        // IServ-Erkennung als Ja/Nein-Pruefung, frisch bei jeder Wahl (Issue
        // #497, Spec §5/§2 Pruefung 8) - das Ergebnis wandert gleich mit ins
        // Pruefmerkmal, damit spaetere Auflosungen ohne Netz pruefen koennen.
        try {
            $iserv = webdav_instance::detect_iserv_root($instanceid);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e);
        }
        if ($iserv && $segments[0] !== webdav_instance::ISERV_FILES_AREA) {
            throw new \moodle_exception('ortswahliservfilesonly', 'local_kurspilot');
        }

        return [
            'ort' => pointer_location::EXTERN,
            'instanzid' => $instanceid,
            'pfad' => implode('/', $segments),
            'pruefmerkmal' => webdav_instance::fingerprint_of($instanceid) + ['iserv' => $iserv],
        ];
    }

    /**
     * Verschachtelung (Issue #497, Spec §2 Pruefung 7, wiederverwendet der
     * Vergleichsschluessel aus Issue #495): der Materialbestand darf nicht im
     * Kontextbereich oder im selben Ordner liegen - gilt fuer die neu
     * gewaehlten Ziele, bevor irgendetwas angelegt oder gespeichert wird.
     *
     * @param array{ort: string, pfad: string, instanzid?: int, pruefmerkmal?: array} $kontextbereich
     * @param array{ort: string, pfad: string, instanzid?: int, pruefmerkmal?: array} $materialbestand
     * @throws \moodle_exception materialbestandimkontext
     */
    private static function assert_no_overlap(array $kontextbereich, array $materialbestand): void {
        $kontextkey = self::to_pointer_location($kontextbereich)->comparison_key();
        $materialkey = self::to_pointer_location($materialbestand)->comparison_key();
        if (str_starts_with($materialkey, $kontextkey)) {
            throw new \moodle_exception('materialbestandimkontext', 'local_kurspilot');
        }
    }

    /**
     * @param array{ort: string, pfad: string, instanzid?: int, pruefmerkmal?: array} $value
     * @return pointer_location
     */
    private static function to_pointer_location(array $value): pointer_location {
        if ($value['ort'] === pointer_location::MOODLE) {
            return pointer_location::moodle('/' . trim((string) $value['pfad'], '/') . '/');
        }
        return pointer_location::extern((int) $value['instanzid'], (string) $value['pfad'], (array) ($value['pruefmerkmal'] ?? []));
    }

    /**
     * Legt fehlende Ordnerebenen einer Instanz per MKCOL an (Spec §5) - ein
     * bereits vorhandenes Verzeichnis gilt als Erfolg
     * ({@see \local_kurspilot\webdav\webdav_client::mkcol()}).
     *
     * @param int $instanceid
     * @param string $path
     * @throws \moodle_exception webdavexternalerror, oder wie {@see webdav_instance::resolve_owned()}.
     */
    private static function ensure_directory(int $instanceid, string $path): void {
        $segments = self::validate_segments($path);
        if (empty($segments)) {
            return;
        }
        $instance = webdav_instance::resolve_owned($instanceid);
        try {
            $instance->client()->mkcol_chain($instance->directory_url(''), $segments);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e);
        }
    }

    /**
     * Vergleicht zwei Pointer-Zielwerte auf denselben Ort - das Pruefmerkmal
     * zaehlt bewusst nicht mit: eine unveraenderte Instanz mit frisch
     * gelesenem, aber inhaltlich gleichem Pruefmerkmal ist keine Aenderung.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function same_place(array $a, array $b): bool {
        if ($a['ort'] !== $b['ort']) {
            return false;
        }
        if ($a['ort'] === pointer_location::MOODLE) {
            return $a['pfad'] === $b['pfad'];
        }
        return (int) $a['instanzid'] === (int) $b['instanzid'] && $a['pfad'] === $b['pfad'];
    }

    /**
     * @param array $value
     * @return string
     */
    private static function describe_pointer_value(array $value): string {
        if ($value['ort'] === pointer_location::MOODLE) {
            return get_string('ortswahllocationmoodle', 'local_kurspilot', $value['pfad']);
        }
        return self::describe_extern((int) $value['instanzid'], (string) $value['pfad']);
    }

    /**
     * @param int $instanceid
     * @param string $path
     * @return string
     */
    private static function describe_extern(int $instanceid, string $path): string {
        global $DB;

        $name = $DB->get_field('repository_instances', 'name', ['id' => $instanceid]);
        $label = ($name !== false && $name !== '') ? $name : get_string('ortswahlinstanceunknown', 'local_kurspilot');
        if ($path === '') {
            return get_string('ortswahllocationexternroot', 'local_kurspilot', $label);
        }
        return get_string('ortswahllocationextern', 'local_kurspilot', (object) ['instance' => $label, 'path' => $path]);
    }

    /**
     * Segmentpruefung wie {@see storage_anchor}: nicht leer nach dem Trimmen
     * ist nicht Pflicht (die Instanzwurzel selbst ist ein gueltiger Pfad),
     * aber kein Segment ist `.`/`..`.
     *
     * @param string $path
     * @return string[]
     * @throws \moodle_exception invalidcontextpath
     */
    private static function validate_segments(string $path): array {
        $normalised = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new \moodle_exception('invalidcontextpath', 'local_kurspilot');
            }
            $segments[] = $segment;
        }
        return $segments;
    }
}
