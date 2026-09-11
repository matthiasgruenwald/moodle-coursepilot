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

use local_kurspilot\pointer_location;
use local_kurspilot\storage_anchor;

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
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webdav_instance {

    /** @var string Repository-Typname. */
    private const REPOSITORY_TYPE = 'webdav';

    /** @var string[] Instanz-Optionsnamen von repository_webdav::get_instance_option_names(). */
    private const OPTION_NAMES = [
        'webdav_type', 'webdav_server', 'webdav_port', 'webdav_path', 'webdav_user', 'webdav_password', 'webdav_auth',
    ];

    /**
     * @var webdav_transport|null Test-Seam (Spec #486 Testing Decisions):
     *      ersetzt {@see curl_transport} durch den In-Memory-Fake. Nur ueber
     *      {@see use_test_transport()} setzbar, die ausserhalb von PHPUnit
     *      wirft - im Betrieb gibt es genau einen Transport.
     */
    private static ?webdav_transport $testtransport = null;

    /**
     * Test-Seam: laesst Tests den Betriebstransport (Moodles \curl) durch
     * den In-Memory-WebDAV-Fake ersetzen, ohne dass storage_anchor oder die
     * Kontextwerkzeuge davon wissen. `null` schaltet zurueck auf den
     * Betriebstransport.
     *
     * @param webdav_transport|null $transport
     * @throws \coding_exception ausserhalb von PHPUnit.
     */
    public static function use_test_transport(?webdav_transport $transport): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('webdav_instance::use_test_transport() ist nur in PHPUnit-Tests erlaubt.');
        }
        self::$testtransport = $transport;
    }

    /**
     * @param pointer_location $location Muss {@see pointer_location::EXTERN} sein.
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/webdavnotenabled/
     *         webdavauthunsupported/webdavfingerprintchanged
     */
    public static function resolve(pointer_location $location): resolved_webdav_instance {
        global $DB, $USER;

        $record = $DB->get_record_sql(
            'SELECT ri.id, ri.contextid
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.id = :id AND r.type = :type',
            ['id' => $location->instanceid, 'type' => self::REPOSITORY_TYPE]
        );
        if (!$record) {
            throw new \moodle_exception('webdavinstancemissing', 'local_kurspilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        $owncontextid = storage_anchor::own_context()->id;
        if ((int) $record->contextid !== (int) $owncontextid || \core\session\manager::is_loggedinas()) {
            throw new \moodle_exception('webdavinstanceforeign', 'local_kurspilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        if (!webdav_setup_steps::enabled_for_user((int) $USER->id)) {
            throw new \moodle_exception('webdavnotenabled', 'local_kurspilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        $options = self::fresh_options((int) $location->instanceid);

        if ((int) ($options['webdav_type'] ?? 0) !== 1 || ($options['webdav_auth'] ?? '') !== 'basic') {
            throw new \moodle_exception('webdavauthunsupported', 'local_kurspilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        if (self::fingerprint($options) !== self::normalised_fingerprint($location->fingerprint ?? [])) {
            throw new \moodle_exception('webdavfingerprintchanged', 'local_kurspilot', '', webdav_setup_steps::ORTSWAHL_PAGE);
        }

        $transport = self::$testtransport ?? new curl_transport(
            new \curl(),
            (string) ($options['webdav_user'] ?? ''),
            (string) ($options['webdav_password'] ?? '')
        );
        return new resolved_webdav_instance(self::base_url($options), $transport);
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
        $port = trim((string) ($options['webdav_port'] ?? ''));
        $host = $options['webdav_server'] . ($port !== '' ? ':' . $port : '');
        $path = trim((string) ($options['webdav_path'] ?? ''), '/');
        return 'https://' . $host . '/' . ($path !== '' ? $path . '/' : '');
    }
}
