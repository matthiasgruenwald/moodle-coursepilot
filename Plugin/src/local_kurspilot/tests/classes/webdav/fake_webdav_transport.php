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

namespace local_kurspilot\tests\webdav;

use local_kurspilot\webdav\webdav_response;
use local_kurspilot\webdav\webdav_transport;

/**
 * In-Memory-WebDAV-Fake (Issue #489, Spec #486 Testing Decisions): der eine
 * Test-Seam fuer {@see \local_kurspilot\webdav\webdav_client} und alles, was
 * spaeter darauf aufsetzt - von allen kommenden Tickets wiederzuverwenden,
 * deshalb bewusst ohne Bezug zu einem bestimmten Endpunkt.
 *
 * `\curl::mock_response()` taugt dafuer nicht (nur ein Rumpf, fest HTTP 200).
 * Dieser Fake haelt stattdessen einen echten kleinen Dateibaum im Speicher
 * und wertet bedingte Koepfe selbst aus.
 *
 * Liegt unter `tests/classes/`, damit Moodles PHPUnit-Autoloader ihn ueber
 * den Namensraum `local_kurspilot\tests\webdav` laedt (siehe
 * `core\component::class_loader()`), ohne dass jeder Test ihn von Hand
 * einbindet.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fake_webdav_transport implements webdav_transport {

    /** @var array<string, array{content: string, etag: ?string, lastmodified: int, collection: bool}> Pfad => Eintrag. */
    private array $store = ['' => ['content' => '', 'etag' => null, 'lastmodified' => 0, 'collection' => true]];

    /** @var bool Ob PUT/PROPFIND ueberhaupt ETags liefern (aus bei IServ). */
    private bool $etagsenabled = true;

    /** @var string|null Normalisierter Pfad, an dem statt des Speicherinhalts das IServ-Bereichsmenue erscheint. */
    private ?string $iservrootpath = null;

    /** @var int Anzahl der verbleibenden gedrosselten Antworten (404 mit HTML), danach Erholung. */
    private int $throttleremaining = 0;

    /** @var bool Ob PUT/MKCOL mit 507 (Speicher voll) antworten. */
    private bool $full = false;

    /** @var int|null 401 oder 403, falls jede Anfrage abgelehnt werden soll (Anmeldung abgelehnt). */
    private ?int $denyauthstatus = null;

    /** @var array{path: string, statuscode: int}|null Naechste Anfrage an diesen Pfad antwortet einmalig mit diesem Status. */
    private ?array $failonce = null;

    /** @var int Fortlaufender Zeitstempel-Takt, damit Aenderungszeiten deterministisch auseinanderliegen. */
    private int $clocktick = 1_700_000_000;

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> Log aller Anfragen, fuer Erwartungen wie "PUT mit If-None-Match: *". */
    private array $log = [];

    /**
     * @param string $secretpassword Das Passwort "der Fake-Instanz" fuer den
     *        Geheimnis-Test - der Fake benutzt es zu nichts, ausser dass ein
     *        Test darauf pruefen kann, dass es in keiner Antwort, Ausnahme
     *        oder Fehlermeldung auftaucht.
     */
    public function __construct(private readonly string $secretpassword = 'g3h31m-nie-sichtbar') {
    }

    /** @return string Siehe Konstruktor. */
    public function secret(): string {
        return $this->secretpassword;
    }

    /** @return array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function requests(): array {
        return $this->log;
    }

    /**
     * Legt eine Datei im Speicher an - Testvorbereitung, kein HTTP.
     *
     * @param string $path z.B. "/ordner/datei.md".
     * @param string $content
     * @return array{etag: ?string, lastmodified: int} Der entstandene Stand, fuer bedingte Folgeaufrufe im Test.
     */
    public function seed_file(string $path, string $content): array {
        $entry = [
            'content' => $content,
            'etag' => $this->etagsenabled ? $this->make_etag($content) : null,
            'lastmodified' => $this->tick(),
            'collection' => false,
        ];
        $this->store[$this->normalise($path)] = $entry;
        return ['etag' => $entry['etag'], 'lastmodified' => $entry['lastmodified']];
    }

    /**
     * @param string $path z.B. "/ordner".
     */
    public function seed_folder(string $path): void {
        $this->store[$this->normalise($path)] = [
            'content' => '',
            'etag' => null,
            'lastmodified' => $this->tick(),
            'collection' => true,
        ];
    }

    /** IServ liefert keine ETags - schwacher `getlastmodified`-Ersatz gilt dort als einzige Vergleichsbasis. */
    public function without_etags(): void {
        $this->etagsenabled = false;
    }

    /**
     * Ab dann zeigt PROPFIND an diesem Pfad das IServ-Bereichsmenue
     * (`Files/`, `Groups/`, `Print/`, `Temp/`, `Windows/`) statt des
     * gespeicherten Inhalts - unabhaengig davon, was dort im Speicher liegt.
     *
     * @param string $path Standard "/", die Wurzel der Instanz.
     */
    public function as_iserv_root(string $path = '/'): void {
        $this->iservrootpath = $this->normalise($path);
    }

    /**
     * Die naechsten `$failures` Anfragen (jedes Verb) antworten mit 404 und
     * HTML-Gastseiten-Rumpf, egal was angefragt wird - danach "erholt" sich
     * der Fake und beantwortet normal.
     *
     * @param int $failures
     */
    public function throttle(int $failures): void {
        $this->throttleremaining = $failures;
    }

    /** Ab dann antworten PUT und MKCOL mit 507 (Speicher voll). */
    public function fill_storage(): void {
        $this->full = true;
    }

    /**
     * Ab dann antwortet jede Anfrage mit `$statuscode` (Anmeldung
     * abgelehnt) - 401 (nicht angemeldet) oder 403 (angemeldet, aber ohne
     * Recht) zaehlen laut Spec §4 gleich.
     *
     * @param int $statuscode 401 oder 403.
     */
    public function deny_auth(int $statuscode = 401): void {
        $this->denyauthstatus = $statuscode;
    }

    /**
     * Genau die naechste Anfrage an `$path` (egal welches Verb) antwortet
     * einmalig mit `$statuscode`, danach wieder normal - anders als
     * {@see deny_auth()}/{@see throttle()}, die jede Anfrage treffen. Damit
     * lassen sich zwei Anfragen in Folge unterscheiden (z.B. eine gelingende
     * Hauptauflistung und eine scheiternde IServ-Erkennung auf der Wurzel).
     *
     * @param string $path z.B. "/Kurspilot".
     * @param int $statuscode
     */
    public function fail_once(string $path, int $statuscode = 401): void {
        $this->failonce = ['path' => $this->normalise($path), 'statuscode' => $statuscode];
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $this->log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->throttleremaining > 0) {
            $this->throttleremaining--;
            return new webdav_response(404, ['content-type' => 'text/html; charset=utf-8'], $this->guest_page_html());
        }
        if ($this->denyauthstatus !== null) {
            return new webdav_response($this->denyauthstatus, [], '');
        }

        $path = $this->normalise($this->path_of($url));
        if ($this->failonce !== null && $this->failonce['path'] === $path) {
            $statuscode = $this->failonce['statuscode'];
            $this->failonce = null;
            return new webdav_response($statuscode, [], '');
        }
        return match ($method) {
            'PROPFIND' => $this->handle_propfind($url, $path, $headers['Depth'] ?? '1'),
            'GET' => $this->handle_get($path),
            'PUT' => $this->handle_put($path, $headers, $body ?? ''),
            'MKCOL' => $this->handle_mkcol($path),
            'MOVE' => $this->handle_move($path, $headers['Destination'] ?? ''),
            'DELETE' => $this->handle_delete($path),
            default => throw new \InvalidArgumentException('Unbekanntes WebDAV-Verb: ' . $method),
        };
    }

    private function handle_propfind(string $requesturl, string $path, string $depth): webdav_response {
        if ($this->iservrootpath !== null && $path === $this->iservrootpath) {
            return $this->iserv_menu_response($requesturl, $path);
        }
        if (!isset($this->store[$path])) {
            return $this->not_found_dav_response();
        }

        $entries = [$path => $this->store[$path]];
        if ($depth === '1' && $this->store[$path]['collection']) {
            foreach ($this->store as $childpath => $entry) {
                if ($childpath !== $path && $this->parent_of($childpath) === $path) {
                    $entries[$childpath] = $entry;
                }
            }
        }
        return new webdav_response(207, ['content-type' => 'application/xml; charset=utf-8'], $this->multistatus_xml($requesturl, $entries));
    }

    private function handle_get(string $path): webdav_response {
        if (!isset($this->store[$path]) || $this->store[$path]['collection']) {
            return $this->not_found_dav_response();
        }
        $entry = $this->store[$path];
        $headers = ['content-type' => 'text/plain; charset=utf-8'];
        if ($entry['etag'] !== null) {
            $headers['etag'] = $entry['etag'];
        }
        return new webdav_response(200, $headers, $entry['content']);
    }

    private function handle_put(string $path, array $headers, string $body): webdav_response {
        if ($this->full) {
            return new webdav_response(507, [], '');
        }

        // Anders als MKCOL prueft PUT nicht, ob der Elternordner existiert -
        // die Basisadresse ist im Betrieb immer ein schon aufgeloester,
        // vorhandener Ort (Spec §2), kein Konstrukt des Fakes.
        $existing = $this->store[$path] ?? null;
        $ifnonematch = $headers['If-None-Match'] ?? null;
        if ($ifnonematch === '*' && $existing !== null) {
            return new webdav_response(412, [], '');
        }
        $ifmatch = $headers['If-Match'] ?? null;
        if ($ifmatch !== null && ($existing === null || $existing['etag'] !== $ifmatch)) {
            return new webdav_response(412, [], '');
        }

        $wasnew = $existing === null;
        $etag = $this->etagsenabled ? $this->make_etag($body) : null;
        $this->store[$path] = [
            'content' => $body,
            'etag' => $etag,
            'lastmodified' => $this->tick(),
            'collection' => false,
        ];
        $responseheaders = $etag !== null ? ['etag' => $etag] : [];
        return new webdav_response($wasnew ? 201 : 204, $responseheaders, '');
    }

    private function handle_mkcol(string $path): webdav_response {
        if ($this->full) {
            return new webdav_response(507, [], '');
        }
        if (isset($this->store[$path])) {
            // Bereits vorhanden - vom Client (mkcol_chain()) als Erfolg behandelt.
            return new webdav_response(405, [], '');
        }
        $parent = $this->parent_of($path);
        if ($parent !== '' && (!isset($this->store[$parent]) || !$this->store[$parent]['collection'])) {
            return new webdav_response(409, [], '');
        }
        $this->store[$path] = ['content' => '', 'etag' => null, 'lastmodified' => $this->tick(), 'collection' => true];
        return new webdav_response(201, [], '');
    }

    private function handle_move(string $path, string $destinationurl): webdav_response {
        if (!isset($this->store[$path])) {
            return $this->not_found_dav_response();
        }
        if ($destinationurl === '') {
            return new webdav_response(409, [], '');
        }
        $destination = $this->normalise($this->path_of($destinationurl));
        $this->store[$destination] = $this->store[$path];
        unset($this->store[$path]);
        return new webdav_response(201, [], '');
    }

    private function handle_delete(string $path): webdav_response {
        if (!isset($this->store[$path])) {
            return $this->not_found_dav_response();
        }
        unset($this->store[$path]);
        return new webdav_response(204, [], '');
    }

    private function not_found_dav_response(): webdav_response {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:error xmlns:d="DAV:"><d:resource-not-found/></d:error>';
        return new webdav_response(404, ['content-type' => 'application/xml; charset=utf-8'], $body);
    }

    private function guest_page_html(): string {
        return '<!doctype html><html><head><title>Anmelden</title></head><body>Gast-Portal, bitte anmelden.</body></html>';
    }

    private function iserv_menu_response(string $requesturl, string $rootpath): webdav_response {
        $areas = ['Files', 'Groups', 'Print', 'Temp', 'Windows'];
        $entries = [$rootpath => ['content' => '', 'etag' => null, 'lastmodified' => $this->clocktick, 'collection' => true]];
        foreach ($areas as $area) {
            $entries[$rootpath . '/' . $area] = ['content' => '', 'etag' => null, 'lastmodified' => $this->clocktick, 'collection' => true];
        }
        return new webdav_response(207, ['content-type' => 'application/xml; charset=utf-8'], $this->multistatus_xml($requesturl, $entries));
    }

    /**
     * @param string $requesturl Fuer den href der aufgeloesten Ebene selbst - der Client vergleicht darauf.
     * @param array<string, array{content: string, etag: ?string, lastmodified: int, collection: bool}> $entries Pfad => Eintrag.
     */
    private function multistatus_xml(string $requesturl, array $entries): string {
        $base = $this->origin($requesturl);
        $xml = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">';
        foreach ($entries as $path => $entry) {
            $href = $base . ($path === '' ? '/' : $this->encode_path($path) . ($entry['collection'] ? '/' : ''));
            $xml .= '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href>';
            $xml .= '<d:propstat><d:prop>';
            $xml .= '<d:resourcetype>' . ($entry['collection'] ? '<d:collection/>' : '') . '</d:resourcetype>';
            if (!$entry['collection']) {
                $xml .= '<d:getcontentlength>' . strlen($entry['content']) . '</d:getcontentlength>';
            }
            $xml .= '<d:getlastmodified>' . gmdate('D, d M Y H:i:s', $entry['lastmodified']) . ' GMT</d:getlastmodified>';
            if ($entry['etag'] !== null) {
                $xml .= '<d:getetag>' . htmlspecialchars($entry['etag'], ENT_XML1) . '</d:getetag>';
            }
            $xml .= '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';
            $xml .= '</d:response>';
        }
        $xml .= '</d:multistatus>';
        return $xml;
    }

    /** @return string Schema+Host der Anfrage-URL, ohne abschliessenden Schraegstrich. */
    private function origin(string $url): string {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($url, PHP_URL_HOST) ?? 'fake.example';
        return $scheme . '://' . $host;
    }

    /** @return string Prozentkodierter Pfad, jedes Segment einzeln (Spec: "liefert prozentkodierte href"). */
    private function encode_path(string $path): string {
        if ($path === '') {
            return '';
        }
        $segments = explode('/', ltrim($path, '/'));
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    private function path_of(string $url): string {
        return (string) (parse_url($url, PHP_URL_PATH) ?? '');
    }

    /** @return string Dekodiert, ohne abschliessenden Schraegstrich, Wurzel als "". */
    private function normalise(string $path): string {
        return rtrim(rawurldecode($path), '/');
    }

    private function parent_of(string $path): string {
        $pos = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    private function make_etag(string $content): string {
        return '"' . substr(sha1($content), 0, 16) . '"';
    }

    private function tick(): int {
        return $this->clocktick++;
    }
}
