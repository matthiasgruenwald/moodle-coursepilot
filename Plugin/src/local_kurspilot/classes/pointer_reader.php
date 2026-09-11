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
     * @return array{directory: string, entries: array}
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable/
     *         webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/webdavfingerprintchanged/webdavexternalerror
     */
    public static function list_entries(storage_area $area, string $path): array {
        $location = storage_anchor::resolve_pointer_location($area);
        if ($location === null || $location->kind === pointer_location::MOODLE) {
            $directory = storage_anchor::resolve_directory($area, $path);
            return [
                'directory' => storage_anchor::relative_directory($area, $directory),
                'entries' => storage_anchor::list_entries($directory),
            ];
        }

        // Der Client-Pfad bleibt frei vom intern gewaehlten WebDAV-Ordner
        // (Spec §2: "dasselbe Koordinatensystem") - genau wie im Moodle-Zweig
        // nie der Bereichs-Wurzelordner selbst im Ergebnis auftaucht.
        $clientdirectory = storage_anchor::normalise_client_path($area, $path);
        $webdavdirectory = storage_anchor::external_relative_path($area, $location, $path);
        $instance = webdav_instance::resolve($location);
        try {
            $raw = $instance->client()->propfind($instance->directory_url($webdavdirectory), 1);
        } catch (webdav_error $e) {
            if ($e->errorclass === webdav_error::NOT_FOUND) {
                return ['directory' => $clientdirectory, 'entries' => []];
            }
            throw self::webdav_exception($e);
        }

        return [
            'directory' => $clientdirectory,
            'entries' => array_map(static fn (array $entry): array => [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'size' => $entry['size'],
                'mimetype' => $entry['mimetype'],
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
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     * @throws \moodle_exception invalidpathkey des Bereichs, sowie wie {@see list_entries()}.
     */
    public static function read_content(storage_area $area, string $path): ?array {
        $location = storage_anchor::resolve_pointer_location($area);
        if ($location === null || $location->kind === pointer_location::MOODLE) {
            [$directory, $filename] = storage_anchor::resolve_file($area, $path);
            $content = storage_anchor::read_content($directory, $filename);
            if ($content === null) {
                return null;
            }
            return $content + ['path' => storage_anchor::relative_file($area, $directory, $filename)];
        }

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
            if ($e->errorclass === webdav_error::NOT_FOUND) {
                return null;
            }
            throw self::webdav_exception($e);
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
        ];
    }

    /**
     * Uebersetzt einen {@see webdav_error} in eine an die Lehrkraft
     * gerichtete Meldung - nie mit Host, Konto, Passwort, HTTP-Code oder
     * Antwortrumpf (Geheimnis-Test, Spec #486 Testing Decisions), nur die
     * benannte Fehlerklasse und der Verweis auf die Ortswahlseite.
     *
     * @param webdav_error $e
     * @return \moodle_exception
     */
    private static function webdav_exception(webdav_error $e): \moodle_exception {
        return new \moodle_exception('webdavexternalerror', 'local_kurspilot', '', (object) [
            'errorclass' => $e->errorclass,
            'page' => webdav_setup_steps::ORTSWAHL_PAGE,
        ]);
    }
}
