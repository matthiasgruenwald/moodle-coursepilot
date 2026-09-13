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

namespace local_kurspilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\context_files;
use local_kurspilot\personal_data;
use local_kurspilot\pointer_location;

defined('MOODLE_INTERNAL') || die();

/**
 * Haengt Inhalt an eine Datei im Kontextbereich der aufrufenden Lehrkraft an
 * (Issue #409, Spec 0016 §4.2).
 *
 * Dieselbe Reihenfolge wie {@see write_context_file}: erst alle Absagen
 * (Pfad, Endung, Groesse, Personenbezug der Zieldatei, Quote), dann genau
 * ein Schreibvorgang. Der Unterschied zum Schreiben ist die Stelle, an der
 * gelesen wird: Lesen, Zusammenfuegen und Schreiben passieren in einem
 * Serveraufruf, die Lehrkraft muss die Datei also nicht vorher lesen. Im
 * Moodle-Zweig bleibt `expected_contenthash` deshalb wirkungslos - der
 * Aufrufer hat dort keinen Stand, gegen den er pruefen koennte.
 *
 * Extern ist das anders (Issue #513, Spec #486 §6: "Anhaengen nutzt den
 * Pruefwert ebenso"): ein frueheres Lesen liefert dort einen echten
 * Pruefwert, und `expected_contenthash` schuetzt den Read-modify-write in
 * {@see \local_kurspilot\pointer_writer::append()} gegen eine Handaenderung,
 * die lange vor diesem Aufruf geschah.
 *
 * Was das *nicht* heisst: Spec 0016 §5.3 verbietet Locks, zwei wirklich
 * gleichzeitige Appends koennen einander daher weiterhin verlieren. Der
 * Kontextbereich gehoert genau einer Person, gleichzeitiges Schreiben ist dort
 * der Ausnahmefall, und ein Lock waere die teurere Antwort darauf. Die
 * Transaktion in {@see \local_kurspilot\storage_anchor::replace()} (aufgerufen
 * ueber {@see context_files::append()}) sichert nur das Naheliegende zu: kein
 * halb geschriebener Zustand aus einem abgebrochenen Vorgang.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class append_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Kontextbereich, z.B. "journal.md"'),
            'content' => new external_value(PARAM_RAW, 'Anzuhaengender Inhalt'),
            'ausstand' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: Kennung eines offenen Ausstands (aus kurspilot_list_skills) - gelingt das Schreiben, '
                    . 'verschwindet der Eintrag im selben Aufruf',
                VALUE_DEFAULT,
                ''
            ),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional, wirkt nur am externen Ort: contenthash der Zieldatei aus dem letzten Lesen - passt er '
                    . 'nicht, bricht der Vorgang ab. Ohne ETag (IServ) beruht der Vergleich auf der Aenderungszeit '
                    . '(Sekundenaufloesung).',
                VALUE_DEFAULT,
                ''
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
     * @param string $ausstand
     * @param string $expectedcontenthash
     * @param int $courseid
     * @return array
     * @throws \moodle_exception invalidcontextpath, contextfilenotmarkdown,
     *         contextfiletoolarge, contextfilelocked, contextquotaexceeded,
     *         contextfileexternalconflict (extern, Issue #513)
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(
        string $path,
        string $content,
        string $ausstand = '',
        string $expectedcontenthash = '',
        int $courseid = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content' => $content,
            'ausstand' => $ausstand,
            'expected_contenthash' => $expectedcontenthash,
            'courseid' => $courseid,
        ]);

        self::validate_context(context_files::own_context());

        $content = $params['content'];
        context_files::require_size_within_limit($content);

        // Zeigerbewusst (Issue #491, Spec #486 §6): siehe write_context_file
        // fuer die Begruendung der getrennten Zweige.
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            // Pruefung 8 (IServ-Bereich, Issue #516, Spec #486 §2/§8) - siehe
            // write_context_file::execute() fuer die Begruendung.
            if ($e->errorcode === 'webdaviservfilesonly') {
                return self::execute_external(
                    $params['path'],
                    $content,
                    $params['ausstand'],
                    $params['expected_contenthash'],
                    $params['courseid']
                );
            }
            throw $e;
        }
        if ($location !== null && $location->kind === pointer_location::EXTERN) {
            return self::execute_external(
                $params['path'],
                $content,
                $params['ausstand'],
                $params['expected_contenthash'],
                $params['courseid']
            );
        }

        return self::execute_moodle($params, $content);
    }

    /**
     * Der Moodle-Zweig (Spec #486 §6) - Groessenpruefung des Anhaengsels ist
     * bereits im Aufrufer erledigt.
     *
     * @param array $params Ergebnis von {@see self::validate_parameters()}.
     * @param string $content Anzuhaengender Inhalt.
     * @return array
     * @throws \moodle_exception contextfilelocked, contextquotaexceeded
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    private static function execute_moodle(array $params, string $content): array {
        context_files::require_manage_own_files();

        [$directory, $filename] = context_files::resolve_writable_file($params['path']);

        $existing = context_files::read_content($directory, $filename);

        // Geprueft wird die Markierung der Zieldatei, nicht das Anhaengsel
        // (Spec 0016 §5.5): ohne diesen Schritt liesse sich die #344-Grenze
        // mit einem Append umgehen - Journal als personenbezogen markiert,
        // Schalter aus, Kurspilot schreibt trotzdem weiter hinein. Keine
        // Zieldatei = kein Frontmatter = kein Personenbezug.
        // ponytail: kein Frontmatter-Test auf dem Anhaengsel - Spec 0016 §5.5
        // beauftragt ausdruecklich nur die Zieldatei.
        if ($existing && !personal_data::allowed()
                && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_kurspilot', '', $params['path']);
        }

        context_files::require_quota(strlen($content));

        $newsize = context_files::append($directory, $filename, $content);
        \local_kurspilot\ausstand_notice::dismiss($params['ausstand']);

        $relativepath = context_files::relative_file($directory, $filename);
        $message = $existing
            ? get_string('contextfileappended', 'local_kurspilot', (object) [
                'path' => $relativepath,
                'size' => $newsize,
            ])
            : get_string('contextfilecreated', 'local_kurspilot', $relativepath);

        // Weiches Signal statt hartem Ende: ein Limit auf der Zieldatei
        // wuerde das Journal mitten im Schuljahr abwuergen. Die Rotation ist
        // Skill-Sache, das Plugin gibt nur den Hinweis (Spec 0016 §5.2/§8.4).
        if ($newsize > context_files::MAX_WRITE_BYTES) {
            $message .= ' ' . get_string('contextfilerotation', 'local_kurspilot');
        }

        return [
            'path' => $relativepath,
            'created' => !$existing,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * Der externe Zweig (Issue #491, Spec #486 §4/§6): Read-modify-write mit
     * `If-Match`, siehe {@see \local_kurspilot\pointer_writer::append()}.
     * Personenbezug der Zieldatei wird ueber den pointer-bewussten Lesezweig
     * geprueft, denselben, den auch `read_context_file` benutzt.
     *
     * Der Rotationshinweis folgt extern derselben 1-MB-Grenze wie im
     * Moodle-Zweig (Issue #505 Befund #9, siehe execute_moodle()): der Text
     * nennt ausdruecklich "1 MB", eine unbedingte Anzeige waere bei kleinen
     * Dateien irrefuehrend.
     *
     * @param string $path
     * @param string $content
     * @param string $ausstand Optional: siehe {@see execute()}.
     * @param string $expectedcontenthash Optional: siehe {@see execute()}.
     * @param int $courseid Optional: siehe {@see execute()}.
     * @return array
     */
    private static function execute_external(
        string $path,
        string $content,
        string $ausstand,
        string $expectedcontenthash,
        int $courseid = 0
    ): array {
        self::guard_personal_data_external($path, $content, $courseid);

        $result = context_files::append_pointer_aware($path, $content, $expectedcontenthash, $ausstand !== '', $courseid);
        \local_kurspilot\ausstand_notice::dismiss($ausstand);

        return self::build_append_response($result);
    }

    /**
     * Personenbezugs-Vorpruefung fuer den externen Zweig (Issue #523: aus
     * execute_external() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten): sperrt eine bereits markierte Zieldatei
     * und prueft bei neuer Markierung den zugelassenen Speicher.
     *
     * @param string $path
     * @param string $content
     * @param int $courseid Siehe {@see execute()} - nur fuer einen etwaigen Ausstandseintrag.
     */
    private static function guard_personal_data_external(string $path, string $content, int $courseid = 0): void {
        $existingcontent = self::peek_existing_content($path, $courseid);
        if ($existingcontent !== null && !personal_data::allowed() && personal_data::is_marked($existingcontent)) {
            throw new \moodle_exception('contextfilelocked', 'local_kurspilot', '', $path);
        }

        // Zugelassener Speicher (Issue #493, ADR 0021 §3): geprueft wird die
        // ganze entstehende Datei, nicht nur der bisherige Bestand - ein neu
        // angelegtes Anhaengsel kann die Markierung selbst erst mitbringen
        // (der Fall $existingcontent === null).
        $finalcontent = ($existingcontent ?? '') . $content;
        if (personal_data::is_marked($finalcontent)) {
            try {
                \local_kurspilot\personal_data_hosts::require_allowed_location(
                    context_files::resolve_pointer_location(),
                    $path
                );
            } catch (\moodle_exception $e) {
                if ($e->errorcode !== 'webdaviservfilesonly') {
                    throw $e;
                }
                // Siehe oben: der folgende Schreibversuch scheitert ohnehin.
            }
        }
    }

    /**
     * Liest die bereits vorhandene Zieldatei, tolerant gegen eine
     * unaufloesbare Instanz/Verbindung (Issue #505 Befund #10) - eine
     * geloeschte Instanz, entzogene Freischaltung o.ae. bricht die
     * Vorpruefung nicht ab, der folgende echte Schreibversuch
     * ({@see \local_kurspilot\context_files::append_pointer_aware()}) loest
     * denselben Ort erneut auf und legt bei demselben Ausfall den Ausstand
     * vollstaendig an.
     *
     * Ein Ausfall *waehrend* des eigentlichen GET (abgelehnte Anmeldung,
     * nicht erreichbar, unklar/gedrosselt, ...) bricht dagegen bewusst hier
     * ab, uebersetzt ueber {@see \local_kurspilot\pointer_writer::record_preread_failure()}
     * in denselben Ausstand - siehe dort fuer die Begruendung (Issue #515:
     * kein ungeprueftes Anhaengen an eine moeglicherweise markierte Datei,
     * falls ausgerechnet nur dieses eine GET scheitert, der anschliessende
     * Read-modify-write aber durchgeht).
     *
     * @param string $path
     * @param int $courseid Siehe {@see guard_personal_data_external()}.
     * @return string|null
     */
    private static function peek_existing_content(string $path, int $courseid = 0): ?string {
        try {
            $location = context_files::resolve_pointer_location();
        } catch (\moodle_exception $e) {
            // Pruefung 8 (IServ-Bereich, Issue #516, Spec #486 §2/§8): der Ort
            // ist nicht aufloesbar - der folgende Schreibversuch scheitert
            // ohnehin und legt den Eintrag in der Ausstandsnotiz an. Jeder
            // andere Fehlerschluessel hier (pointerunreadable/pointerincomplete)
            // ist dagegen ein echter Aufruffehler, kein Ausfall.
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            return null;
        }
        if ($location === null || $location->kind !== pointer_location::EXTERN) {
            return null;
        }
        try {
            return \local_kurspilot\pointer_reader::peek_external_content(context_files::area(), $path, $location);
        } catch (\local_kurspilot\webdav\webdav_error $e) {
            throw \local_kurspilot\pointer_writer::record_preread_failure(
                $e,
                $location,
                $path,
                \local_kurspilot\pointer_writer::OP_APPEND,
                $courseid
            );
        }
    }

    /**
     * Baut die Antwort des externen Zweigs mit dem Rotationshinweis (Issue
     * #523: aus execute_external() ausgelagert).
     *
     * @param array{path: string, created: bool, size: int} $result
     * @return array
     */
    private static function build_append_response(array $result): array {
        $message = $result['created']
            ? get_string('contextfilecreated', 'local_kurspilot', $result['path'])
            : get_string('contextfileappended', 'local_kurspilot', (object) [
                'path' => $result['path'],
                'size' => $result['size'],
            ]);
        if ($result['size'] > context_files::MAX_WRITE_BYTES) {
            $message .= ' ' . get_string('contextfilerotation', 'local_kurspilot');
        }

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
            'size' => new external_value(PARAM_INT, 'Gesamtgroesse der Datei nach dem Anhaengen, in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch'),
        ]);
    }
}
