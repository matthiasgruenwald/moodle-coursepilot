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

use local_coursepilot\webdav\webdav_error;

/**
 * Die vier Kontextbereich-Werkzeugoperationen (lesen, schreiben, anhaengen,
 * auflisten), ortsneutral fuer den Aufrufer (Issue #538, Spec 0021): kein
 * Werkzeug in {@see \local_coursepilot\external\write_context_file} und
 * seinen drei Geschwistern verzweigt mehr selbst auf Ortsart, Ortsfehler-
 * schluessel oder ein "etag"-Sonderfeld - diese Entscheidungen wandern hier
 * herein.
 *
 * Von {@see context_files} getrennt gehalten (die Klasse naeherte sich sonst
 * der 800-Zeilen-Grenze aus den Coding-Standards) - dasselbe Muster, mit dem
 * {@see pointer_reader}/{@see pointer_writer} bereits von {@see storage_anchor}
 * getrennt wurden. Anders als jene beiden bleibt diese Klasse bewusst auf den
 * Kontextbereich fixiert (`context_files::area()`), statt einen Bereich als
 * Parameter zu nehmen - noch kein zweiter Aufrufer braucht das (YAGNI, ADR
 * 0020).
 *
 * Fuer Private Files laeuft die eigentliche Ablage seit Issue #538 ueber den
 * {@see storage_port}-Adapter {@see private_files_storage_port} - fuer den
 * externen Ort unveraendert ueber {@see pointer_writer}/{@see pointer_reader}.
 * Die Ausfallbehandlung (ADR 0023, "Ausstandsnotiz und Nachtragen an beiden
 * Orten") gilt seit Issue #540 fuer beide Zweige: extern weiterhin in
 * {@see pointer_writer}, fuer Private Files hier selbst
 * ({@see write_moodle()}/{@see append_moodle()}, ueber
 * {@see pending_write_translation}, die dieselbe fuenfteilige Ausfallantwort
 * ortsneutral baut).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_area {

    /**
     * Liest eine Kontextdatei zeigerbewusst und ortsneutral - wie
     * {@see context_files::read_content_pointer_aware()}, aber ohne das nur
     * intern gebrauchte "etag"-Feld: der Pruefwert steht bereits als
     * "contenthash" bereit, gleich ob Moodle- oder externer Ort. Der einzige
     * Aufrufer, der diese Unterscheidung noch braucht, ist diese Klasse
     * selbst - kein Werkzeug sieht sie mehr.
     *
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read(string $path): ?array {
        try {
            $file = storage_anchor::port(context_files::area())->read(context_files::area(), $path);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e);
        }
        return $file === null ? null : [
            'path' => storage_anchor::normalise_client_path(context_files::area(), $path),
            'content' => $file['content'],
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'contenthash' => $file['checksum'],
            'timemodified' => $file['timemodified'],
        ];
    }

    /**
     * Wie {@see read()}, aber fuer den vorherigen Ort (Issue #498) - siehe
     * {@see context_files::read_content_previous_location()}.
     *
     * @param string $path
     * @param pointer_location $location
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read_previous_location(string $path, pointer_location $location): ?array {
        return self::normalise_pointer_result(context_files::read_content_previous_location($path, $location));
    }

    /**
     * Ersetzt ein nur intern gefuehrtes "etag"-Feld (Kontextbereich-Pruefwert,
     * Issue #513) durch den bereits nach aussen gedachten "contenthash" -
     * ortsneutral: ein Moodle-Ergebnis (kein "etag"-Schluessel) bleibt
     * unangetastet.
     *
     * @param array|null $file
     * @return array|null
     */
    private static function normalise_pointer_result(?array $file): ?array {
        if ($file === null || !array_key_exists('etag', $file)) {
            return $file;
        }
        $file['contenthash'] = pointer_reader::external_checkvalue($file['etag'], $file['timemodified']);
        unset($file['etag']);
        return $file;
    }

    /**
     * Listet eine Ebene des Kontextbereichs zeigerbewusst und ortsneutral:
     * derselbe Feldsatz fuer beide Orte, "locked" bereits ausgewertet - kein
     * Werkzeug muss dafuer noch selbst zwischen Moodle und extern
     * unterscheiden.
     *
     * @param string $path
     * @param bool $previouslocation Siehe {@see context_files::list_entries_previous_location()}.
     * @return array{directory: string, entries: array}
     */
    public static function list(string $path, bool $previouslocation = false): array {
        if ($previouslocation) {
            $result = context_files::list_entries_previous_location($path, previous_location::require_open_location());
        } else {
            try {
                $result = [
                    'directory' => storage_anchor::normalise_client_path(context_files::area(), $path),
                    'entries' => storage_anchor::port(context_files::area())->list(context_files::area(), $path),
                ];
            } catch (webdav_error $e) {
                throw pointer_reader::webdav_exception($e);
            }
        }

        return [
            'directory' => $result['directory'],
            'entries' => array_map(
                static fn (array $entry): array => self::annotate_entry($entry, $result['directory']),
                $result['entries']
            ),
        ];
    }

    /**
     * Ergaenzt einen Auflistungs-Eintrag um "locked" und einen ortsneutralen
     * "contenthash" - relocated aus
     * {@see \local_coursepilot\external\list_context_files} (Issue #538),
     * damit das Werkzeug selbst keine Ortsverzweigung mehr braucht.
     *
     * @param array $entry Ein Eintrag aus {@see context_files::list_entries_pointer_aware()}
     *        (traegt noch das interne "etag"-Feld, nur extern).
     * @param string $directory Ergebnis-Ordner, siehe {@see list()}.
     * @return array Derselbe Eintrag ohne "etag", mit "locked" und (extern,
     *         Dateien) einem gefuellten "contenthash".
     */
    private static function annotate_entry(array $entry, string $directory): array {
        $etag = $entry['etag'] ?? null;
        $isexternal = array_key_exists('etag', $entry);
        unset($entry['etag']);

        if (array_key_exists('checksum', $entry)) {
            $entry['contenthash'] = $entry['checksum'];
            unset($entry['checksum']);
        }

        if ($entry['type'] === 'folder') {
            return $entry + ['locked' => false];
        }

        if ($isexternal) {
            $entry['contenthash'] = pointer_reader::external_checkvalue($etag, $entry['timemodified']);
        }

        // Nur .md-Dateien tragen ueberhaupt eine Personenbezugs-Markierung
        // (Frontmatter) - siehe die urspruengliche Begruendung in
        // list_context_files::annotate_locked() (Issue #506/#493).
        $ismarkdown = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'md';
        if (!$ismarkdown || personal_data::allowed()) {
            return $entry + ['locked' => false];
        }

        return $entry + ['locked' => self::is_entry_marked($entry, $directory, $etag)];
    }

    /**
     * Prueft (mit Markierungsgedaechtnis) ob eine .md-Datei personenbezogen
     * markiert ist - relocated aus list_context_files::is_marked_cached()
     * (Issue #538).
     *
     * @param array $entry
     * @param string $directory
     * @param string|null $etag
     * @return bool
     */
    private static function is_entry_marked(array $entry, string $directory, ?string $etag): bool {
        $relativepath = $directory === '' ? $entry['name'] : $directory . '/' . $entry['name'];

        $marked = mark_memory::lookup($relativepath, $entry['size'], $entry['timemodified'], $etag);
        if ($marked === null) {
            $content = context_files::read_content_pointer_aware($relativepath);
            $marked = $content !== null && personal_data::is_marked($content['content']);
            mark_memory::remember($relativepath, $entry['size'], $entry['timemodified'], $etag, $marked);
        }

        return $marked;
    }

    /**
     * Schreibt eine Kontextdatei zeigerbewusst und ortsneutral: entscheidet
     * intern, ob Private Files (ueber den {@see storage_port}-Adapter
     * {@see private_files_storage_port}) oder der externe Ort
     * ({@see pointer_writer}) greift - kein Werkzeug aussen sieht diese
     * Entscheidung mehr, und Private Files laufen jetzt ueber denselben
     * Vertrag wie der externe Ort.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Siehe {@see context_files::write_pointer_aware()}.
     * @param string $ausstand Siehe {@see context_files::write_pointer_aware()}.
     * @param bool $createonly Siehe {@see context_files::write_pointer_aware()}.
     * @param int $courseid Siehe {@see context_files::write_pointer_aware()}.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     */
    public static function write(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $ausstand = '',
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        try {
            $location = storage_anchor::effective_location($area);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            if (personal_data::is_marked($content) && !personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_CREATE, $courseid);
        }
        if ($location->kind === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }
        $port = storage_anchor::port($area, $courseid);
        try {
            $existing = $port->read($area, $path);
        } catch (webdav_error $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, pointer_writer::OP_UNKNOWN, $courseid);
        } catch (\moodle_exception $e) {
            throw pointer_writer::record_location_failure($e, $path, $location, pointer_writer::OP_CREATE, $courseid);
        }
        if ($existing !== null && $createonly) {
            throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
        }
        self::guard_existing_locked($existing, $path);
        if (personal_data::is_marked($content)) {
            if (!personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            personal_data_hosts::require_allowed_location($location, $path);
        }
        if ($ausstand !== '' && $existing !== null && $expectedcontenthash === '') {
            throw new storage_conflict_exception($path);
        }
        // Reuse the preflight read as the write condition. This closes the
        // read-write window without exposing location-specific concurrency.
        $checksum = $expectedcontenthash !== '' ? $expectedcontenthash : ($existing['checksum'] ?? storage_port::MISSING_CHECKSUM);
        $written = $port->write($area, $path, $content, $checksum);
        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $existing['size'] ?? 0,
        ];
    }

    /**
     * Loest den Kontextpointer auf (Sonderfall Pruefung 8/IServ eingerechnet)
     * und wendet den Personenbezugs-Gate an - relocated aus
     * {@see \local_coursepilot\external\write_context_file::dispatch()}
     * (Issue #538). Gibt den aufgeloesten externen Ort zurueck, oder `null`
     * fuer Private Files.
     *
     * Uebersetzt einen Ausfall bei Pruefung 8 (IServ) bereits hier, statt ihn
     * wie vor Issue #541 ein zweites Mal in {@see pointer_writer::write()}
     * entstehen zu lassen, nur um ihn dort zu uebersetzen - {@see pointer_writer}
     * loest den Pointer seit Issue #541 nicht mehr selbst auf.
     *
     * Validiert dabei zuerst den Pfad ({@see storage_anchor::writable_segments()}),
     * genau wie vormals {@see pointer_writer::write()} es vor seiner eigenen
     * (zweiten) Pointer-Aufloesung tat (Code-Review zu Issue #541): ein
     * ungueltiger Pfad bleibt ein Aufruffehler (`invalidcontextpath`/
     * `contextfilenotmarkdown`), auch bei Pruefung 8 - kein Ausstand fuer
     * etwas, das nie hätte geschrieben werden koennen.
     *
     * @param string $content
     * @param string $path
     * @param bool $createonly
     * @param int $courseid
     * @return pointer_location|null Der externe Ort, wenn der externe Zweig
     *         greift, sonst `null` fuer Private Files.
     */
    private static function resolve_write_target(string $content, string $path, bool $createonly, int $courseid): ?pointer_location {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'webdaviservfilesonly') {
                storage_anchor::writable_segments(context_files::area(), $path);
                self::guard_personal_data_for_write($content, null, $path, $createonly, $courseid);
                throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_CREATE, $courseid);
            }
            throw $e;
        }
        self::guard_personal_data_for_write($content, $location, $path, $createonly, $courseid);

        return ($location !== null && $location->kind === pointer_location::EXTERN) ? $location : null;
    }

    /**
     * Personenbezugs-Gate vor dem Schreiben - relocated aus
     * write_context_file::require_personal_data_allowed() (Issue #538),
     * unveraendertes Verhalten.
     *
     * @param string $content
     * @param pointer_location|null $location
     * @param string $path
     * @param bool $createonly
     * @param int $courseid
     * @throws \moodle_exception contextfilelocked, contextfilealreadyexists, ausstandwritefailed
     */
    private static function guard_personal_data_for_write(
        string $content,
        ?pointer_location $location,
        string $path,
        bool $createonly,
        int $courseid = 0
    ): void {
        if ($location !== null && $location->kind === pointer_location::EXTERN && !personal_data::allowed()) {
            try {
                $existing = pointer_reader::peek_external_content(context_files::area(), $path, $location);
            } catch (webdav_error $e) {
                // Der Vorab-Lese-Check selbst ist gescheitert (Issue #561):
                // ob am Ort schon etwas lag, ist damit unbekannt - nie
                // binaer aus $createonly ableiten, das waere fuer den
                // Regelfall (kein nur_anlegen) immer "ueberschreiben",
                // selbst wenn dort noch nie etwas lag.
                throw pointer_writer::record_preread_failure(
                    $e,
                    $location,
                    $path,
                    pointer_writer::OP_UNKNOWN,
                    $courseid
                );
            }
            if ($existing !== null && $createonly) {
                throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
            }
            if ($existing !== null && personal_data::is_marked($existing)) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
        }

        if (!personal_data::is_marked($content)) {
            return;
        }
        if (!personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
        personal_data_hosts::require_allowed_location($location, $path);
    }

    /**
     * Der Moodle-Zweig von {@see write()} - laeuft ueber
     * {@see private_files_storage_port} statt ueber eine eigene
     * Schreibchoreografie (Issue #538, Spec 0021 Abnahmekriterium "Auch das
     * Schreiben in Private Files laeuft ueber den Anker").
     *
     * Seit Issue #540 (Spec 0021, ADR 0023 "an beiden Orten") symmetrisch zum
     * externen Zweig ({@see pointer_writer::write()}): ein Nachtragen
     * (`$requirecheckvalue`, `ausstand=`) ueberschreibt eine bereits
     * vorhandene Zieldatei nie ungeprueft, und ein echter Ausfall beim
     * Persistieren selbst (nicht: Pfad-/Endungs-/Quotenpruefung, nicht: der
     * hier bereits behandelte Pruefwert-Konflikt) vermerkt einen Ausstand,
     * bevor der Fehler zurueckgeht - siehe {@see persist_moodle_write()}.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @param bool $requirecheckvalue Nachtragen (`ausstand=`) - siehe
     *        {@see pointer_writer::write()}: eine bereits vorhandene
     *        Zieldatei ohne mitgegebenen Pruefwert gilt dann selbst als
     *        Konflikt, statt gewachsenen Bestand ungeprueft zu ersetzen.
     * @param bool $createonly
     * @param int $courseid Kurs-ID, nur fuer einen etwaigen Eintrag der
     *        Ausstandsnotiz - 0, wenn der Aufruf keinem Kurs zugeordnet ist.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     * @throws \moodle_exception contextfilealreadyexists, contextfilelocked,
     *         contextfilechanged, contextquotaexceeded, ausstandwritefailed,
     *         ausstandnotewritefailed
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function write_moodle(
        string $path,
        string $content,
        string $expectedcontenthash,
        bool $requirecheckvalue,
        bool $createonly,
        int $courseid
    ): array {
        context_files::require_manage_own_files();

        $port = new private_files_storage_port();
        $existing = $port->read(context_files::area(), $path);

        if ($existing !== null && $createonly) {
            throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
        }
        self::guard_existing_locked($existing, $path);
        self::require_moodle_checkvalue_match($existing, $expectedcontenthash, $requirecheckvalue, $path);

        $operation = $existing === null ? pending_write_translation::OP_CREATE : pending_write_translation::OP_OVERWRITE;
        $written = self::persist_moodle_write($port, $path, $content, $operation, $courseid);

        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $existing['size'] ?? 0,
        ];
    }

    /**
     * Der Konfliktschutz des Moodle-Zweigs - dieselbe Zweiwegepruefung wie
     * {@see pointer_writer}'s gleichnamiges Gegenstueck (Issue #540): ohne
     * Pruefwert bleibt der Vertrag wie bisher ("ohne Pruefwert wird wie heute
     * ueberschrieben"), ausser beim Nachtragen (`$requirecheckvalue`) - dort
     * ist ein fehlender Pruefwert gegen eine bereits vorhandene Datei selbst
     * ein Konflikt, denn Nachtragen darf gewachsenen Bestand nie ungeprueft
     * ersetzen.
     *
     * @param array{checksum: string}|null $existing Ergebnis von {@see private_files_storage_port::read()}.
     * @param string $expectedcontenthash
     * @param bool $requirecheckvalue
     * @param string $path
     * @throws \moodle_exception contextfilechanged
     */
    private static function require_moodle_checkvalue_match(
        ?array $existing,
        string $expectedcontenthash,
        bool $requirecheckvalue,
        string $path
    ): void {
        if ($expectedcontenthash === '') {
            if ($requirecheckvalue && $existing !== null) {
                throw new \moodle_exception('contextfilechanged', 'local_coursepilot', '', $path);
            }
            return;
        }
        if (!$existing || $existing['checksum'] !== $expectedcontenthash) {
            throw new \moodle_exception('contextfilechanged', 'local_coursepilot', '', $path);
        }
    }

    /**
     * Der eigentliche Schreibvorgang, umschlossen von der Ausfallbehandlung
     * (Issue #540, ADR 0023 "an beiden Orten"): Pfad-, Endungs- und
     * Quotenpruefung sowie ein Pruefwert-Konflikt laufen bereits vorher und
     * bleiben unangetastet ({@see private_files_storage_port::write()} bekommt
     * hier bewusst keinen Pruefwert mehr mitgegeben - der Vergleich ist schon
     * erledigt); jeder andere Ausfall, der beim Persistieren selbst entsteht
     * (z.B. Private-Files-Speicher/Datenbank), vermerkt einen Ausstand, bevor
     * der Fehler zurueckgeht - nie roh durchgereicht.
     *
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation Eine der {@see pending_write_translation}-OP_*-Konstanten.
     * @param int $courseid
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception contextquotaexceeded, ausstandwritefailed, ausstandnotewritefailed
     */
    private static function persist_moodle_write(
        storage_port $port,
        string $path,
        string $content,
        string $operation,
        int $courseid
    ): array {
        try {
            return $port->write(context_files::area(), $path, $content);
        } catch (storage_conflict_exception $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            if (self::is_moodle_call_error($e)) {
                throw $e;
            }
            throw self::record_moodle_storage_failure($e->errorcode, $e->getMessage(), $path, $operation, $courseid);
        } catch (\Throwable $e) {
            throw self::record_moodle_storage_failure(get_class($e), $e->getMessage(), $path, $operation, $courseid);
        }
    }

    /**
     * Aufruffehler, die {@see private_files_storage_port::write()}/{@see private_files_storage_port::append()}
     * selbst noch werfen koennen (Pfad-/Endungs-/Quotenpruefung liegt dort,
     * nicht schon vorher bei {@see write_moodle()}/{@see append_moodle()}) -
     * zaehlen weiterhin nicht als Ausstand (Issue #540 Abnahmekriterium 2,
     * ADR 0023 Punkt 2). Ohne diese Ausnahme wuerde z.B. eine falsche
     * Dateiendung faelschlich als Speicherausfall vermerkt, nur weil sie erst
     * beim tatsaechlichen Schreibversuch durchschlaegt statt vorher.
     *
     * @param \moodle_exception $e
     * @return bool
     */
    private static function is_moodle_call_error(\moodle_exception $e): bool {
        $area = context_files::area();
        return in_array($e->errorcode, [$area->invalidpathkey, $area->quotaerrorkey, 'contextfilenotmarkdown'], true);
    }

    /**
     * Vermerkt einen Ausstand fuer einen Ausfall beim Persistieren in Private
     * Files (Issue #540, ADR 0023 "an beiden Orten") - dieselbe fuenfteilige
     * Ausfallantwort wie extern ({@see pointer_writer}), nur ohne
     * Instanzname/Host: es gibt keine Verbindung, die ausfallen koennte, nur
     * die eigene Moodle-Ablage selbst.
     *
     * @param string $errorclass
     * @param string $rawmessage
     * @param string $path
     * @param string $operation
     * @param int $courseid
     * @return \moodle_exception
     */
    private static function record_moodle_storage_failure(
        string $errorclass,
        string $rawmessage,
        string $path,
        string $operation,
        int $courseid
    ): \moodle_exception {
        return pending_write_translation::record_and_translate(
            $errorclass,
            'Private Files ' . $errorclass . ': ' . $rawmessage,
            $path,
            $operation,
            'Ihre privaten Dateien in Moodle sind gerade nicht beschreibbar – an Ihrem Speicher ist etwas zu tun',
            'Ihre privaten Dateien in Moodle',
            $courseid
        );
    }

    /**
     * Sperrt eine bereits vorhandene, personenbezogen markierte Zieldatei bei
     * ausgeschaltetem #344-Schalter - gemeinsame Absage von
     * {@see write_moodle()} und {@see append_moodle()} (Standards-Review zu
     * Issue #538: beide Kopien lagen vorher in getrennten Tool-Klassen,
     * durch die Relocation hierher nebeneinander sichtbar geworden).
     *
     * @param array{content: string}|null $existing Ergebnis von {@see private_files_storage_port::read()}.
     * @param string $path
     * @throws \moodle_exception contextfilelocked
     */
    private static function guard_existing_locked(?array $existing, string $path): void {
        if ($existing !== null && !personal_data::allowed() && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
    }

    /**
     * Haengt an eine Kontextdatei zeigerbewusst und ortsneutral an - siehe
     * {@see write()}.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Siehe {@see context_files::append_pointer_aware()}.
     * @param string $ausstand Siehe {@see context_files::append_pointer_aware()}.
     * @param int $courseid Siehe {@see context_files::append_pointer_aware()}.
     * @return array{path: string, created: bool, size: int}
     */
    public static function append(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $ausstand = '',
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        try {
            $location = storage_anchor::effective_location($area);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_APPEND, $courseid);
        }
        if ($location->kind === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }
        $port = storage_anchor::port($area, $courseid);
        try {
            $existing = $port->read($area, $path);
        } catch (webdav_error $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, pointer_writer::OP_APPEND, $courseid);
        } catch (\moodle_exception $e) {
            throw pointer_writer::record_location_failure($e, $path, $location, pointer_writer::OP_APPEND, $courseid);
        }
        self::guard_existing_locked($existing, $path);
        $finalcontent = ($existing['content'] ?? '') . $content;
        if (personal_data::is_marked($finalcontent)) {
            if (!personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            personal_data_hosts::require_allowed_location($location, $path);
        }
        if ($expectedcontenthash !== '' && ($existing === null || $existing['checksum'] !== $expectedcontenthash)) {
            throw new storage_conflict_exception($path);
        }
        if ($ausstand !== '' && $existing !== null && $expectedcontenthash === '') {
            throw new storage_conflict_exception($path);
        }
        $written = $port->append($area, $path, $content);
        return ['path' => $written['path'], 'created' => $written['created'], 'size' => $written['size']];
    }

    /**
     * Loest den Kontextpointer auf (Sonderfall Pruefung 8/IServ eingerechnet)
     * - relocated aus append_context_file::execute() (Issue #538). Gibt den
     * aufgeloesten externen Ort zurueck, oder `null` fuer Private Files;
     * wendet dabei zugleich das Personenbezugs-Gate der Zieldatei an.
     * Uebersetzt einen Ausfall bei Pruefung 8 (IServ) bereits hier, siehe
     * {@see resolve_write_target()} (Issue #541).
     *
     * @param string $path
     * @param string $content
     * @param int $courseid
     * @return pointer_location|null
     */
    private static function resolve_append_target(string $path, string $content, int $courseid): ?pointer_location {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'webdaviservfilesonly') {
                storage_anchor::writable_segments(context_files::area(), $path);
                self::guard_personal_data_for_append($path, $content, $courseid);
                throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_APPEND, $courseid);
            }
            throw $e;
        }
        if ($location !== null && $location->kind === pointer_location::EXTERN) {
            self::guard_personal_data_for_append($path, $content, $courseid);
            return $location;
        }
        return null;
    }

    /**
     * Personenbezugs-Gate vor dem Anhaengen am externen Ort - relocated aus
     * append_context_file::guard_personal_data_external() (Issue #538),
     * unveraendertes Verhalten.
     *
     * @param string $path
     * @param string $content
     * @param int $courseid
     * @throws \moodle_exception contextfilelocked
     */
    private static function guard_personal_data_for_append(string $path, string $content, int $courseid = 0): void {
        $existingcontent = self::peek_append_target_content($path, $courseid);
        if ($existingcontent !== null && !personal_data::allowed() && personal_data::is_marked($existingcontent)) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }

        $finalcontent = ($existingcontent ?? '') . $content;
        if (personal_data::is_marked($finalcontent)) {
            try {
                personal_data_hosts::require_allowed_location(context_files::resolve_pointer_location(), $path);
            } catch (\moodle_exception $e) {
                if ($e->errorcode !== 'webdaviservfilesonly') {
                    throw $e;
                }
            }
        }
    }

    /**
     * Liest die bereits vorhandene externe Zieldatei fuer das
     * Anhaengen-Gate, tolerant gegen eine unaufloesbare Instanz/Verbindung -
     * relocated aus append_context_file::peek_existing_content() (Issue
     * #538), unveraendertes Verhalten.
     *
     * @param string $path
     * @param int $courseid
     * @return string|null
     */
    private static function peek_append_target_content(string $path, int $courseid = 0): ?string {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            return null;
        }
        if ($location === null || $location->kind !== pointer_location::EXTERN) {
            return null;
        }
        try {
            return pointer_reader::peek_external_content(context_files::area(), $path, $location);
        } catch (webdav_error $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, pointer_writer::OP_APPEND, $courseid);
        }
    }

    /**
     * Der Moodle-Zweig von {@see append()} - laeuft ueber
     * {@see private_files_storage_port} (Issue #538).
     *
     * Seit Issue #540 vermerkt ein Ausfall beim Persistieren selbst (nicht:
     * Personenbezugs-Sperre, nicht: Quote) einen Ausstand, bevor der Fehler
     * zurueckgeht - symmetrisch zu {@see write_moodle()}. Anders als dort kein
     * Pruefwert-Konfliktschutz: `expected_contenthash` wirkt beim Anhaengen
     * dokumentiert nur am externen Ort ({@see \local_coursepilot\external\append_context_file}),
     * Spec 0016 §5.3 verbietet fuer Anhaengen ohnehin Locks.
     *
     * @param string $path
     * @param string $content
     * @param int $courseid Kurs-ID, nur fuer einen etwaigen Eintrag der
     *        Ausstandsnotiz - 0, wenn der Aufruf keinem Kurs zugeordnet ist.
     * @return array{path: string, created: bool, size: int}
     * @throws \moodle_exception contextfilelocked, contextquotaexceeded,
     *         ausstandwritefailed, ausstandnotewritefailed
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function append_moodle(string $path, string $content, int $courseid = 0): array {
        context_files::require_manage_own_files();

        $port = new private_files_storage_port();
        $existing = $port->read(context_files::area(), $path);
        self::guard_existing_locked($existing, $path);

        $result = self::persist_moodle_append($port, $path, $content, pending_write_translation::OP_APPEND, $courseid);

        return ['path' => $result['path'], 'created' => $result['created'], 'size' => $result['size']];
    }

    /**
     * Der eigentliche Anhaengevorgang, umschlossen von der Ausfallbehandlung
     * - siehe {@see persist_moodle_write()}.
     *
     * @param storage_port $port
     * @param string $path
     * @param string $content
     * @param string $operation
     * @param int $courseid
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception contextquotaexceeded, ausstandwritefailed, ausstandnotewritefailed
     */
    private static function persist_moodle_append(
        storage_port $port,
        string $path,
        string $content,
        string $operation,
        int $courseid
    ): array {
        try {
            return $port->append(context_files::area(), $path, $content);
        } catch (\moodle_exception $e) {
            if (self::is_moodle_call_error($e)) {
                throw $e;
            }
            throw self::record_moodle_storage_failure($e->errorcode, $e->getMessage(), $path, $operation, $courseid);
        } catch (\Throwable $e) {
            throw self::record_moodle_storage_failure(get_class($e), $e->getMessage(), $path, $operation, $courseid);
        }
    }
}
