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

use local_kurspilot\webdav\resolved_webdav_instance;
use local_kurspilot\webdav\webdav_error;
use local_kurspilot\webdav\webdav_instance;

/**
 * Schreibt in einen externen Bereich (Issue #491, Spec #486 §4/§6) - das
 * Gegenstueck zu {@see pointer_reader} fuer die beiden Schreibendpunkte
 * `write_context_file`/`append_context_file`. Nur fuer den externen Zweig:
 * der Moodle-Zweig bleibt vollstaendig in den Endpunkten selbst (Spec §6
 * "Für den Kontextbereich in Moodle bleibt alles wie heute") - die
 * Verzweigung faellt dort, nicht hier.
 *
 * Bedingtes Schreiben (Spec §4): neu angelegt wird mit `If-None-Match: *`
 * ({@see \local_kurspilot\webdav\webdav_client::put_new()}), ueberschrieben
 * mit `If-Match` bzw. dem `getlastmodified`-Ersatz
 * ({@see \local_kurspilot\webdav\webdav_client::put_new()}/put_overwrite()}).
 * Ein `Konflikt` (412) geht als eigene, an die KI gerichtete Ausnahme zurueck -
 * neu lesen, zusammenfuehren, erneut schreiben. Legt dabei **nie** eine
 * Ausstandsnotiz an - ein Konflikt ist ein Aufruffehler, kein Ausfall (ADR
 * 0023 Punkt 2). Jeder andere Ausfall an Speicher, Verbindung oder Ort -
 * einschliesslich einer geloeschten Instanz, entzogenen Freischaltung oder
 * eines geaenderten Pruefmerkmals aus {@see \local_kurspilot\webdav\webdav_instance::resolve()} -
 * vermerkt dagegen einen Eintrag in der Ausstandsnotiz, bevor der Fehler
 * zurueckgeht (Issue #492, ADR 0023).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pointer_writer {

    /** @var string Vorgang "anlegen" - Ausstandsnotiz-Vokabular (ADR 0023). */
    private const OP_CREATE = 'anlegen';

    /** @var string Vorgang "ueberschreiben". */
    private const OP_OVERWRITE = 'überschreiben';

    /** @var string Vorgang "anhaengen". */
    private const OP_APPEND = 'anhängen';

    /**
     * @var string[] moodle_exception-Fehlerschluessel aus
     *      {@see \local_kurspilot\webdav\webdav_instance::resolve()} - Ort-
     *      Ausfaelle im Sinne von ADR 0023 (geloeschte Instanz, entzogene
     *      Freischaltung, geaendertes Pruefmerkmal, u.a.), die genauso einen
     *      Ausstand anlegen wie ein {@see webdav_error}. Jeder andere
     *      moodle_exception-Fehlerschluessel, der aus diesem Zweig entkommt,
     *      ist ein Programmierfehler und laeuft unveraendert weiter.
     */
    private const LOCATION_FAILURE_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
        'webdavfingerprintchanged',
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
        'webdavinstancemissing' => 'die Verbindung existiert nicht mehr',
        'webdavinstanceforeign' => 'die Verbindung gehört nicht mehr zu Ihnen',
        'webdavnotenabled' => 'externe Speicher sind für Sie nicht mehr freigeschaltet',
        'webdavauthunsupported' => 'die Verbindung nutzt eine nicht mehr unterstützte Anmeldeart',
        'webdavfingerprintchanged' => 'Server, Pfad oder Konto der Verbindung haben sich geändert',
    ];

    /**
     * Legt eine externe Datei an oder ueberschreibt sie bedingt (Spec §4/§6).
     * Ein einzelnes PUT ohne Zwischendatei - die Zwischendatei-Choreografie
     * von {@see storage_anchor::replace()} loest die Deduplizierung im
     * Moodle-Dateipool, die es extern nicht gibt.
     *
     * @param storage_area $area
     * @param string $path Client-Pfad, z.B. "plan.md" oder "faecher/mathe/profil.md".
     * @param string $content Vollstaendiger neuer Inhalt.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     * @throws \moodle_exception invalidpathkey/contextfilenotmarkdown des Bereichs,
     *         contextfileexternalconflict bei 412, sonst ausstandwritefailed
     *         (Issue #492, Ausfall an Speicher/Verbindung/Ort - legt einen
     *         Eintrag in der Ausstandsnotiz an) bzw. ausstandnotewritefailed,
     *         wenn selbst die Notiz nicht mehr geschrieben werden kann.
     */
    public static function write(storage_area $area, string $path, string $content): array {
        $location = self::resolve_external_location($area);
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = self::client_path($folders, $filename);
        $operation = self::OP_CREATE;

        try {
            $instance = webdav_instance::resolve($location);
            $fileurl = $instance->file_url(storage_anchor::external_relative_path($area, $location, $clientpath));
            $client = $instance->client();
            self::ensure_directory($instance, $location, $folders);
            $existing = self::current_entry($client, $fileurl);
            if ($existing === null) {
                $client->put_new($fileurl, $content);
            } else {
                $operation = self::OP_OVERWRITE;
                $client->put_overwrite($fileurl, $content, $existing['etag'], $existing['timemodified']);
            }
        } catch (webdav_error $e) {
            throw self::translate_or_record($e, $clientpath, $location, $operation);
        } catch (\moodle_exception $e) {
            throw self::record_location_failure($e, $clientpath, $location, $operation);
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
     * @param string $path
     * @param string $content Anzuhaengender Inhalt.
     * @return array{path: string, created: bool, size: int}
     * @throws \moodle_exception invalidpathkey/contextfilenotmarkdown des Bereichs,
     *         contextfileexternalconflict bei 412, sonst ausstandwritefailed
     *         (Issue #492, Ausfall an Speicher/Verbindung/Ort - legt einen
     *         Eintrag in der Ausstandsnotiz an) bzw. ausstandnotewritefailed,
     *         wenn selbst die Notiz nicht mehr geschrieben werden kann.
     */
    public static function append(storage_area $area, string $path, string $content): array {
        $location = self::resolve_external_location($area);
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

            $newcontent = $client->get($fileurl) . $content;
            $client->put_overwrite($fileurl, $newcontent, $existing['etag'], $existing['timemodified']);
        } catch (webdav_error $e) {
            throw self::translate_or_record($e, $clientpath, $location, self::OP_APPEND);
        } catch (\moodle_exception $e) {
            throw self::record_location_failure($e, $clientpath, $location, self::OP_APPEND);
        }

        return ['path' => $clientpath, 'created' => false, 'size' => strlen($newcontent)];
    }

    /**
     * Der aufgeloeste externe Pointer-Zustand - eine coding_exception, sollte
     * je ein Aufrufer diese Klasse ohne vorherige Pruefung auf *extern*
     * erreichen (interner Programmierfehler, nie ein Lehrkraft-sichtbarer Weg:
     * beide Endpunkte pruefen den Pointer-Zustand selbst, bevor sie hierher
     * verzweigen).
     *
     * @param storage_area $area
     * @return pointer_location
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable
     * @throws \coding_exception der Pointer ist inzwischen nicht mehr extern.
     */
    private static function resolve_external_location(storage_area $area): pointer_location {
        $location = storage_anchor::resolve_pointer_location($area);
        if ($location === null || $location->kind !== pointer_location::EXTERN) {
            throw new \coding_exception('pointer_writer erreicht ohne externen Kontextpointer.');
        }
        return $location;
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
     * Baut fehlende Ordnerebenen per MKCOL (Spec §4) - Basisordner des
     * Pointers plus die vom Aufrufer gewuenschten Unterordner, Ebene fuer
     * Ebene. Ein bereits vorhandenes Verzeichnis gilt als Erfolg
     * ({@see \local_kurspilot\webdav\webdav_client::mkcol()}), diese Methode
     * prueft also nie selbst, was schon existiert.
     *
     * @param resolved_webdav_instance $instance
     * @param pointer_location $location
     * @param string[] $folders
     * @throws webdav_error
     */
    private static function ensure_directory(resolved_webdav_instance $instance, pointer_location $location, array $folders): void {
        $base = array_values(array_filter(explode('/', trim((string) $location->relativepath, '/')), static fn (string $s): bool => $s !== ''));
        $segments = [...$base, ...$folders];
        if (empty($segments)) {
            return;
        }
        $instance->client()->mkcol_chain($instance->directory_url(''), $segments);
    }

    /**
     * Die aktuellen Eigenschaften der Zieldatei, oder null, wenn sie fehlt -
     * der eine PROPFIND, den sich Anlegen/Ueberschreiben und Anhaengen teilen.
     *
     * @param \local_kurspilot\webdav\webdav_client $client
     * @param string $fileurl
     * @return array{etag: ?string, timemodified: int, size: int}|null
     * @throws webdav_error Jeder Fehler ausser NOT_FOUND, unuebersetzt - der
     *         Aufrufer (write()/append()) faengt ihn selbst, uebersetzt und
     *         vermerkt ihn als Ausstand (Issue #492).
     */
    private static function current_entry(\local_kurspilot\webdav\webdav_client $client, string $fileurl): ?array {
        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            if ($e->errorclass === webdav_error::NOT_FOUND) {
                return null;
            }
            // Jeder andere Fehler bleibt unuebersetzt - der Aufrufer (write()/
            // append()) faengt webdav_error ohnehin selbst ab, uebersetzt und
            // vermerkt ihn als Ausstand (Issue #492). Wuerde hier schon
            // uebersetzt, waere die Ausnahme dort keine webdav_error mehr und
            // liefe am Ausstand-Fang vorbei.
            throw $e;
        }
        $entry = $meta[0] ?? null;
        if ($entry === null) {
            return null;
        }
        return ['etag' => $entry['etag'], 'timemodified' => $entry['timemodified'], 'size' => $entry['size']];
    }

    /**
     * Uebersetzt einen {@see webdav_error} - `Konflikt` bekommt die
     * bestehende, auf Zusammenfuehren gerichtete Meldung und legt **keinen**
     * Ausstand an (ADR 0023 Punkt 2: Konflikt ist ein Aufruffehler, kein
     * Ausfall); jeder andere Fehler ist ein Ausfall im Sinne der
     * Ausstandsnotiz und laeuft ueber {@see fail()}.
     *
     * @param webdav_error $e
     * @param string $clientpath
     * @param pointer_location $location
     * @param string $operation Eine der OP_*-Konstanten.
     * @return \moodle_exception
     */
    private static function translate_or_record(
        webdav_error $e,
        string $clientpath,
        pointer_location $location,
        string $operation
    ): \moodle_exception {
        if ($e->errorclass === webdav_error::CONFLICT) {
            return new \moodle_exception('contextfileexternalconflict', 'local_kurspilot', '', $clientpath);
        }
        return self::fail($e->errorclass, $e->getMessage(), $clientpath, $location, $operation);
    }

    /**
     * Ort-Ausfaelle aus {@see \local_kurspilot\webdav\webdav_instance::resolve()}
     * (Issue #492: "eine geloeschte Instanz, eine entzogene Freischaltung,
     * ein geaendertes Pruefmerkmal") legen ebenfalls einen Ausstand an - jeder
     * andere moodle_exception-Fehlerschluessel laeuft unveraendert weiter, er
     * gehoert nicht zu diesem Zweig.
     *
     * @param \moodle_exception $e
     * @param string $clientpath
     * @param pointer_location $location
     * @param string $operation Eine der OP_*-Konstanten.
     * @return \moodle_exception
     */
    private static function record_location_failure(
        \moodle_exception $e,
        string $clientpath,
        pointer_location $location,
        string $operation
    ): \moodle_exception {
        if (!in_array($e->errorcode, self::LOCATION_FAILURE_CODES, true)) {
            return $e;
        }
        return self::fail($e->errorcode, $e->getMessage(), $clientpath, $location, $operation);
    }

    /**
     * Vermerkt einen Ausstand ({@see ausstand_notice::record()}) und baut die
     * fuenfteilige Ausfallantwort (Issue #492, Spec #486 §8/§10): Pfad und
     * Vorgang; Ursache in Lehrkraftsprache; "noch nicht gespeichert, vermerkt
     * (Kennung ...)"; Anweisung an die KI; Instanzname und Host. Nie ein
     * absoluter Serverpfad, Benutzername, Passwort, HTTP-Code oder
     * Antwortrumpf (Geheimnis-Test) - der Rohcode ($rawmessage, z.B.
     * "HTTP 507") geht stattdessen ins Zugriffsprotokoll.
     *
     * Kann die Notiz selbst nicht geschrieben werden (Private-Files-Quote
     * voll), sagt die Antwort das ausdruecklich statt die urspruengliche
     * Ursache zu verschweigen.
     *
     * @param string $errorclass webdav_error-Konstante oder ein Fehlerschluessel aus LOCATION_FAILURE_CODES.
     * @param string $rawmessage Interne, entwicklerorientierte Meldung (z.B. "HTTP 507") - nur fuers Zugriffsprotokoll.
     * @param string $clientpath
     * @param pointer_location $location
     * @param string $operation Eine der OP_*-Konstanten.
     * @return \moodle_exception
     */
    private static function fail(
        string $errorclass,
        string $rawmessage,
        string $clientpath,
        pointer_location $location,
        string $operation
    ): \moodle_exception {
        access_log::log_failure('WebDAV ' . $errorclass . ': ' . $rawmessage);

        try {
            $kennung = ausstand_notice::record($clientpath, $operation, $errorclass);
        } catch (\moodle_exception $quotaerror) {
            if ($quotaerror->errorcode !== 'ausstandnotequotaexceeded') {
                throw $quotaerror;
            }
            return new \moodle_exception('ausstandnotewritefailed', 'local_kurspilot', '', (object) [
                'path' => $clientpath,
                'operation' => $operation,
            ]);
        }

        return new \moodle_exception('ausstandwritefailed', 'local_kurspilot', '', (object) [
            'path' => $clientpath,
            'operation' => $operation,
            'reason' => self::REASONS[$errorclass] ?? ('Fehlerklasse "' . $errorclass . '"'),
            'kennung' => $kennung,
            'target' => self::describe_target($location),
        ]);
    }

    /**
     * "Instanzname und Host" (Teil 5 der Ausfallantwort) - nie der volle
     * Basispfad (der koennte auf ein Verzeichnis des Speichers verweisen),
     * das Pruefmerkmal im Pointer traegt aber bereits nur den Host, kein
     * Geheimnis. Der Instanzname kommt frisch aus der Datenbank, weil eine
     * geloeschte Instanz (webdavinstancemissing) keinen mehr hat - dann
     * bleibt nur der im Pointer gespeicherte Host.
     *
     * @param pointer_location $location
     * @return string
     */
    private static function describe_target(pointer_location $location): string {
        global $DB;

        $host = (string) ($location->fingerprint['server'] ?? '');
        $name = $location->instanceid !== null
            ? $DB->get_field('repository_instances', 'name', ['id' => $location->instanceid])
            : false;

        if ($name === false || $name === null || $name === '') {
            return $host;
        }
        return $name . ' (' . $host . ')';
    }
}
