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
use local_kurspilot\webdav\webdav_setup_steps;

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
 * Ausstandsnotiz an (die ist Sache eines spaeteren Issues, #492) - ein
 * Konflikt ist ein Aufruffehler, kein Ausfall.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pointer_writer {

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
     *         contextfileexternalconflict bei 412, webdavexternalwriteerror bei
     *         jedem anderen Fehler, sowie wie {@see \local_kurspilot\webdav\webdav_instance::resolve()}.
     */
    public static function write(storage_area $area, string $path, string $content): array {
        $location = self::resolve_external_location($area);
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = self::client_path($folders, $filename);
        $instance = webdav_instance::resolve($location);
        $fileurl = $instance->file_url(storage_anchor::external_relative_path($area, $location, $clientpath));
        $client = $instance->client();

        try {
            self::ensure_directory($instance, $location, $folders);
            $existing = self::current_entry($client, $fileurl, $clientpath);
            if ($existing === null) {
                $client->put_new($fileurl, $content);
            } else {
                $client->put_overwrite($fileurl, $content, $existing['etag'], $existing['timemodified']);
            }
        } catch (webdav_error $e) {
            throw self::translate($e, $clientpath);
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
     *         contextfileexternalconflict bei 412, webdavexternalwriteerror bei
     *         jedem anderen Fehler, sowie wie {@see \local_kurspilot\webdav\webdav_instance::resolve()}.
     */
    public static function append(storage_area $area, string $path, string $content): array {
        $location = self::resolve_external_location($area);
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = self::client_path($folders, $filename);
        $instance = webdav_instance::resolve($location);
        $fileurl = $instance->file_url(storage_anchor::external_relative_path($area, $location, $clientpath));
        $client = $instance->client();

        try {
            self::ensure_directory($instance, $location, $folders);
            $existing = self::current_entry($client, $fileurl, $clientpath);
            if ($existing === null) {
                $client->put_new($fileurl, $content);
                return ['path' => $clientpath, 'created' => true, 'size' => strlen($content)];
            }

            $newcontent = $client->get($fileurl) . $content;
            $client->put_overwrite($fileurl, $newcontent, $existing['etag'], $existing['timemodified']);
        } catch (webdav_error $e) {
            throw self::translate($e, $clientpath);
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
     * @param string $clientpath Fuer die Fehlermeldung, falls PROPFIND selbst scheitert.
     * @return array{etag: ?string, timemodified: int, size: int}|null
     * @throws \moodle_exception webdavexternalwriteerror
     */
    private static function current_entry(\local_kurspilot\webdav\webdav_client $client, string $fileurl, string $clientpath): ?array {
        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            if ($e->errorclass === webdav_error::NOT_FOUND) {
                return null;
            }
            throw self::translate($e, $clientpath);
        }
        $entry = $meta[0] ?? null;
        if ($entry === null) {
            return null;
        }
        return ['etag' => $entry['etag'], 'timemodified' => $entry['timemodified'], 'size' => $entry['size']];
    }

    /**
     * Uebersetzt einen {@see webdav_error} in eine an die Lehrkraft gerichtete
     * Meldung - nie mit Host, Konto, Passwort, HTTP-Code oder Antwortrumpf
     * (Geheimnis-Test, Spec #486 Testing Decisions). `Konflikt` (412) bekommt
     * eine eigene, auf Zusammenfuehren gerichtete Meldung; jeder andere Fehler
     * (u.a. `Speicher voll` bei 507) den allgemeinen Schreibfehler.
     *
     * @param webdav_error $e
     * @param string $clientpath
     * @return \moodle_exception
     */
    private static function translate(webdav_error $e, string $clientpath): \moodle_exception {
        if ($e->errorclass === webdav_error::CONFLICT) {
            return new \moodle_exception('contextfileexternalconflict', 'local_kurspilot', '', $clientpath);
        }
        return new \moodle_exception('webdavexternalwriteerror', 'local_kurspilot', '', (object) [
            'errorclass' => $e->errorclass,
            'page' => webdav_setup_steps::ORTSWAHL_PAGE,
        ]);
    }
}
