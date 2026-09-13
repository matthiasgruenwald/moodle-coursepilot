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
 * Liest einen Bereich zeigerbewusst (Issue #490, Spec #486 §2/§6): folgt dem
 * Kontextpointer nach Moodle oder extern - der einzige Ort ausserhalb von
 * {@see storage_anchor} selbst, an dem diese Unterscheidung faellt. Von
 * {@see storage_anchor} getrennt gehalten (die Klasse naeherte sich sonst
 * der 800-Zeilen-Grenze aus den Coding-Standards), benutzt aber ausschliesslich
 * deren bereits oeffentliche Aufloesungsmethoden - kein neuer Zugriff auf
 * storage_anchor-Interna.
 *
 * Fuer den Moodle-Zweig identisch zum bisherigen Verhalten
 * ({@see storage_anchor::resolve_directory()}/{@see storage_anchor::list_entries()});
 * extern liest per {@see webdav_instance} und {@see \local_kurspilot\webdav\webdav_client::propfind()}.
 * `contenthash` bleibt bei externen Eintraegen leer - WebDAV kennt keinen
 * (wie bei Ordnereintraegen schon in {@see storage_anchor::list_entries()}).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pointer_reader {

    /**
     * Listet eine Ebene eines Bereichs.
     *
     * @param storage_area $area
     * @param string $path
     * @param pointer_location|null $location Ueberschreibt die normale
     *        Pointer-Aufloesung - fuer den Nur-Lese-Schalter des vorherigen
     *        Ortes (Issue #498, Spec #486 §6/§9), der denselben Lesezweig
     *        auf einem anderen Ort braucht. Weglassen loest wie bisher ueber
     *        {@see storage_anchor::resolve_pointer_location()} auf.
     * @return array{directory: string, entries: array}
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable/
     *         webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/webdavfingerprintchanged/webdavexternalerror
     */
    public static function list_entries(storage_area $area, string $path, ?pointer_location $location = null): array {
        $location = $location ?? storage_anchor::resolve_pointer_location($area);
        if ($location === null) {
            $directory = storage_anchor::resolve_directory($area, $path);
            return [
                'directory' => storage_anchor::relative_directory($area, $directory),
                'entries' => storage_anchor::list_entries($directory),
            ];
        }
        if ($location->kind === pointer_location::MOODLE) {
            // Der Ort ist bereits aufgeloest (regulaer oder ueberschrieben,
            // Issue #498) - die Wurzel kommt aus $location, nicht erneut aus
            // der aktiven Pointer-Aufloesung, sonst wuerde eine ueberschriebene
            // Wurzel (vorheriger Ort) hier stillschweigend ignoriert.
            $relative = storage_anchor::normalise_client_path($area, $path);
            $root = rtrim((string) $location->path, '/') . '/';
            $directory = $relative === '' ? $root : $root . $relative . '/';
            return [
                'directory' => $relative,
                'entries' => storage_anchor::list_entries($directory),
            ];
        }

        return self::list_entries_external($area, $path, $location);
    }

    /**
     * Der externe (WebDAV-)Zweig von {@see list_entries()} (Issue #523: aus
     * list_entries() ausgelagert, um die Funktion unter der 50-Zeilen-Grenze
     * zu halten).
     *
     * @param storage_area $area
     * @param string $path
     * @param pointer_location $location
     * @return array{directory: string, entries: array}
     */
    private static function list_entries_external(storage_area $area, string $path, pointer_location $location): array {
        // Der Client-Pfad bleibt frei vom intern gewaehlten WebDAV-Ordner
        // (Spec §2: "dasselbe Koordinatensystem") - genau wie im Moodle-Zweig
        // nie der Bereichs-Wurzelordner selbst im Ergebnis auftaucht.
        $clientdirectory = storage_anchor::normalise_client_path($area, $path);
        $webdavdirectory = storage_anchor::external_relative_path($area, $location, $path);
        $instance = webdav_instance::resolve($location);
        try {
            $raw = $instance->client()->propfind($instance->directory_url($webdavdirectory), 1);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing(
                $e,
                ['directory' => $clientdirectory, 'entries' => []],
                [self::class, 'webdav_exception']
            );
        }

        return [
            'directory' => $clientdirectory,
            'entries' => array_map(static fn (array $entry): array => [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'size' => $entry['size'],
                'mimetype' => $entry['mimetype'],
                // Bleibt hier bewusst leer: material_files::list_entries_pointer_aware()
                // teilt sich diese Methode (Spec §7: "contenthash bleibt leer, weil
                // WebDAV keinen kennt") - der Kontextbereich-Pruefwert (Issue #513) wird
                // deshalb erst in list_context_files::annotate_locked() aus dem
                // ebenfalls durchgereichten "etag"-Feld gebildet, nicht hier.
                'contenthash' => '',
                'timemodified' => $entry['timemodified'],
                // Nur intern verwendet (Markierungsgedaechtnis, Issue #493) -
                // {@see \local_kurspilot\external\list_context_files} entfernt
                // dieses Feld wieder, bevor die Antwort die Werkzeuggrenze
                // erreicht (execute_returns() kennt es nicht).
                'etag' => $entry['etag'],
            ], $raw),
        ];
    }

    /**
     * Liest eine Datei eines Bereichs. `null`, wenn die Datei fehlt (Moodle:
     * wie bisher; extern: `nicht gefunden`) - dieselbe Bedeutung wie bei
     * {@see storage_anchor::read_content()}, jeder andere externe Fehler
     * bleibt eine Ausnahme.
     *
     * @param storage_area $area
     * @param string $path
     * @param pointer_location|null $location Ueberschreibt die normale
     *        Pointer-Aufloesung, siehe {@see list_entries()}.
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     * @throws \moodle_exception invalidpathkey des Bereichs, sowie wie {@see list_entries()}.
     */
    public static function read_content(storage_area $area, string $path, ?pointer_location $location = null): ?array {
        $location = $location ?? storage_anchor::resolve_pointer_location($area);
        if ($location === null) {
            [$directory, $filename] = storage_anchor::resolve_file($area, $path);
            $content = storage_anchor::read_content($directory, $filename);
            if ($content === null) {
                return null;
            }
            return $content + ['path' => storage_anchor::relative_file($area, $directory, $filename)];
        }
        if ($location->kind === pointer_location::MOODLE) {
            return self::read_content_moodle($area, $path, $location);
        }

        return self::read_content_external($area, $path, $location);
    }

    /**
     * Der Moodle-Zweig von {@see read_content()} (Issue #523: ausgelagert,
     * um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param storage_area $area
     * @param string $path
     * @param pointer_location $location
     * @return array|null
     */
    private static function read_content_moodle(storage_area $area, string $path, pointer_location $location): ?array {
        // Siehe list_entries(): die Wurzel kommt aus $location, nicht
        // erneut aus der aktiven Pointer-Aufloesung (Issue #498).
        $relative = storage_anchor::normalise_client_path($area, $path);
        if ($relative === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_kurspilot');
        }
        $segments = explode('/', $relative);
        $filename = array_pop($segments);
        $root = rtrim((string) $location->path, '/') . '/';
        $directorypart = implode('/', $segments);
        $directory = $directorypart === '' ? $root : $root . $directorypart . '/';
        $content = storage_anchor::read_content($directory, $filename);
        if ($content === null) {
            return null;
        }
        return $content + ['path' => $relative];
    }

    /**
     * Der externe (WebDAV-)Zweig von {@see read_content()} (Issue #523:
     * ausgelagert).
     *
     * @param storage_area $area
     * @param string $path
     * @param pointer_location $location
     * @return array|null
     */
    private static function read_content_external(storage_area $area, string $path, pointer_location $location): ?array {
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        if ($clientpath === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_kurspilot');
        }
        $webdavpath = storage_anchor::external_relative_path($area, $location, $path);

        $instance = webdav_instance::resolve($location);
        $client = $instance->client();
        $fileurl = $instance->file_url($webdavpath);
        try {
            $meta = $client->propfind($fileurl, 0);
            $content = $client->get($fileurl);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, null, [self::class, 'webdav_exception']);
        }

        $entry = $meta[0] ?? null;
        $filename = basename($clientpath);
        return [
            'path' => $clientpath,
            'content' => $content,
            'mimetype' => $entry['mimetype'] ?? mimeinfo('type', $filename),
            'size' => $entry !== null ? $entry['size'] : strlen($content),
            'contenthash' => '',
            'timemodified' => $entry['timemodified'] ?? 0,
            // Nur intern verwendet (Issue #513, wie das gleichnamige Feld in
            // list_entries()) - read_context_file::execute() bildet daraus
            // den Pruefwert und entfernt dieses Feld wieder, bevor die
            // Antwort die Werkzeuggrenze erreicht (execute_returns() kennt
            // es nicht).
            'etag' => $entry['etag'] ?? null,
        ];
    }

    /**
     * Der aus ETag oder `getlastmodified` abgeleitete Pruefwert (Issue #513,
     * Spec #486 §4/§6) - was {@see list_entries()}/{@see read_content()} als
     * `contenthash` zurueckgeben und was {@see pointer_writer::write()}/
     * {@see pointer_writer::append()} als `expected_contenthash` wieder
     * entgegennehmen und gegen den dann aktuellen Stand pruefen.
     *
     * Kein Moodle-`contenthash`: WebDAV kennt keinen Inhalts-Hash, dieser
     * Wert ist ein rein opakes Vergleichsmerkmal. Ein ETag hat Vorrang
     * (Nextcloud); fehlt er (IServ), tritt `getlastmodified` als schwacher
     * Ersatz ein - Sekundenaufloesung, die Werkzeugbeschreibung nennt diese
     * Grenze.
     *
     * @param string|null $etag
     * @param int $timemodified
     * @return string 40-stelliger Hexwert (sha1) - PARAM_ALPHANUMEXT-sicher,
     *         anders als ein roher ETag, der haeufig Anfuehrungszeichen traegt.
     */
    public static function external_checkvalue(?string $etag, int $timemodified): string {
        return $etag !== null ? sha1('etag:' . $etag) : sha1('mtime:' . $timemodified);
    }

    /**
     * Uebersetzt einen {@see webdav_error} in eine an die Lehrkraft
     * gerichtete Meldung - nie mit Host, Konto, Passwort, HTTP-Code oder
     * Antwortrumpf (Geheimnis-Test, Spec #486 Testing Decisions), nur die
     * benannte Fehlerklasse und der Verweis auf die Ortswahlseite.
     *
     * Oeffentlich, weil auch {@see \local_kurspilot\ortswahl_lib} (Issue
     * #494: Auflisten/Anlegen auf der Ortswahlseite) denselben Fehlertext
     * braucht - eine Uebersetzung statt zwei fast identischer Kopien.
     *
     * @param webdav_error $e
     * @return \moodle_exception
     */
    public static function webdav_exception(webdav_error $e): \moodle_exception {
        return new \moodle_exception('webdavexternalerror', 'local_kurspilot', '', (object) [
            'errorclass' => $e->errorclass,
            'page' => webdav_setup_steps::ORTSWAHL_PAGE,
        ]);
    }

    /**
     * Nur der reine Inhalt einer externen Zieldatei, ohne PROPFIND (Issue
     * #515) - fuer den Personenbezugs-Schutz beim Ueberschreiben:
     * {@see \local_kurspilot\external\write_context_file} muss wissen, ob
     * eine bereits vorhandene Zieldatei markiert ist, *bevor* der eigentliche
     * Schreibversuch beginnt. Bewusst kein zweites PROPFIND vorweg: das
     * wuerde {@see pointer_writer::write()}'s eigene, unmittelbar vor dem PUT
     * ausgefuehrte Existenzpruefung vorziehen und damit den in
     * `write_context_file_test` nachgestellten Wettlauf zwischen Lesen und
     * Schreiben verfaelschen (der Test beobachtet dort das *erste* PROPFIND
     * auf den Pfad). Ein einzelnes GET beruehrt diesen Wettlauf nicht.
     *
     * Eine geloeschte Instanz, entzogene Freischaltung oder ein geaendertes
     * Pruefmerkmal ({@see \local_kurspilot\webdav\webdav_instance::resolve()})
     * liefert ebenfalls still `null`: dieser Zustand ist ortsfest, nicht
     * launenhaft - der unmittelbar folgende echte Schreibversuch loest
     * denselben Ort erneut auf und meldet denselben Fehler dann vollstaendig
     * (Ausstandsnotiz, ADR 0023). Ein `webdav_error` dagegen wird **nicht**
     * verschluckt (ausser bei einer tatsaechlich fehlenden Datei): ein
     * fluechtiger Ausfall genau dieses einen GET waere sonst ein
     * stillschweigendes "kein Personenbezug" fuer eine in Wahrheit weiterhin
     * gesperrte Datei - das widerspraeche dem in
     * {@see \local_kurspilot\webdav\webdav_client} dokumentierten Grundsatz
     * "nie stillschweigend Erfolg" (Issue #515). Stattdessen bricht der ganze
     * Schreibversuch ab, uebersetzt wie jeder andere Lesefehler (siehe
     * {@see read_content()}) - ohne Ausstandsnotiz, weil auch andere
     * Lesefehler keine bekommen.
     *
     * @param storage_area $area
     * @param string $path Client-Pfad, bereits als extern erkannt.
     * @param pointer_location $location Muss bereits als EXTERN erkannt sein.
     * @return string|null null, wenn die Datei fehlt oder der Ort gerade nicht aufloesbar ist.
     * @throws \moodle_exception webdavexternalerror bei einem echten Lesefehler (nicht: fehlende Datei).
     */
    public static function peek_external_content(storage_area $area, string $path, pointer_location $location): ?string {
        try {
            $webdavpath = storage_anchor::external_relative_path($area, $location, $path);
            $instance = webdav_instance::resolve($location);
        } catch (\moodle_exception $e) {
            return null;
        }
        try {
            return $instance->client()->get($instance->file_url($webdavpath));
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, null, [self::class, 'webdav_exception']);
        }
    }
}
