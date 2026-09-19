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

namespace local_coursepilot\webdav;

use local_coursepilot\pointer_location;
use local_coursepilot\storage_anchor;

/**
 * Loest eine im Kontextpointer genannte WebDAV-Nutzerinstanz auf (Issue
 * #490, Spec #486 §2/§3) und fuehrt dabei alle Pruefungen ohne Netz aus, die
 * bei jeder Auflösung gelten - jede mit benanntem Fehler, nie ein stiller
 * Rueckfall:
 *
 * 2. Die Instanz existiert (und gehoert zum Repository-Typ "webdav").
 * 3. Instanzeigentum: `contextid` der Instanz ist der eigene Nutzerkontext,
 *    keine "Login as"-Sitzung. Die Instanz-ID kommt ausschliesslich aus dem
 *    serverseitigen Pointer, nie aus einer Client-Eingabe.
 * 4. WebDAV-Freischaltung fuer diese Person ({@see webdav_setup_steps}).
 * 5. HTTPS und Basic.
 * 6. Das Pruefmerkmal (Server, Basispfad, Konto) stimmt unveraendert.
 *
 * Zugangsdaten werden danach frisch aus `repository_instance_config`
 * gelesen - nie ueber Moodles Repository-Cache, nie zwischengespeichert
 * (Spec §2/§3).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_instance {

    /** @var string Repository-Typname. */
    private const REPOSITORY_TYPE = 'webdav';

    /** @var string[] Instanz-Optionsnamen von repository_webdav::get_instance_option_names(). */
    private const OPTION_NAMES = [
        'webdav_type', 'webdav_server', 'webdav_port', 'webdav_path', 'webdav_user', 'webdav_password', 'webdav_auth',
    ];

    /**
     * @var string[] Die IServ-Bereichsnamen, sortiert (Issue #497, Spec #486
     *      §5/§2 Pruefung 8): besteht die Wurzelebene einer Instanz genau aus
     *      diesen fuenf Namen, gilt sie als IServ erkannt.
     */
    /** @var string Der einzige IServ-Bereich, unter dem bei IServ gewaehlt werden darf (Issue #497, Spec §5). */
    public const ISERV_FILES_AREA = 'Files';

    private const ISERV_AREAS = [self::ISERV_FILES_AREA, 'Groups', 'Print', 'Temp', 'Windows'];

    /**
     * @var webdav_transport|null Von aussen gesetzter Transport (Issue #535,
     *      Spec #486 Testing Decisions): ersetzt {@see curl_transport}, z.B.
     *      durch den In-Memory-Fake im Test. Nur ueber {@see set_transport()}
     *      setzbar. `null` (Default) baut {@see resolve_owned()} den
     *      Betriebstransport (Moodles \curl) selbst.
     */
    private static ?webdav_transport $transport = null;

    /**
     * Setzt den Transport von aussen (Issue #535): laesst Aufrufer - im
     * Betrieb niemand, im Test die WebDAV-Tests - den Betriebstransport
     * (Moodles \curl) durch einen eigenen ersetzen, ohne dass storage_anchor
     * oder die Kontextwerkzeuge davon wissen. `null` schaltet zurueck auf den
     * Betriebstransport.
     *
     * @param webdav_transport|null $transport
     */
    public static function set_transport(?webdav_transport $transport): void {
        self::$transport = $transport;
    }

    /**
     * @param pointer_location $location Muss {@see pointer_location::EXTERN} sein.
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/webdavfingerprintchanged
     */
    public static function resolve(pointer_location $location): resolved_webdav_instance {
        $resolved = self::resolve_owned((int) $location->instanceid);

        $options = self::fresh_options((int) $location->instanceid);
        if (self::fingerprint($options) !== self::normalised_fingerprint($location->fingerprint ?? [])) {
            throw new \moodle_exception('webdavfingerprintchanged', 'local_coursepilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        return $resolved;
    }

    /**
     * Loest eine WebDAV-Nutzerinstanz per ID auf, ohne ein Pruefmerkmal zu
     * verlangen (Issue #494): Existenz, Instanzeigentum (inkl. "Login as"),
     * WebDAV-Freischaltung, https+Basic - dieselben Pruefungen 2-5 wie
     * {@see resolve()}, nur ohne Pruefung 6 (Pruefmerkmal), weil die
     * Ortswahlseite eine Instanz durchsucht, bevor ueberhaupt ein
     * Kontextpointer mit einem gespeicherten Pruefmerkmal existiert. Das
     * frisch gelesene Pruefmerkmal dieses Aufrufs ist es, das die Ortswahl
     * beim Abschliessen erst in den Pointer schreibt ({@see fingerprint_of()}).
     *
     * @param int $instanceid
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/webdavauthunsupported
     */
    public static function resolve_owned(int $instanceid): resolved_webdav_instance {
        global $DB, $USER;

        $record = $DB->get_record_sql(
            'SELECT ri.id, ri.contextid
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.id = :id AND r.type = :type',
            ['id' => $instanceid, 'type' => self::REPOSITORY_TYPE]
        );
        if (!$record) {
            throw new \moodle_exception('webdavinstancemissing', 'local_coursepilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        $owncontextid = storage_anchor::own_context()->id;
        if ((int) $record->contextid !== (int) $owncontextid || \core\session\manager::is_loggedinas()) {
            throw new \moodle_exception('webdavinstanceforeign', 'local_coursepilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        if (!webdav_setup_steps::enabled_for_user((int) $USER->id)) {
            throw new \moodle_exception('webdavnotenabled', 'local_coursepilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        $options = self::fresh_options($instanceid);

        if (!self::auth_supported($options)) {
            throw new \moodle_exception('webdavauthunsupported', 'local_coursepilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        // \curl (lib/filelib.php) is not autoloaded - plain pages like
        // ortswahl_browse.php would fail with "Class curl not found".
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $transport = self::$transport ?? new curl_transport(
            new \curl(),
            (string) ($options['webdav_user'] ?? ''),
            (string) ($options['webdav_password'] ?? '')
        );
        return new resolved_webdav_instance(self::base_url($options), $transport);
    }

    /**
     * Das frisch gelesene Pruefmerkmal einer Instanz (Issue #494) - was die
     * Ortswahl beim Abschliessen in den Kontextpointer schreibt.
     *
     * @param int $instanceid
     * @return array{server: string, basispfad: string, konto: string}
     */
    public static function fingerprint_of(int $instanceid): array {
        return self::fingerprint(self::fresh_options($instanceid));
    }

    /**
     * Ob eine Instanz https+Basic erfuellt, ohne bei Verstoss zu werfen
     * (Issue #497) - anders als {@see resolve_owned()}, das genau deshalb
     * hier nicht wiederverwendet wird: die Ortswahlseite muss eine nicht
     * waehlbare Instanz weiterhin *anzeigen* (mit Begruendung), statt beim
     * Auflisten aller eigenen Instanzen abzubrechen.
     *
     * @param int $instanceid
     * @return bool
     */
    public static function has_supported_auth(int $instanceid): bool {
        return self::auth_supported(self::fresh_options($instanceid));
    }

    /**
     * Das eine Praedikat "https+Basic", geteilt von {@see resolve_owned()}
     * (wirft) und {@see has_supported_auth()} (wirft nicht) - Issue #497
     * Standards-Review: beide kannten die Bedingung zuvor je einmal, invertiert.
     *
     * @param array<string, string|null> $options
     * @return bool
     */
    private static function auth_supported(array $options): bool {
        return (int) ($options['webdav_type'] ?? 0) === 1 && ($options['webdav_auth'] ?? '') === 'basic';
    }

    /**
     * IServ-Erkennung als Ja/Nein-Pruefung (Issue #497, Spec #486 §5): die
     * Wurzelebene einer Instanz besteht genau aus den fuenf IServ-Bereichen.
     * Braucht Netz (ein PROPFIND auf die Instanzwurzel) - das Ergebnis
     * gehoert danach ins Pruefmerkmal des Pointers, damit die spaetere
     * Auflosung ohne Netz prueft (§2, Pruefung 8).
     *
     * @param int $instanceid
     * @return bool
     * @throws \moodle_exception wie {@see resolve_owned()}.
     * @throws \local_coursepilot\webdav\webdav_error bei einem Netzfehler - vom Aufrufer zu behandeln.
     */
    public static function detect_iserv_root(int $instanceid): bool {
        $resolved = self::resolve_owned($instanceid);
        return self::is_iserv_listing($resolved->client()->propfind($resolved->directory_url(''), 1));
    }

    /**
     * @param array<int, array{name: string, type: string}> $entries Wurzelebene, {@see webdav_client::propfind()}.
     * @return bool
     */
    public static function is_iserv_listing(array $entries): bool {
        $names = array_values(array_map(
            static fn (array $entry): string => $entry['name'],
            array_filter($entries, static fn (array $entry): bool => $entry['type'] === 'folder')
        ));
        sort($names);
        return $names === self::ISERV_AREAS;
    }

    /**
     * Liest die Instanzoptionen unmittelbar aus `repository_instance_config` -
     * bewusst nicht ueber `repository::get_instance()`/dessen MUC-Cache
     * (Spec §2: "Zugangsdaten werden bei jedem Zugriff frisch aus der
     * Instanz gelesen und nie zwischengespeichert").
     *
     * @param int $instanceid
     * @return array<string, string|null>
     */
    private static function fresh_options(int $instanceid): array {
        global $DB;

        $records = $DB->get_records('repository_instance_config', ['instanceid' => $instanceid], '', 'name, value');
        $options = [];
        foreach (self::OPTION_NAMES as $name) {
            $options[$name] = isset($records[$name]) ? $records[$name]->value : null;
        }
        return $options;
    }

    /**
     * @param array<string, string|null> $options
     * @return array{server: string, basispfad: string, konto: string}
     */
    private static function fingerprint(array $options): array {
        return [
            'server' => (string) ($options['webdav_server'] ?? ''),
            'basispfad' => trim((string) ($options['webdav_path'] ?? ''), '/'),
            'konto' => (string) ($options['webdav_user'] ?? ''),
        ];
    }

    /**
     * @param array $fingerprint Roh aus dem Pointer.
     * @return array{server: string, basispfad: string, konto: string}
     */
    private static function normalised_fingerprint(array $fingerprint): array {
        return [
            'server' => (string) ($fingerprint['server'] ?? ''),
            'basispfad' => trim((string) ($fingerprint['basispfad'] ?? ''), '/'),
            'konto' => (string) ($fingerprint['konto'] ?? ''),
        ];
    }

    /**
     * @param array<string, string|null> $options
     * @return string https-Adresse der Instanz inkl. Basispfad, mit abschliessendem "/".
     */
    private static function base_url(array $options): string {
        // Moodle's WebDAV form stores "default port" as '0'.
        $port = (int) ($options['webdav_port'] ?? 0);
        $host = $options['webdav_server'] . ($port > 0 ? ':' . $port : '');
        $path = trim((string) ($options['webdav_path'] ?? ''), '/');
        return 'https://' . $host . '/' . ($path !== '' ? $path . '/' : '');
    }
}
