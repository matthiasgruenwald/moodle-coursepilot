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
use local_coursepilot\webdav\webdav_client;
use local_coursepilot\webdav\webdav_error;
use local_coursepilot\webdav\webdav_instance;
use local_coursepilot\webdav\webdav_transport;

/**
 * Zweiter Adapter des Ablage-Vertrags (Issue #537, Spec 0021): WebDAV.
 * Erfuellt {@see storage_port} vollstaendig ueber eine bereits benannte
 * WebDAV-Nutzerinstanz und einen relativen Basisordner darin - beide werden
 * dem Konstruktor uebergeben, nicht aus dem Kontextpointer gelesen. Welcher
 * Adapter fuer einen Bereich greift und wie die Instanz-ID/der Basisordner
 * aus dem Pointer aufgeloest werden, entscheidet ein spaeteres Ticket, nicht
 * dieser Adapter selbst - genau wie {@see private_files_storage_port} noch
 * kein Werkzeug kennt.
 *
 * Die Pruefung "Repository-Instanz gehoert dem Token-Inhaber" (ADR 0021)
 * liegt an genau einer Stelle: {@see webdav_instance::resolve_owned()}, die
 * dieser Adapter fuer jede Operation neu aufruft - Zugangsdaten werden dabei
 * frisch gelesen, nie zwischengespeichert. Die Hostsperre des Kerns
 * ({@see \curl_transport}, im Betrieb hinter {@see webdav_instance}) bleibt
 * unveraendert: dieser Adapter ersetzt nirgends den Transport selbst, nur
 * ueber den bereits bestehenden Testhaken {@see webdav_instance::set_transport()}.
 *
 * Der Pruefwert dieses Adapters ist ein aus ETag/Aenderungszeit gebildeter
 * Hash ({@see pointer_reader::external_checkvalue()}) - WebDAV kennt keinen
 * Moodle-`contenthash`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_storage_port implements storage_port {

    /**
     * Liest eine Ebene fuer die Ortswahl. Das bleibt beim WebDAV-Adapter:
     * die Seite bewertet nur die zurueckgegebenen Eintraege als Auswahl.
     *
     * @param int $instanceid
     * @param string $path Relativ zur Instanzwurzel.
     * @return array{entries: array<int, array{name: string, type: string}>, iserv: bool}
     * @throws webdav_error
     */
    public static function browse_location(int $instanceid, string $path): array {
        $instance = webdav_instance::resolve_owned($instanceid);
        try {
            $entries = $instance->client()->propfind($instance->directory_url($path), 1);
        } catch (webdav_error $e) {
            $entries = webdav_error::empty_when_missing($e, [], static fn (webdav_error $error): webdav_error => $error);
        }
        if ($path === '') {
            return ['entries' => $entries, 'iserv' => webdav_instance::is_iserv_listing($entries)];
        }
        try {
            $root = $instance->client()->propfind($instance->directory_url(''), 1);
            $iserv = webdav_instance::is_iserv_listing($root);
        } catch (webdav_error $e) {
            access_log::log_failure('WebDAV ' . $e->errorclass . ' bei IServ-Erkennung: ' . $e->getMessage());
            $iserv = false;
        }
        return ['entries' => $entries, 'iserv' => $iserv];
    }

    /**
     * @var string[] moodle_exception-Fehlerschluessel aus
     *      {@see webdav_instance::resolve_owned()}, die genauso einen
     *      Ausstand anlegen wie ein {@see webdav_error} (Issue #540, ADR
     *      0023) - kein Pruefmerkmal-/Wurzel-/IServ-Check hier (dieser
     *      Adapter kennt keinen Kontextpointer), deshalb kuerzer als
     *      {@see pointer_writer}'s LOCATION_FAILURE_CODES.
     */
    private const LOCATION_FAILURE_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
    ];

    /**
     * @param int $instanceid Die WebDAV-Nutzerinstanz, ausschliesslich aus
     *        einer serverseitigen Quelle (nie aus einer Client-Eingabe) -
     *        Instanzeigentum prueft {@see webdav_instance::resolve_owned()}.
     * @param string $baserelativepath Basisordner innerhalb der Instanz, in
     *        dem dieser Adapter arbeitet. Leer heisst: die Instanzwurzel.
     */
    public function __construct(
        private readonly int $instanceid,
        private readonly string $baserelativepath = '',
        private readonly ?webdav_transport $transport = null,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(storage_area $area, string $path): ?array {
        [$folders, $filename] = $this->split_file_path($area, $path);
        $resolved = $this->resolved_instance();
        $client = $resolved->client();
        $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, null, static fn (webdav_error $err): webdav_error => $err);
        }

        $entry = $meta[0] ?? null;
        if ($entry === null || $entry['type'] === 'folder') {
            // Vertrag (storage_port::read()): null bei einer fehlenden Datei
            // *oder einem Ordner* - kein GET auf eine Collection.
            return null;
        }

        $content = $client->get($fileurl);
        $mimetype = $entry['mimetype'];
        if ($mimetype === 'document/unknown') {
            // Ortsneutralitaet (Issue #560): Moodle-Core sniffft bei
            // unbekannter Endung ebenfalls den Inhalt. Kein zusaetzlicher
            // GET hier - der Inhalt liegt bereits vor.
            $mimetype = webdav_client::sniff_mimetype_from_content($content) ?? $mimetype;
        }
        return [
            'content' => $content,
            'checksum' => pointer_reader::external_checkvalue($entry['etag'] ?? null, $entry['timemodified'] ?? 0),
            'size' => $entry['size'],
            'mimetype' => $mimetype,
            'timemodified' => $entry['timemodified'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function list(storage_area $area, string $path): array {
        $clientdirectory = storage_anchor::normalise_client_path($area, $path);
        $resolved = $this->resolved_instance();
        $directorysegments = $clientdirectory === '' ? [] : explode('/', $clientdirectory);
        $directoryurl = $resolved->directory_url(implode('/', $this->relative_segments($directorysegments)));

        try {
            $raw = $resolved->client()->propfind($directoryurl, 1);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, [], static fn (webdav_error $err): webdav_error => $err);
        }

        return array_map(static fn (array $entry): array => [
            'name' => $entry['name'],
            'type' => $entry['type'],
            'size' => $entry['size'],
            'mimetype' => $entry['mimetype'],
            'checksum' => pointer_reader::external_checkvalue($entry['etag'], $entry['timemodified']),
            'timemodified' => $entry['timemodified'],
        ], $raw);
    }

    /**
     * @inheritDoc
     */
    public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = implode('/', [...$folders, $filename]);
        $operation = pending_write_translation::OP_CREATE;

        try {
            $resolved = $this->resolved_instance();
            $client = $resolved->client();
            $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

            $existing = $this->current_entry($client, $fileurl);
            $this->require_checksum_match($existing, $expectedchecksum, $clientpath);
            storage_anchor::require_quota($area, strlen($content) - ($existing['size'] ?? 0));
            if ($existing !== null) {
                $operation = pending_write_translation::OP_OVERWRITE;
            }

            $this->ensure_directory($resolved, $folders);
            $this->put($client, $fileurl, $content, $existing, $clientpath);

            $written = $this->current_entry($client, $fileurl);
        } catch (webdav_error $e) {
            throw $this->fail($e->errorclass, $e->getMessage(), $clientpath, $operation);
        } catch (\moodle_exception $e) {
            throw $this->translate_location_failure($e, $clientpath, $operation);
        }

        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $written['size'] ?? strlen($content),
            'checksum' => pointer_reader::external_checkvalue($written['etag'] ?? null, $written['timemodified'] ?? 0),
        ];
    }

    /**
     * @inheritDoc
     */
    public function append(storage_area $area, string $path, string $content): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = implode('/', [...$folders, $filename]);

        try {
            $resolved = $this->resolved_instance();
            $client = $resolved->client();
            $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

            $existing = $this->current_entry($client, $fileurl);
            storage_anchor::require_quota($area, strlen($content));
            $this->ensure_directory($resolved, $folders);

            $newcontent = $existing === null ? $content : ($client->get($fileurl) . $content);
            $this->put($client, $fileurl, $newcontent, $existing, $clientpath);

            $written = $this->current_entry($client, $fileurl);
        } catch (webdav_error $e) {
            throw $this->fail($e->errorclass, $e->getMessage(), $clientpath, pending_write_translation::OP_APPEND);
        } catch (\moodle_exception $e) {
            throw $this->translate_location_failure($e, $clientpath, pending_write_translation::OP_APPEND);
        }

        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => strlen($newcontent),
            'checksum' => pointer_reader::external_checkvalue($written['etag'] ?? null, $written['timemodified'] ?? 0),
        ];
    }

    /**
     * Uebersetzt einen Ausfall beim Schreiben/Anhaengen (Issue #540, ADR
     * 0023) genauso wie {@see pointer_writer}: vermerkt einen Ausstand, bevor
     * der Fehler zurueckgeht - nie roh durchgereicht. `Konflikt` (412) ist
     * hier bereits als {@see storage_conflict_exception} unterwegs (siehe
     * {@see put()}), erreicht diese Methode also nie.
     *
     * @param string $errorclass
     * @param string $rawmessage
     * @param string $clientpath
     * @param string $operation Eine der {@see pending_write_translation}-OP_*-Konstanten.
     * @return \moodle_exception
     */
    private function fail(string $errorclass, string $rawmessage, string $clientpath, string $operation): \moodle_exception {
        return pending_write_translation::record_and_translate(
            $errorclass,
            'WebDAV ' . $errorclass . ': ' . $rawmessage,
            $clientpath,
            $operation,
            pointer_writer::reason_for($errorclass),
            pointer_writer::describe_target($this->resolve_host(), $this->instanceid),
            0
        );
    }

    /**
     * Ort-Ausfaelle aus {@see resolved_instance()} legen ebenfalls einen
     * Ausstand an (Issue #540); jeder andere moodle_exception-Fehlerschluessel
     * - insbesondere {@see storage_conflict_exception} und die Quotenpruefung
     * des Bereichs - laeuft unveraendert weiter, er gehoert nicht zu diesem
     * Zweig.
     *
     * @param \moodle_exception $e
     * @param string $clientpath
     * @param string $operation
     * @return \moodle_exception
     */
    private function translate_location_failure(\moodle_exception $e, string $clientpath, string $operation): \moodle_exception {
        if ($e instanceof storage_conflict_exception || !in_array($e->errorcode, self::LOCATION_FAILURE_CODES, true)) {
            return $e;
        }
        return pending_write_translation::record_and_translate(
            $e->errorcode,
            'WebDAV ' . $e->errorcode . ': ' . $e->getMessage(),
            $clientpath,
            $operation,
            pointer_writer::reason_for($e->errorcode),
            // Instanz nicht mehr aufloesbar - kein frischer Host verfuegbar,
            // anders als bei pointer_writer, der den Host aus dem Pointer-
            // Pruefmerkmal kennt (dieser Adapter kennt keinen Pointer).
            pointer_writer::describe_target('', $this->instanceid),
            0
        );
    }

    /**
     * Der Host der Instanz, best-effort - fuer die Zielbeschreibung der
     * Ausfallantwort. Leer, wenn die Instanz selbst nicht mehr aufloesbar ist
     * (dann greift ohnehin {@see translate_location_failure()}, nicht diese
     * Methode).
     *
     * @return string
     */
    private function resolve_host(): string {
        try {
            return (string) (webdav_instance::fingerprint_of($this->instanceid)['server'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @inheritDoc
     */
    public function delete(storage_area $area, string $path): bool {
        [$folders, $filename] = $this->split_file_path($area, $path);
        $resolved = $this->resolved_instance();
        $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

        try {
            $resolved->client()->delete($fileurl);
            return true;
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, false, static fn (webdav_error $err): webdav_error => $err);
        }
    }

    /**
     * Loest die Instanz frisch auf - Instanzeigentum (ADR 0021), WebDAV-
     * Freischaltung und https+Basic prueft ausschliesslich
     * {@see webdav_instance::resolve_owned()}, hier fuer jede Operation neu
     * aufgerufen, damit Zugangsdaten nie zwischengespeichert werden.
     *
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/
     *         webdavnotenabled/webdavauthunsupported
     */
    private function resolved_instance(): resolved_webdav_instance {
        return webdav_instance::resolve_owned($this->instanceid, $this->transport);
    }

    /**
     * Zerlegt einen Client-Pfad grosszuegig (nur `.`/`..`-Segmente
     * verboten, keine Namensregel) - fuer Lesen/Loeschen, analog zu
     * {@see storage_anchor::resolve_file()}.
     *
     * @param storage_area $area
     * @param string $path
     * @return array{0: string[], 1: string}
     * @throws \moodle_exception invalidpathkey des Bereichs
     */
    private function split_file_path(storage_area $area, string $path): array {
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        if ($clientpath === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $segments = explode('/', $clientpath);
        $filename = array_pop($segments);
        return [$segments, $filename];
    }

    /**
     * @return string[] Segmente des Basisordners, ohne leere Anteile.
     */
    private function base_segments(): array {
        return array_values(array_filter(
            explode('/', trim($this->baserelativepath, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));
    }

    /**
     * @param string[] $extra
     * @return string[] Basisordner-Segmente gefolgt von $extra.
     */
    private function relative_segments(array $extra): array {
        return [...$this->base_segments(), ...$extra];
    }

    /**
     * @param string[] $folders
     * @param string $filename
     * @return string Voller instanzrelativer Pfad einer Datei.
     */
    private function relative_path(array $folders, string $filename): string {
        return implode('/', [...$this->relative_segments($folders), $filename]);
    }

    /**
     * Baut fehlende Unterordner - Basisordner und vom Aufrufer gewuenschte
     * Ordner gleichermassen - per MKCOL Ebene fuer Ebene. Ein bereits
     * vorhandenes Verzeichnis gilt als Erfolg ({@see webdav_client::mkcol()}).
     *
     * @param resolved_webdav_instance $resolved
     * @param string[] $folders
     * @throws webdav_error
     */
    private function ensure_directory(resolved_webdav_instance $resolved, array $folders): void {
        $segments = $this->relative_segments($folders);
        if (empty($segments)) {
            return;
        }
        $resolved->client()->mkcol_chain($resolved->directory_url(''), $segments);
    }

    /**
     * Die aktuellen Eigenschaften der Zieldatei, oder null, wenn sie fehlt.
     *
     * @param webdav_client $client
     * @param string $fileurl
     * @return array{etag: ?string, timemodified: int, size: int}|null
     * @throws webdav_error jeder Fehler ausser "nicht gefunden".
     */
    private function current_entry(webdav_client $client, string $fileurl): ?array {
        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, null, static fn (webdav_error $err): webdav_error => $err);
        }
        $entry = $meta[0] ?? null;
        if ($entry === null) {
            return null;
        }
        return ['etag' => $entry['etag'], 'timemodified' => $entry['timemodified'], 'size' => $entry['size']];
    }

    /**
     * Legt eine Datei an (`existing === null`) oder ueberschreibt sie
     * bedingt - ein transportnaher Konflikt (412) wird zum ortsneutralen
     * {@see storage_conflict_exception}, damit der Aufrufer nie einen
     * webdav_error als Konflikt sieht.
     *
     * @param webdav_client $client
     * @param string $fileurl
     * @param string $content
     * @param array{etag: ?string, timemodified: int, size: int}|null $existing
     * @param string $clientpath Fuer die Fehlermeldung.
     * @throws storage_conflict_exception
     * @throws webdav_error jeder andere Fehler.
     */
    private function put(webdav_client $client, string $fileurl, string $content, ?array $existing, string $clientpath): void {
        try {
            if ($existing === null) {
                $client->put_new($fileurl, $content);
            } else {
                $client->put_overwrite($fileurl, $content, $existing['etag'], $existing['timemodified']);
            }
        } catch (webdav_error $e) {
            if ($e->errorclass === webdav_error::CONFLICT) {
                throw new storage_conflict_exception($clientpath);
            }
            throw $e;
        }
    }

    /**
     * Weist ein bedingtes Schreiben ab, dessen Pruefwert nicht (mehr) zum
     * aktuellen Stand passt - auch wenn die Datei inzwischen ganz fehlt.
     * Kein Vergleich, wenn kein Pruefwert mitgegeben wurde (`null`).
     *
     * @param array{etag: ?string, timemodified: int, size: int}|null $existing
     * @param string|null $expectedchecksum
     * @param string $clientpath Fuer die Fehlermeldung.
     * @throws storage_conflict_exception
     */
    private function require_checksum_match(?array $existing, ?string $expectedchecksum, string $clientpath): void {
        if ($expectedchecksum === null) {
            return;
        }
        $actual = $existing !== null
            ? pointer_reader::external_checkvalue($existing['etag'], $existing['timemodified'])
            : null;
        if ($actual !== $expectedchecksum) {
            throw new storage_conflict_exception($clientpath);
        }
    }
}
