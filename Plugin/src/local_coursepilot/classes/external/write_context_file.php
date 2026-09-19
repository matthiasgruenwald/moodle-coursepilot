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

namespace local_coursepilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\context_files;
use local_coursepilot\personal_data;
use local_coursepilot\pointer_location;

defined('MOODLE_INTERNAL') || die();

/**
 * Legt eine Datei im Kontextbereich der aufrufenden Lehrkraft an oder
 * ueberschreibt sie vollstaendig (Issue #408, Spec 0016 §4.1).
 *
 * Reihenfolge ist Absicht: erst alle Absagen (Pfad, Endung, Groesse,
 * Personenbezug, Gleichzeitigkeit, Quote), dann genau ein Schreibvorgang in
 * einer Transaktion. Nichts wird angefasst, bevor nicht alles geprueft ist.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class write_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Kontextbereich, z.B. "plan.md"'),
            'content' => new external_value(PARAM_RAW, 'Vollstaendiger neuer Dateiinhalt'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash aus dem letzten Lesen - passt er nicht, bricht der Vorgang ab',
                VALUE_DEFAULT,
                ''
            ),
            'ausstand' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: Kennung eines offenen Ausstands (aus coursepilot_list_skills) - gelingt das Schreiben, '
                    . 'verschwindet der Eintrag im selben Aufruf',
                VALUE_DEFAULT,
                ''
            ),
            'nur_anlegen' => new external_value(
                PARAM_BOOL,
                'Optional: true legt nur an und ueberschreibt nie - fuer das Kopieren aus dem Altbestand '
                    . '(vorheriger Ort, aus coursepilot_list_context_files/read_context_file) an den neuen Ort',
                VALUE_DEFAULT,
                false
            ),
            'courseid' => new external_value(
                PARAM_INT,
                'Optional: Kurs-ID, wenn der Inhalt zu einem bestimmten Kurs gehoert - dient nur einem etwaigen '
                    . 'Eintrag der Notiz "noch nicht gespeichert", falls der Speicher/die Verbindung/der Ort scheitert',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @param string $ausstand
     * @param bool $nuranlegen
     * @param int $courseid
     * @return array
     * @throws \moodle_exception invalidcontextpath, contextfilenotmarkdown,
     *         contextfiletoolarge, contextfilelocked, contextfilechanged,
     *         contextfilealreadyexists, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $ausstand = '',
        bool $nuranlegen = false,
        int $courseid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
            'expected_contenthash' => $expectedcontenthash,
            'ausstand' => $ausstand,
            'nur_anlegen' => $nuranlegen,
            'courseid' => $courseid,
        ]);

        self::validate_context(context_files::own_context());

        $content = $params['content'];
        context_files::require_size_within_limit($content);

        return self::dispatch($params, $content);
    }

    /**
     * Loest den Kontextpointer auf und verzweigt in den externen oder den
     * Moodle-Zweig (Issue #523: aus execute() ausgelagert, um die Funktion
     * unter der 50-Zeilen-Grenze zu halten).
     *
     * @param array $params Validierte Parameter von execute().
     * @param string $content
     * @return array
     */
    private static function dispatch(array $params, string $content): array {
        // Zeigerbewusst (Issue #491, Spec #486 §6): der externe Zweig kennt
        // weder Nutzerquote noch moodle/user:manageownfiles - "fuer den
        // Kontextbereich in Moodle bleibt alles wie heute" gilt wortwoertlich,
        // die beiden Zweige bleiben deshalb getrennt statt ineinander verwoben.
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            // Pruefung 8 (IServ-Bereich, Issue #516, Spec #486 §2/§8) scheitert
            // schon bei der reinen Pointer-Aufloesung, bevor ein Ort feststeht -
            // trotzdem ein Ausfall am Ort, kein Aufruffehler: direkt in den
            // externen Zweig, dessen eigene Aufloesung (pointer_writer) denselben
            // Fehler noch einmal trifft, dort aber faengt und einen Eintrag in
            // der Ausstandsnotiz anlegt (statt hier schon unuebersetzt abzubrechen).
            if ($e->errorcode === 'webdaviservfilesonly') {
                // Mit $location === null bleiben Existenz-Peek und Zugelassener-
                // Speicher-Pruefung wirkungslos (beide an einen Ort gebunden, den
                // es hier nicht gibt) - der reine Inhalts-Gate ("ist der Inhalt
                // markiert, obwohl der Schalter aus ist?") bleibt aber in Kraft,
                // exakt dieselbe Pruefung wie im regulaeren externen Zweig unten.
                self::require_personal_data_allowed($content, null, $params['path'], $params['nur_anlegen'], $params['courseid']);
                return self::execute_external(
                    $params['path'],
                    $content,
                    $params['expected_contenthash'],
                    $params['ausstand'],
                    $params['nur_anlegen'],
                    $params['courseid']
                );
            }
            throw $e;
        }
        self::require_personal_data_allowed($content, $location, $params['path'], $params['nur_anlegen'], $params['courseid']);

        if ($location !== null && $location->kind === pointer_location::EXTERN) {
            return self::execute_external(
                $params['path'],
                $content,
                $params['expected_contenthash'],
                $params['ausstand'],
                $params['nur_anlegen'],
                $params['courseid']
            );
        }

        return self::execute_moodle($params, $content);
    }

    /**
     * Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011): geprueft
     * wird die Markierung im zu schreibenden Inhalt, nicht der Inhalt selbst
     * (Spec 0016 §5.5) - fuer beide Orte identisch (Spec #486 §6:
     * "allowpersonaldata wirkt unveraendert am Inhalt").
     *
     * Zugelassener Speicher (Issue #493, ADR 0021 §3): unabhaengig vom
     * Schalter - eine markierte Datei geht nur in einen zugelassenen
     * Speicher, Private Files sind immer zugelassen (kein Zweig hier noetig,
     * sie sind nie EXTERN).
     *
     * Auch die externe Zieldatei zaehlt (Issue #515): eine personenbezogen
     * markierte Datei ist bei ausgeschaltetem Schalter nicht lesbar - sie
     * darf dann erst recht nicht ueberschrieben werden, extern genauso wie
     * in Moodle (siehe die entsprechende Pruefung in {@see execute_moodle()}).
     * Dieselbe Reihenfolge wie dort gilt auch hier: "nur_anlegen" gegen eine
     * bereits vorhandene Zieldatei ist ein Aufruffehler (Kopieren aus dem
     * Altbestand, Issue #498), unabhaengig davon, ob diese Zieldatei
     * markiert ist - "contextfilealreadyexists" geht deshalb vor
     * "contextfilelocked".
     *
     * Ein Ausfall genau beim Vorab-Lesen (Issue #505 Befund #10: abgelehnte
     * Anmeldung, nicht erreichbar, unklar/gedrosselt, ...) bricht ebenfalls
     * ab, aber nicht mehr unuebersetzt: {@see \local_coursepilot\pointer_writer::record_preread_failure()}
     * legt denselben Ausstand an, den auch der echte Schreibversuch anlegen
     * wuerde - siehe dort fuer die Begruendung, warum bewusst *vor* dem
     * Schreibversuch abgebrochen wird (Issue #515: kein ungeprueftes
     * Ueberschreiben einer moeglicherweise markierten Datei).
     *
     * @param string $content
     * @param pointer_location|null $location
     * @param string $path
     * @param bool $nuranlegen
     * @param int $courseid Siehe execute() - nur fuer einen etwaigen Ausstandseintrag.
     * @throws \moodle_exception contextfilelocked, contextfilealreadyexists, ausstandwritefailed
     */
    private static function require_personal_data_allowed(
        string $content,
        ?pointer_location $location,
        string $path,
        bool $nuranlegen,
        int $courseid = 0
    ): void {
        if ($location !== null && $location->kind === pointer_location::EXTERN && !personal_data::allowed()) {
            try {
                $existing = \local_coursepilot\pointer_reader::peek_external_content(context_files::area(), $path, $location);
            } catch (\local_coursepilot\webdav\webdav_error $e) {
                throw \local_coursepilot\pointer_writer::record_preread_failure(
                    $e,
                    $location,
                    $path,
                    $nuranlegen ? \local_coursepilot\pointer_writer::OP_CREATE : \local_coursepilot\pointer_writer::OP_OVERWRITE,
                    $courseid
                );
            }
            if ($existing !== null && $nuranlegen) {
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
        \local_coursepilot\personal_data_hosts::require_allowed_location($location, $path);
    }

    /**
     * Kopier-/Konfliktschutz vor dem Schreiben im Moodle-Zweig (Issue #523:
     * aus execute_moodle() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten).
     *
     * @param array $params
     * @param array|null $existing
     * @throws \moodle_exception contextfilealreadyexists, contextfilelocked, contextfilechanged
     */
    private static function guard_moodle_write(array $params, ?array $existing): void {
        // Kopieren aus dem Altbestand (Issue #498, Spec #486 §9): am neuen
        // Ort wird nie ueberschrieben - dieselbe Garantie, die der externe
        // Zweig ueber "If-None-Match: *" bereits hat.
        if ($existing && $params['nur_anlegen']) {
            throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $params['path']);
        }

        // Auch die Zieldatei zaehlt: eine personenbezogen markierte Datei ist
        // bei ausgeschaltetem Schalter nicht lesbar - sie darf dann erst
        // recht nicht ueberschrieben werden. Sonst waere die #344-Grenze auf
        // dem zerstoerenden Weg offen, den sie auf dem lesenden schliesst
        // (dieselbe Begruendung wie Spec 0016 §4.2 fuer Append).
        if ($existing && !personal_data::allowed()
                && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $params['path']);
        }

        // Gleichzeitigkeitsschutz ohne Locks (Spec 0016 §5.3): eine fehlende
        // Datei ist ebenfalls ein Konflikt - sie wurde zwischendurch geloescht.
        if ($params['expected_contenthash'] !== ''
                && (!$existing || $existing['contenthash'] !== $params['expected_contenthash'])) {
            throw new \moodle_exception('contextfilechanged', 'local_coursepilot', '', $params['path']);
        }
    }

    /**
     * Der Moodle-Zweig (Spec #486 §6: "fuer den Kontextbereich in Moodle
     * bleibt alles wie heute") - Groessen- und Personenbezugspruefung sind
     * bereits im Aufrufer erledigt.
     *
     * @param array $params Ergebnis von {@see self::validate_parameters()}.
     * @param string $content
     * @return array
     * @throws \moodle_exception contextfilealreadyexists, contextfilelocked,
     *         contextfilechanged, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function execute_moodle(array $params, string $content): array {
        context_files::require_manage_own_files();

        [$directory, $filename] = context_files::resolve_writable_file($params['path']);

        $existing = context_files::read_content($directory, $filename);
        $oldsize = $existing ? $existing['size'] : 0;
        $newsize = strlen($content);

        self::guard_moodle_write($params, $existing);

        context_files::require_quota($newsize - $oldsize);

        context_files::write($directory, $filename, $content);
        \local_coursepilot\ausstand_notice::dismiss($params['ausstand']);

        $relativepath = context_files::relative_file($directory, $filename);
        $message = $existing
            ? get_string('contextfileoverwritten', 'local_coursepilot', (object) [
                'path' => $relativepath,
                'before' => $oldsize,
                'after' => $newsize,
            ])
            : get_string('contextfilecreated', 'local_coursepilot', $relativepath);

        return [
            'path' => $relativepath,
            'created' => !$existing,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * Der externe Zweig (Issue #491, Spec #486 §4/§6): bedingtes Anlegen/
     * Ueberschreiben ueber WebDAV, siehe {@see \local_coursepilot\pointer_writer::write()}.
     * Groessen- und Personenbezugspruefung sind bereits im Aufrufer erledigt -
     * fuer beide Orte identisch, deshalb dort statt hier.
     *
     * Konfliktschutz mit dem gelesenen Pruefwert (Issue #513): der Aufrufer
     * gibt "expected_contenthash" unveraendert durch; "ausstand" wirkt
     * zusaetzlich als Schranke gegen ungeprueftes Nachtragen - eine bereits
     * gewachsene Zieldatei ohne mitgegebenen Pruefwert wird dann selbst zum
     * Konflikt (siehe {@see \local_coursepilot\pointer_writer::write()}).
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash Optional: siehe {@see execute()}.
     * @param string $ausstand Optional: siehe {@see execute()}.
     * @param bool $createonly Optional: siehe {@see execute()}.
     * @param int $courseid Optional: siehe {@see execute()}.
     * @return array
     */
    private static function execute_external(
        string $path,
        string $content,
        string $expectedcontenthash,
        string $ausstand,
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $result = context_files::write_pointer_aware(
            $path,
            $content,
            $createonly,
            $expectedcontenthash,
            $ausstand !== '',
            $courseid
        );
        \local_coursepilot\ausstand_notice::dismiss($ausstand);

        $message = $result['created']
            ? get_string('contextfilecreated', 'local_coursepilot', $result['path'])
            : get_string('contextfileoverwritten', 'local_coursepilot', (object) [
                'path' => $result['path'],
                'before' => $result['oldsize'],
                'after' => $result['size'],
            ]);

        return [
            'path' => $result['path'],
            'created' => $result['created'],
            'size' => $result['size'],
            'message' => $message,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Dateipfad, relativ zum Kontextbereich'),
            'created' => new external_value(PARAM_BOOL, 'true, wenn die Datei neu angelegt wurde'),
            'size' => new external_value(PARAM_INT, 'Neue Dateigroesse in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch'),
        ]);
    }
}
