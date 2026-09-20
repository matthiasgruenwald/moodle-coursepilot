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

use local_coursepilot\webdav\resolved_webdav_instance;
use local_coursepilot\webdav\webdav_error;
use local_coursepilot\webdav\webdav_instance;
use local_coursepilot\webdav\webdav_setup_steps;

/**
 * Schreibt in einen externen Bereich (Issue #491, Spec #486 §4/§6) - das
 * Gegenstueck zu {@see pointer_reader} fuer die beiden Schreibendpunkte
 * `write_context_file`/`append_context_file`. Nur fuer den externen Zweig:
 * der Moodle-Zweig bleibt vollstaendig in den Endpunkten selbst (Spec §6
 * "Für den Kontextbereich in Moodle bleibt alles wie heute") - die
 * Verzweigung faellt dort, nicht hier.
 *
 * Bedingtes Schreiben (Spec §4): neu angelegt wird mit `If-None-Match: *`
 * ({@see \local_coursepilot\webdav\webdav_client::put_new()}), ueberschrieben
 * mit `If-Match` bzw. dem `getlastmodified`-Ersatz
 * ({@see \local_coursepilot\webdav\webdav_client::put_new()}/put_overwrite()}).
 * Ein `Konflikt` (412) geht als eigene, an die KI gerichtete Ausnahme zurueck -
 * neu lesen, zusammenfuehren, erneut schreiben. Legt dabei **nie** eine
 * Ausstandsnotiz an - ein Konflikt ist ein Aufruffehler, kein Ausfall (ADR
 * 0023 Punkt 2). Jeder andere Ausfall an Speicher, Verbindung oder Ort -
 * einschliesslich einer geloeschten Instanz, entzogenen Freischaltung oder
 * eines geaenderten Pruefmerkmals aus {@see \local_coursepilot\webdav\webdav_instance::resolve()} -
 * vermerkt dagegen einen Eintrag in der Ausstandsnotiz, bevor der Fehler
 * zurueckgeht (Issue #492, ADR 0023).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_writer {

    /**
     * @var string Vorgang "anlegen" - Ausstandsnotiz-Vokabular (ADR 0023).
     *      Oeffentlich (Issue #505 Befund #10): die Personenbezugs-
     *      Vorpruefungen der Schreibendpunkte brauchen dasselbe Vokabular fuer
     *      {@see record_preread_failure()}.
     */
    public const OP_CREATE = ausstand_translation::OP_CREATE;

    /** @var string Vorgang "ueberschreiben". */
    public const OP_OVERWRITE = ausstand_translation::OP_OVERWRITE;

    /** @var string Vorgang "anhaengen". */
    public const OP_APPEND = ausstand_translation::OP_APPEND;

    /**
     * @var string[] moodle_exception-Fehlerschluessel, die genauso einen
     *      Ausstand anlegen wie ein {@see webdav_error} - Ort-Ausfaelle im
     *      Sinne von ADR 0023. Die ersten fuenf kommen aus
     *      {@see \local_coursepilot\webdav\webdav_instance::resolve()}
     *      (geloeschte Instanz, entzogene Freischaltung, geaendertes
     *      Pruefmerkmal, u.a.); `contextrootmissing` kommt dagegen aus
     *      {@see require_root_exists()} selbst (Issue #514: die
     *      Kontextbereich-Wurzel fehlt am externen Ort). Jeder andere
     *      moodle_exception-Fehlerschluessel, der aus diesem Zweig entkommt,
     *      ist ein Programmierfehler und laeuft unveraendert weiter.
     */
    private const LOCATION_FAILURE_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
        'webdavfingerprintchanged',
        'contextrootmissing',
        // Pruefung 8 (Issue #516, Spec #486 §2/§8): "Scheitert ein
        // Schreibvorgang an einer der Pruefungen 2 bis 6 oder 8, entsteht ein
        // Ausstand." Pruefung 1 (Pointer unlesbar/unvollstaendig) und
        // Pruefung 7 (Verschachtelung) bleiben bewusst aussen vor - beides
        // sind Aufruffehler, keine Ausfaelle an Speicher/Verbindung/Ort.
        'webdaviservfilesonly',
    ];

    /**
     * @var string[] Fehlerklassen/-schluessel, deren Ursache sich "spaeter"
     *      von selbst loest (voruebergehend) statt an der Verbindung der
     *      Lehrkraft zu haengen - Teil 2 der fuenfteiligen Ausfallantwort
     *      (Issue #516, Spec #486 §8: "spaeter nachtragen" vs. "an Ihrem
     *      Speicher ist etwas zu tun"). Jede andere Fehlerklasse gilt als
     *      "an Ihrem Speicher ist etwas zu tun".
     */
    private const LATER_CLASSES = [
        webdav_error::UNCLEAR,
        webdav_error::UNREACHABLE,
    ];

    /**
     * @var array<string, string> Fehlerklasse/-schluessel => Ursache in
     *      Lehrkraftsprache, Teil 2 der fuenfteiligen Ausfallantwort
     *      (Issue #492).
     */
    private const REASONS = [
        webdav_error::UNCLEAR => 'der Speicher antwortet gerade nicht eindeutig (möglicherweise gedrosselt)',
        webdav_error::NOT_FOUND => 'der Zielordner ist dort nicht erreichbar',
        webdav_error::AUTH_REJECTED => 'die Anmeldung am Speicher wurde abgelehnt',
        webdav_error::UNREACHABLE => 'der Speicher ist gerade nicht erreichbar',
        webdav_error::STORAGE_FULL => 'der Speicher ist voll',
        webdav_error::BLOCKED => 'der Zugriff auf den Speicher ist gesperrt',
        webdav_error::REDIRECTED => 'der Speicher hat auf eine andere Adresse umgeleitet',
        'webdavinstancemissing' => 'die Verbindung existiert nicht mehr',
        'webdavinstanceforeign' => 'die Verbindung gehört nicht mehr zu Ihnen',
        'webdavnotenabled' => 'externe Speicher sind für Sie nicht mehr freigeschaltet',
        'webdavauthunsupported' => 'die Verbindung nutzt eine nicht mehr unterstützte Anmeldeart',
        'webdavfingerprintchanged' => 'Server, Pfad oder Konto der Verbindung haben sich geändert',
        'contextrootmissing' => 'der gewählte Kontextbereich ist dort nicht mehr vorhanden (verschoben, gelöscht'
            . ' oder umbenannt) — bitte auf der Ortswahlseite neu wählen',
        'webdaviservfilesonly' => 'der gewählte Pfad liegt bei IServ außerhalb von „Files/“',
    ];

    /**
     * Legt eine externe Datei an oder ueberschreibt sie bedingt (Spec §4/§6).
     * Ein einzelnes PUT ohne Zwischendatei - die Zwischendatei-Choreografie
     * von {@see storage_anchor::replace()} loest die Deduplizierung im
     * Moodle-Dateipool, die es extern nicht gibt.
     *
     * @param storage_area $area
     * @param pointer_location $location Bereits aufgeloester externer Ort
     *        (Issue #541) - der Aufrufer ({@see context_area}) hat den
     *        Kontextpointer bereits gelesen und als *extern* erkannt, bevor
     *        er hierher verzweigt; diese Methode loest ihn nicht erneut auf.
     * @param string $path Client-Pfad, z.B. "plan.md" oder "faecher/mathe/profil.md".
     * @param string $content Vollstaendiger neuer Inhalt.
     * @param bool $createonly Nur anlegen, nie ueberschreiben (Issue #498,
     *        Spec #486 §9: Kopieren aus dem Altbestand am neuen Ort) - eine
     *        bereits vorhandene Datei wird abgewiesen (Aufruffehler, kein
     *        Ausstand), statt sie bedingt zu ueberschreiben.
     * @param string $expectedcontenthash Pruefwert aus einem frueheren Lesen
     *        (Issue #513, {@see pointer_reader::external_checkvalue()}) -
     *        passt er nicht zum inzwischen aktuellen Stand, wird ein
     *        `Konflikt` gemeldet, statt die Handaenderung zu ueberschreiben.
     *        Leer heisst: ohne Pruefwert wird wie bisher ueberschrieben.
     * @param bool $requirecheckvalue Nachtragen (`ausstand=`, Issue #513) -
     *        eine bereits vorhandene Zieldatei ohne mitgegebenen Pruefwert
     *        gilt dann selbst als `Konflikt`, statt gewachsenen Bestand
     *        ungeprueft zu ersetzen. Wirkungslos, solange die Datei noch
     *        fehlt - "anlegen" ist ueber `If-None-Match: *` bereits sicher.
     * @param int $courseid Kurs-ID, nur fuer einen etwaigen Eintrag der
     *        Ausstandsnotiz (Issue #516, Spec #486 §8) - 0, wenn der Aufruf
     *        keinem Kurs zugeordnet ist.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     * @throws \moodle_exception invalidpathkey/contextfilenotmarkdown des Bereichs,
     *         contextfileexternalconflict bei 412 sowie bei einem nicht mehr
     *         passenden oder (beim Nachtragen) fehlenden Pruefwert,
     *         contextfilealreadyexists bei $createonly und vorhandener Datei,
     *         sonst ausstandwritefailed (Issue #492, Ausfall an Speicher/
     *         Verbindung/Ort - legt einen Eintrag in der Ausstandsnotiz an)
     *         bzw. ausstandnotewritefailed, wenn selbst die Notiz nicht mehr
     *         geschrieben werden kann.
     */
    public static function write(
        storage_area $area,
        pointer_location $location,
        string $path,
        string $content,
        bool $createonly = false,
        string $expectedcontenthash = '',
        bool $requirecheckvalue = false,
        int $courseid = 0
    ): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = self::client_path($folders, $filename);
        $operation = self::OP_CREATE;

        try {
            $instance = webdav_instance::resolve($location);
            $fileurl = $instance->file_url(storage_anchor::external_relative_path($area, $location, $clientpath));
            $client = $instance->client();
            self::ensure_directory($instance, $location, $folders);
            $existing = self::current_entry($client, $fileurl);
            if ($existing !== null && $createonly) {
                throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $clientpath);
            }
            if ($existing === null) {
                $client->put_new($fileurl, $content);
            } else {
                $operation = self::OP_OVERWRITE;
                self::require_checkvalue_match($existing, $expectedcontenthash, $requirecheckvalue, $clientpath);
                $client->put_overwrite($fileurl, $content, $existing['etag'], $existing['timemodified']);
            }
        } catch (webdav_error $e) {
            throw self::translate_or_record($e, $clientpath, $location, $operation, $courseid);
        } catch (\moodle_exception $e) {
            throw self::record_location_failure($e, $clientpath, $location, $operation, $courseid);
        }

        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => strlen($content),
            'oldsize' => $existing['size'] ?? 0,
        ];
    }

    /**
     * Haengt Inhalt an eine externe Datei an, legt sie an, falls sie noch
     * nicht existiert (Spec §6) - ein Read-modify-write mit `If-Match` bzw.
     * dem `getlastmodified`-Ersatz: der bisherige Inhalt wird gelesen, das
     * Anhaengsel angefuegt, das Ganze bedingt zurueckgeschrieben.
     *
     * @param storage_area $area
     * @param pointer_location $location Bereits aufgeloester externer Ort
     *        (Issue #541) - siehe {@see write()}.
     * @param string $path
     * @param string $content Anzuhaengender Inhalt.
     * @param string $expectedcontenthash Pruefwert aus einem frueheren Lesen
     *        (Issue #513) - siehe {@see write()}, hier vor dem Read-modify-
     *        write geprueft statt vor einem einzelnen PUT.
     * @param bool $requirecheckvalue Nachtragen (`ausstand=`, Issue #513) -
     *        siehe {@see write()}.
     * @param int $courseid Kurs-ID, nur fuer einen etwaigen Eintrag der
     *        Ausstandsnotiz (Issue #516, Spec #486 §8) - 0, wenn der Aufruf
     *        keinem Kurs zugeordnet ist.
     * @return array{path: string, created: bool, size: int}
     * @throws \moodle_exception invalidpathkey/contextfilenotmarkdown des Bereichs,
     *         contextfileexternalconflict bei 412 sowie bei einem nicht mehr
     *         passenden oder (beim Nachtragen) fehlenden Pruefwert, sonst
     *         ausstandwritefailed (Issue #492, Ausfall an Speicher/
     *         Verbindung/Ort - legt einen Eintrag in der Ausstandsnotiz an)
     *         bzw. ausstandnotewritefailed, wenn selbst die Notiz nicht mehr
     *         geschrieben werden kann.
     */
    public static function append(
        storage_area $area,
        pointer_location $location,
        string $path,
        string $content,
        string $expectedcontenthash = '',
        bool $requirecheckvalue = false,
        int $courseid = 0
    ): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = self::client_path($folders, $filename);

        try {
            $instance = webdav_instance::resolve($location);
            $fileurl = $instance->file_url(storage_anchor::external_relative_path($area, $location, $clientpath));
            $client = $instance->client();
            self::ensure_directory($instance, $location, $folders);
            $existing = self::current_entry($client, $fileurl);
            if ($existing === null) {
                $client->put_new($fileurl, $content);
                return ['path' => $clientpath, 'created' => true, 'size' => strlen($content)];
            }

            self::require_checkvalue_match($existing, $expectedcontenthash, $requirecheckvalue, $clientpath);
            $newcontent = $client->get($fileurl) . $content;
            $client->put_overwrite($fileurl, $newcontent, $existing['etag'], $existing['timemodified']);
        } catch (webdav_error $e) {
            throw self::translate_or_record($e, $clientpath, $location, self::OP_APPEND, $courseid);
        } catch (\moodle_exception $e) {
            throw self::record_location_failure($e, $clientpath, $location, self::OP_APPEND, $courseid);
        }

        return ['path' => $clientpath, 'created' => false, 'size' => strlen($newcontent)];
    }

    /**
     * @param string[] $folders
     * @param string $filename
     * @return string
     */
    private static function client_path(array $folders, string $filename): string {
        return implode('/', [...$folders, $filename]);
    }

    /**
     * Baut fehlende Unterordner *innerhalb* des Kontextbereichs per MKCOL
     * (Spec §4) - Ebene fuer Ebene. Ein bereits vorhandenes Verzeichnis gilt
     * als Erfolg ({@see \local_coursepilot\webdav\webdav_client::mkcol()}),
     * diese Methode prueft also nie selbst, was schon existiert.
     *
     * Die Wurzel des Kontextbereichs selbst - der Pointer-Pfad
     * ({@see $location}) - wird hier bewusst **nie** mitgebaut (Issue #514):
     * bis dahin war sie Teil derselben MKCOL-Kette wie die Unterordner und
     * entstand so still neu, wenn die Lehrkraft den Ordner verschoben,
     * geloescht oder umbenannt hatte - ein leerer zweiter Kontextbereich statt
     * eines benannten Fehlers. {@see require_root_exists()} prueft die Wurzel
     * deshalb vorab nur, legt sie aber nie an.
     *
     * @param resolved_webdav_instance $instance
     * @param pointer_location $location
     * @param string[] $folders Vom Aufrufer gewuenschte Unterordner, relativ zur Wurzel.
     * @throws \moodle_exception contextrootmissing, wenn die Wurzel fehlt.
     * @throws webdav_error
     */
    private static function ensure_directory(resolved_webdav_instance $instance, pointer_location $location, array $folders): void {
        $base = array_values(array_filter(explode('/', trim((string) $location->relativepath, '/')), static fn (string $s): bool => $s !== ''));
        self::require_root_exists($instance, $base);
        if (empty($folders)) {
            return;
        }
        $instance->client()->mkcol_chain($instance->directory_url(implode('/', $base)), $folders);
    }

    /**
     * Prueft, dass die Kontextbereich-Wurzel am externen Ort tatsaechlich
     * existiert - ein reines PROPFIND, nie ein MKCOL (Issue #514, siehe
     * {@see ensure_directory()}). "Serverseitig geschrieben wird
     * ausschliesslich im Kontextbereich" gilt damit woertlich: fehlt die
     * Wurzel, entsteht nichts, weder sie selbst noch ein Unterordner darin.
     *
     * @param resolved_webdav_instance $instance
     * @param string[] $base Segmente des Pointer-Pfades.
     * @throws \moodle_exception contextrootmissing, wenn die Wurzel fehlt.
     * @throws webdav_error jeder andere Ausfall - unuebersetzt, der Aufrufer
     *         (write()/append()) faengt ihn selbst, uebersetzt und vermerkt
     *         ihn als Ausstand (Issue #492).
     */
    private static function require_root_exists(resolved_webdav_instance $instance, array $base): void {
        try {
            $instance->client()->propfind($instance->directory_url(implode('/', $base)), 0);
        } catch (webdav_error $e) {
            if ($e->errorclass !== webdav_error::NOT_FOUND) {
                throw $e;
            }
            throw new \moodle_exception('contextrootmissing', 'local_coursepilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }
    }

    /**
     * Die aktuellen Eigenschaften der Zieldatei, oder null, wenn sie fehlt -
     * der eine PROPFIND, den sich Anlegen/Ueberschreiben und Anhaengen teilen.
     *
     * @param \local_coursepilot\webdav\webdav_client $client
     * @param string $fileurl
     * @return array{etag: ?string, timemodified: int, size: int}|null
     * @throws webdav_error Jeder Fehler ausser NOT_FOUND, unuebersetzt - der
     *         Aufrufer (write()/append()) faengt ihn selbst, uebersetzt und
     *         vermerkt ihn als Ausstand (Issue #492).
     */
    private static function current_entry(\local_coursepilot\webdav\webdav_client $client, string $fileurl): ?array {
        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            // Jeder andere Fehler bleibt unuebersetzt - der Aufrufer (write()/
            // append()) faengt webdav_error ohnehin selbst ab, uebersetzt und
            // vermerkt ihn als Ausstand (Issue #492). Wuerde hier schon
            // uebersetzt, waere die Ausnahme dort keine webdav_error mehr und
            // liefe am Ausstand-Fang vorbei.
            return webdav_error::empty_when_missing($e, null, static fn (webdav_error $err): webdav_error => $err);
        }
        $entry = $meta[0] ?? null;
        if ($entry === null) {
            return null;
        }
        return ['etag' => $entry['etag'], 'timemodified' => $entry['timemodified'], 'size' => $entry['size']];
    }

    /**
     * Der eigentliche Konfliktschutz mit dem gelesenen Pruefwert (Issue
     * #513): anders als das transportnahe `If-Match`/`getlastmodified` in
     * {@see \local_coursepilot\webdav\webdav_client::put_overwrite()} - das nur
     * eine Handaenderung *innerhalb* dieses Aufrufs sieht, weil {@see current_entry()}
     * ihren Stand unmittelbar vorher frisch liest - vergleicht diese Methode
     * gegen einen Stand, den die KI womoeglich lange vor diesem Aufruf gelesen
     * hat.
     *
     * Ohne mitgegebenen Pruefwert bleibt der Vertrag wie bisher (Entscheidung
     * zu Issue #513: "ohne Pruefwert wird wie heute ueberschrieben") - ausser
     * beim Nachtragen (`$requirecheckvalue`): dort ist ein fehlender
     * Pruefwert gegen eine bereits vorhandene Datei selbst ein Konflikt, denn
     * Nachtragen darf gewachsenen Bestand nie ungeprueft ersetzen.
     *
     * @param array{etag: ?string, timemodified: int, size: int} $existing
     * @param string $expectedcontenthash
     * @param bool $requirecheckvalue
     * @param string $clientpath
     * @throws \moodle_exception contextfileexternalconflict
     */
    private static function require_checkvalue_match(
        array $existing,
        string $expectedcontenthash,
        bool $requirecheckvalue,
        string $clientpath
    ): void {
        if ($expectedcontenthash === '') {
            if ($requirecheckvalue) {
                throw new \moodle_exception('contextfileexternalconflict', 'local_coursepilot', '', $clientpath);
            }
            return;
        }
        $actual = pointer_reader::external_checkvalue($existing['etag'], $existing['timemodified']);
        if ($actual !== $expectedcontenthash) {
            throw new \moodle_exception('contextfileexternalconflict', 'local_coursepilot', '', $clientpath);
        }
    }

    /**
     * Uebersetzt einen Ausfall beim Vorab-Lesen genauso wie einen Ausfall
     * beim echten Schreiben (Issue #505 Befund #10): dieselbe Ausstandsnotiz,
     * derselbe fuenfteilige Text. Genutzt von den Personenbezugs-
     * Vorpruefungen der Schreibendpunkte ({@see \local_coursepilot\external\write_context_file},
     * {@see \local_coursepilot\external\append_context_file}), wenn das GET vor
     * dem eigentlichen Schreibversuch an Verbindung/Ort scheitert (abgelehnte
     * Anmeldung, nicht erreichbar, unklar/gedrosselt, ...).
     *
     * Bricht bewusst *vor* dem eigentlichen Schreibversuch ab, statt einfach
     * durchzureichen: bei einer echten Ausfallklasse (dieselbe, die auch der
     * anschliessende Schreibversuch treffen wuerde) bleibt dadurch nichts
     * geschrieben. Wuerde man stattdessen unbesehen zum Schreibversuch
     * durchreichen, koennte eine markierte Zieldatei ungeprueft ueberschrieben
     * werden, falls ausgerechnet nur dieses eine Vorab-GET scheitert, der
     * anschliessende PUT aber durchgeht - genau der Fall, den
     * {@see \local_coursepilot\pointer_reader::peek_external_content()} laut
     * Issue #515 nicht stillschweigend uebergehen darf.
     *
     * @param webdav_error $e
     * @param pointer_location $location
     * @param string $clientpath
     * @param string $operation Eine der OP_*-Konstanten.
     * @param int $courseid Siehe {@see write()}.
     * @return \moodle_exception
     */
    public static function record_preread_failure(
        webdav_error $e,
        pointer_location $location,
        string $clientpath,
        string $operation,
        int $courseid
    ): \moodle_exception {
        return self::translate_or_record($e, $clientpath, $location, $operation, $courseid);
    }

    /**
     * `Konflikt` bekommt die bestehende, auf Zusammenfuehren gerichtete
     * Meldung und legt **keinen** Ausstand an (ADR 0023 Punkt 2: Konflikt ist
     * ein Aufruffehler, kein Ausfall); jeder andere Fehler ist ein Ausfall im
     * Sinne der Ausstandsnotiz und laeuft ueber {@see fail()}.
     */
    private static function translate_or_record(
        webdav_error $e,
        string $clientpath,
        pointer_location $location,
        string $operation,
        int $courseid
    ): \moodle_exception {
        if ($e->errorclass === webdav_error::CONFLICT) {
            return new \moodle_exception('contextfileexternalconflict', 'local_coursepilot', '', $clientpath);
        }
        return self::fail(
            $e->errorclass,
            $e->getMessage(),
            $clientpath,
            $operation,
            (string) ($location->fingerprint['server'] ?? ''),
            $location->instanceid,
            $courseid
        );
    }

    /**
     * Ort-Ausfaelle legen ebenfalls einen Ausstand an - jeder andere
     * moodle_exception-Fehlerschluessel laeuft unveraendert weiter, er gehoert
     * nicht zu diesem Zweig. Zwei Quellen (Issue #516): die ersten sechs
     * Codes aus {@see \local_coursepilot\webdav\webdav_instance::resolve()}
     * (Pruefungen 2-6: "eine geloeschte Instanz, eine entzogene
     * Freischaltung, ein geaendertes Pruefmerkmal", dazu #514s
     * "contextrootmissing") - dort ist der Pointer bereits aufgeloest,
     * $location also gesetzt; `webdaviservfilesonly` (Pruefung 8) dagegen
     * kommt aus {@see \local_coursepilot\context_pointer::resolve_target()}
     * *waehrend* der Pointer-Aufloesung selbst - $location ist dort noch
     * `null`, Host und Instanz-ID kommen dann aus dem $a der Ausnahme.
     *
     * Oeffentlich (Issue #541): {@see context_area} ruft dies inzwischen auch
     * direkt fuer `webdaviservfilesonly` auf, sobald die Pointer-Aufloesung
     * selbst schon scheitert - vorher liess context_area denselben Fehler ein
     * zweites Mal in {@see write()}/{@see append()} entstehen, nur um ihn dort
     * zu uebersetzen.
     *
     * @param \moodle_exception $e
     * @param string $clientpath
     * @param pointer_location|null $location null bei `webdaviservfilesonly`.
     * @param string $operation Eine der OP_*-Konstanten.
     * @param int $courseid Siehe {@see write()}.
     * @return \moodle_exception
     */
    public static function record_location_failure(
        \moodle_exception $e,
        string $clientpath,
        ?pointer_location $location,
        string $operation,
        int $courseid
    ): \moodle_exception {
        if (!in_array($e->errorcode, self::LOCATION_FAILURE_CODES, true)) {
            return $e;
        }
        if ($location !== null) {
            $host = (string) ($location->fingerprint['server'] ?? '');
            $instanceid = $location->instanceid;
        } else {
            $host = (string) ($e->a->server ?? '');
            $instanceid = is_numeric($e->a->instanceid ?? null) ? (int) $e->a->instanceid : null;
        }
        return self::fail($e->errorcode, $e->getMessage(), $clientpath, $operation, $host, $instanceid, $courseid);
    }

    /**
     * Vermerkt einen Ausstand ({@see ausstand_notice::record()}) und baut die
     * fuenfteilige Ausfallantwort (Issue #492/#516, Spec #486 §8/§10): (1)
     * Pfad und Vorgang; (2) Ursache in Lehrkraftsprache, mit dem Hinweis
     * "spaeter nachtragen" oder "an Ihrem Speicher ist etwas zu tun" (Issue
     * #516); (3) "noch nicht gespeichert, vermerkt (Kennung ...)"; (4) die
     * Anweisung an die KI, den Inhalt zu behalten, mit `ausstand=`
     * nachzutragen und keinen anderen Ort zu nehmen; (5) Instanzname und
     * Host. Nie ein absoluter Serverpfad, Benutzername, Passwort, HTTP-Code
     * oder Antwortrumpf (Geheimnis-Test) - der Rohcode ($rawmessage, z.B.
     * "HTTP 507") geht stattdessen ins Zugriffsprotokoll.
     *
     * Kann die Notiz selbst nicht geschrieben werden (Private-Files-Quote
     * voll), sagt die Antwort das ausdruecklich statt die urspruengliche
     * Ursache zu verschweigen.
     *
     * @param string $errorclass webdav_error-Konstante oder ein Fehlerschluessel aus LOCATION_FAILURE_CODES.
     * @param string $rawmessage Interne, entwicklerorientierte Meldung (z.B. "HTTP 507") - nur fuers Zugriffsprotokoll.
     * @param string $clientpath
     * @param string $operation Eine der OP_*-Konstanten.
     * @param string $host
     * @param int|null $instanceid
     * @param int $courseid Kurs-ID des Eintrags in der Ausstandsnotiz (Issue #516).
     * @return \moodle_exception
     */
    private static function fail(
        string $errorclass,
        string $rawmessage,
        string $clientpath,
        string $operation,
        string $host,
        ?int $instanceid,
        int $courseid
    ): \moodle_exception {
        return ausstand_translation::record_and_translate(
            $errorclass,
            'WebDAV ' . $errorclass . ': ' . $rawmessage,
            $clientpath,
            $operation,
            self::reason_for($errorclass),
            self::describe_target($host, $instanceid),
            $courseid
        );
    }

    /**
     * Die Ursache in Lehrkraftsprache samt Teil 2 der Ausfallantwort (Issue
     * #516, Spec #486 §8): "spaeter nachtragen" fuer eine Ursache, die sich
     * voraussichtlich von selbst loest, sonst "an Ihrem Speicher ist etwas zu
     * tun". Oeffentlich (Issue #540), weil {@see webdav_storage_port} dieselbe
     * WebDAV-Ursachensprache braucht, ohne sie zweimal zu pflegen.
     *
     * @param string $errorclass
     * @return string
     */
    public static function reason_for(string $errorclass): string {
        $reason = self::REASONS[$errorclass] ?? ('Fehlerklasse "' . $errorclass . '"');
        return $reason . ' – ' . self::classify($errorclass);
    }

    /**
     * Teil 2 der Ausfallantwort (Issue #516, Spec #486 §8): "spaeter
     * nachtragen" fuer eine Ursache, die sich voraussichtlich von selbst
     * loest, sonst "an Ihrem Speicher ist etwas zu tun".
     *
     * @param string $errorclass
     * @return string
     */
    private static function classify(string $errorclass): string {
        return in_array($errorclass, self::LATER_CLASSES, true)
            ? 'das lässt sich später nachtragen'
            : 'an Ihrem Speicher ist etwas zu tun';
    }

    /**
     * "Instanzname und Host" (Teil 5 der Ausfallantwort) - nie der volle
     * Basispfad (der koennte auf ein Verzeichnis des Speichers verweisen),
     * das Pruefmerkmal im Pointer traegt aber bereits nur den Host, kein
     * Geheimnis. Der Instanzname kommt frisch aus der Datenbank, weil eine
     * geloeschte Instanz (webdavinstancemissing) keinen mehr hat - dann
     * bleibt nur der uebergebene Host.
     *
     * Oeffentlich (Issue #540), weil {@see webdav_storage_port} dieselbe
     * Zielbeschreibung braucht, ohne sie zweimal zu pflegen.
     *
     * @param string $host
     * @param int|null $instanceid
     * @return string
     */
    public static function describe_target(string $host, ?int $instanceid): string {
        global $DB;

        $name = $instanceid !== null
            ? $DB->get_field('repository_instances', 'name', ['id' => $instanceid])
            : false;

        if ($name === false || $name === null || $name === '') {
            return $host;
        }
        return $name . ' (' . $host . ')';
    }
}
