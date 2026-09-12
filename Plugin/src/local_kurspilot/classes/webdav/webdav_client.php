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

namespace local_kurspilot\webdav;

/**
 * Der eigene, schlanke WebDAV-Client (Issue #489, Spec #486 §4, ADR 0022).
 * Sechs Verben, benannte Fehlerklassen statt Statuscodes, stille
 * Wiederholung bei `unklar/gedrosselt`, bedingtes Schreiben. Noch an kein
 * Werkzeug angeschlossen - reines Fundament, vollstaendig ueber den
 * austauschbaren {@see webdav_transport} getestet.
 *
 * Die Ortswahl und die Kontextwerkzeuge (spaetere Tickets) benutzen
 * denselben Client, nicht `get_listing()` (Spec §4).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webdav_client {

    /** @var float Hoechste Wiederholungsdauer bei `unklar/gedrosselt` (Spec §4). */
    private const RETRY_BUDGET_SECONDS = 5.0;

    /** @var float Wartezeit zwischen zwei Wiederholungsversuchen. */
    private const RETRY_DELAY_SECONDS = 0.2;

    /** @var int[] HTTP-Status, die als Erfolg gelten, sofern nicht abweichend angegeben. */
    private const DEFAULT_SUCCESS = [200, 201, 204, 207];

    /** @var string Minimaler PROPFIND-Rumpf: alle Eigenschaften. */
    private const PROPFIND_BODY = '<?xml version="1.0" encoding="utf-8"?><propfind xmlns="DAV:"><allprop/></propfind>';

    /**
     * @param webdav_transport $transport Der austauschbare Transport-Seam.
     *        Im Betrieb {@see curl_transport}, im Test der wiederverwendbare
     *        In-Memory-Fake.
     * @param callable $clock () => float, Sekunden seit irgendeinem festen
     *        Nullpunkt. Nur fuer den Wiederholungs-Takt gebraucht - im Test
     *        ersetzbar, damit nicht wirklich gewartet wird.
     * @param callable $sleeper (float $seconds) => void.
     */
    public function __construct(
        private readonly webdav_transport $transport,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /** @var callable */
    private $clock;

    /** @var callable */
    private $sleeper;

    /**
     * Listet eine Ebene (Tiefe 1) oder liest die Eigenschaften einer
     * einzelnen Ressource (Tiefe 0).
     *
     * @param string $url
     * @param int $depth 0 oder 1.
     * @return array<int, array{name: string, type: string, size: int,
     *         timemodified: int, etag: ?string, mimetype: string}>
     * @throws webdav_error
     */
    public function propfind(string $url, int $depth = 1): array {
        if ($depth !== 0 && $depth !== 1) {
            throw new \InvalidArgumentException('PROPFIND unterstuetzt nur Tiefe 0 oder 1.');
        }
        $response = $this->send('PROPFIND', $url, [
            'Depth' => (string) $depth,
            'Content-Type' => 'application/xml',
        ], self::PROPFIND_BODY);
        return $this->parse_multistatus($response->body, $url, $depth);
    }

    /**
     * @param string $url
     * @return string Rumpf der Datei.
     * @throws webdav_error
     */
    public function get(string $url): string {
        return $this->send('GET', $url)->body;
    }

    /**
     * Legt eine neue Datei an - nie ein Ueberschreiben (`If-None-Match: *`).
     *
     * @param string $url
     * @param string $content
     * @throws webdav_error CONFLICT (412), falls dort schon etwas liegt.
     */
    public function put_new(string $url, string $content): void {
        $this->send('PUT', $url, ['If-None-Match' => '*'], $content);
    }

    /**
     * Ueberschreibt eine vorhandene Datei bedingt (Spec §4).
     *
     * Liegt ein ETag vor, traegt `If-Match: <ETag>` den Vergleich - ein
     * server-geprueftes 412 wird zu CONFLICT. Fehlt ein ETag (IServ), dient
     * `getlastmodified` als schwacher, clientseitig gepruefter Ersatz: eine
     * abweichende Aenderungszeit wird lokal zu CONFLICT, *bevor* ueberhaupt
     * geschrieben wird. Verlorene Aktualisierungen sind damit nur schwach
     * erkennbar (Spec §4 nennt das offen).
     *
     * @param string $url
     * @param string $content
     * @param string|null $etag Zuletzt gelesener ETag, falls der Server welche liefert.
     * @param int|null $expectedlastmodified Zuletzt gelesene Aenderungszeit, falls kein ETag vorliegt.
     * @throws webdav_error CONFLICT bei einer erkannten Kollision.
     */
    public function put_overwrite(string $url, string $content, ?string $etag, ?int $expectedlastmodified = null): void {
        $headers = [];
        if ($etag !== null) {
            $headers['If-Match'] = $etag;
        } elseif ($expectedlastmodified !== null) {
            $current = $this->propfind($url, 0);
            $actual = $current[0]['timemodified'] ?? null;
            if ($actual !== $expectedlastmodified) {
                throw new webdav_error(webdav_error::CONFLICT, 'Aenderungszeit weicht vom erwarteten Stand ab (kein ETag).');
            }
        }
        $this->send('PUT', $url, $headers, $content);
    }

    /**
     * Ein einzelnes Verzeichnis. Ein bereits vorhandenes Verzeichnis (405)
     * gilt als Erfolg - {@see mkcol_chain()} baut Ebene fuer Ebene, ohne bei
     * jedem Lauf an einer schon bestehenden Ebene zu scheitern.
     *
     * @param string $url
     * @throws webdav_error
     */
    public function mkcol(string $url): void {
        $this->send('MKCOL', $url, [], null, [201, 405]);
    }

    /**
     * Baut eine Ordnerkette Ebene fuer Ebene per MKCOL (Spec §4).
     *
     * @param string $baseurl Wurzel, ab der die Kette angelegt wird.
     * @param string[] $segments Ordnernamen, unkodiert.
     * @throws webdav_error
     */
    public function mkcol_chain(string $baseurl, array $segments): void {
        $url = rtrim($baseurl, '/') . '/';
        foreach ($segments as $segment) {
            $url .= rawurlencode($segment) . '/';
            $this->mkcol($url);
        }
    }

    /**
     * @param string $sourceurl
     * @param string $destinationurl Vollstaendige Zieladresse.
     * @throws webdav_error
     */
    public function move(string $sourceurl, string $destinationurl): void {
        $this->send('MOVE', $sourceurl, ['Destination' => $destinationurl]);
    }

    /**
     * @param string $url
     * @throws webdav_error
     */
    public function delete(string $url): void {
        $this->send('DELETE', $url);
    }

    /**
     * Der eine Anfrageweg, den sich alle sechs Verben teilen: https-Pflicht,
     * Fehlerklassifizierung, stille Wiederholung bei `unklar/gedrosselt`
     * (auch 429/503), hoechstens {@see RETRY_BUDGET_SECONDS} lang.
     *
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @param int[] $successcodes
     * @return webdav_response
     * @throws webdav_error
     */
    private function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $successcodes = self::DEFAULT_SUCCESS,
    ): webdav_response {
        $this->assert_https($url);
        $start = ($this->clock)();

        while (true) {
            try {
                $response = $this->transport->request($method, $url, $headers, $body);
            } catch (webdav_transport_exception $e) {
                throw new webdav_error(webdav_error::UNREACHABLE, $e->getMessage());
            }

            $errorclass = $this->classify($response, $successcodes);
            if ($errorclass === null) {
                return $response;
            }
            if ($errorclass !== webdav_error::UNCLEAR) {
                throw new webdav_error($errorclass, 'HTTP ' . $response->statuscode);
            }

            $elapsed = ($this->clock)() - $start;
            if ($elapsed >= self::RETRY_BUDGET_SECONDS) {
                throw new webdav_error(webdav_error::UNCLEAR, 'Wiederholung nach ' . self::RETRY_BUDGET_SECONDS . 's aufgegeben.');
            }
            ($this->sleeper)(self::RETRY_DELAY_SECONDS);
        }
    }

    /**
     * @param string $url
     * @throws \InvalidArgumentException
     */
    private function assert_https(string $url): void {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('WebDAV-Client akzeptiert nur https-Adressen.');
        }
    }

    /**
     * @param webdav_response $response
     * @param int[] $successcodes
     * @return string|null Eine {@see webdav_error}-Konstante, oder null bei Erfolg.
     */
    private function classify(webdav_response $response, array $successcodes): ?string {
        $code = $response->statuscode;
        if (in_array($code, $successcodes, true)) {
            return null;
        }
        if ($code === 401 || $code === 403) {
            return webdav_error::AUTH_REJECTED;
        }
        if ($code === 507) {
            return webdav_error::STORAGE_FULL;
        }
        if ($code === 409 || $code === 412) {
            return webdav_error::CONFLICT;
        }
        if ($code === 404) {
            return $this->is_dav_xml_body($response) ? webdav_error::NOT_FOUND : webdav_error::UNCLEAR;
        }
        if ($code >= 300 && $code < 400) {
            // Der Transport folgt keiner Weiterleitung (curl_transport::transport_options()) -
            // eine 3xx-Antwort ist deshalb ein benannter Fehler, nie still wiederholbar: sonst
            // koennten Anmeldedaten an eine vom Server bestimmte, moeglicherweise unverschluesselte
            // Adresse gelangen (Issue #510).
            return webdav_error::REDIRECTED;
        }
        // 429, 503 und jeder andere nicht benannte Status: still wiederholbar, nie stillschweigend Erfolg.
        return webdav_error::UNCLEAR;
    }

    /**
     * Unterscheidet ein echtes DAV-404 (XML-Rumpf) von einer gedrosselten
     * HTML-Gastseite, die ebenfalls mit 404 antwortet (Spec §4).
     *
     * @param webdav_response $response
     * @return bool
     */
    private function is_dav_xml_body(webdav_response $response): bool {
        if (stripos($response->header('content-type') ?? '', 'xml') !== false) {
            return true;
        }
        $trimmed = ltrim($response->body);
        return str_starts_with($trimmed, '<?xml') || stripos($trimmed, 'DAV:') !== false;
    }

    /**
     * @param string $url
     * @return string Dekodierter Pfadanteil, ohne abschliessenden Schraegstrich.
     */
    private function normalised_path(string $url): string {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        return rtrim(rawurldecode($path), '/');
    }

    /**
     * Wertet einen PROPFIND-Multistatus-Rumpf aus (Spec §4): Name
     * (prozentdekodiert), Typ, Groesse, Aenderungszeit und ETag falls
     * vorhanden. Der MIME-Typ kommt aus der Endung ({@see mimeinfo()}).
     *
     * @param string $body
     * @param string $requesturl
     * @param int $depth
     * @return array<int, array{name: string, type: string, size: int,
     *         timemodified: int, etag: ?string, mimetype: string}>
     * @throws webdav_error UNCLEAR, wenn der Rumpf trotz 2xx-Status nicht als
     *         XML lesbar ist - nie stillschweigend ein leerer Ordner (Issue #510),
     *         sonst entfallen Uebergabe-Hinweis und Altbestand-Erkennung.
     */
    private function parse_multistatus(string $body, string $requesturl, int $depth): array {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: kein Netzzugriff beim Parsen, auch nicht fuer eine im
        // Rumpf verlinkte externe DTD/Entity (Issue #510).
        $sxe = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($sxe === false) {
            throw new webdav_error(webdav_error::UNCLEAR, 'PROPFIND-Rumpf trotz Erfolgsstatus nicht als XML lesbar.');
        }

        $requestpath = $this->normalised_path($requesturl);
        $entries = [];
        foreach ($sxe->children('DAV:')->response as $responsenode) {
            $davresponse = $responsenode->children('DAV:');
            $path = $this->normalised_path((string) $davresponse->href);
            if ($depth === 1 && $path === $requestpath) {
                // Die aufgeloeste Ebene selbst, nicht ihr Inhalt.
                continue;
            }

            $prop = $this->successful_prop($davresponse);
            if ($prop === null) {
                continue;
            }

            $name = basename($path);
            if ($name === '') {
                continue;
            }

            $iscollection = isset($prop->resourcetype->children('DAV:')->collection);
            $lastmodifiedraw = (string) $prop->getlastmodified;
            $etagraw = (string) $prop->getetag;

            $entries[] = [
                'name' => $name,
                'type' => $iscollection ? 'folder' : 'file',
                'size' => $iscollection ? 0 : (int) (string) $prop->getcontentlength,
                'timemodified' => $lastmodifiedraw !== '' ? (int) strtotime($lastmodifiedraw) : 0,
                'etag' => $etagraw !== '' ? $etagraw : null,
                'mimetype' => $iscollection ? '' : mimeinfo('type', $name),
            ];
        }
        return $entries;
    }

    /**
     * Der erste `propstat` mit Status 200 eines `response`-Knotens, oder
     * null, wenn keiner erfolgreich war (z.B. eine Eigenschaft, die der
     * Server fuer diesen Eintrag nicht kennt).
     *
     * @param \SimpleXMLElement $davresponse
     * @return \SimpleXMLElement|null
     */
    private function successful_prop(\SimpleXMLElement $davresponse): ?\SimpleXMLElement {
        foreach ($davresponse->propstat as $propstat) {
            $psdav = $propstat->children('DAV:');
            $status = (string) $psdav->status;
            if ($status === '' || str_contains($status, ' 200 ')) {
                return $psdav->prop->children('DAV:');
            }
        }
        return null;
    }
}
