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

/**
 * Der ortsneutrale Teil der Ausfallantwort (Issue #540, ADR 0023, Spec 0021):
 * Ausstandsnotiz vermerken, dann die fuenfteilige Antwort bauen - der Teil
 * von {@see pointer_writer}'s bisheriger `fail()`, der keine WebDAV-Kenntnis
 * braucht (Fehlerklasse, Pfad, Vorgang, Kennung, Kurs-ID sind ortsneutral;
 * Ursachentext und Zielbeschreibung liefert der Aufrufer bereits fertig).
 *
 * Zwei Aufrufer (Issue #540): {@see pointer_writer} fuer den externen Ort
 * (unveraendertes Verhalten, nur hierher ausgelagert) und
 * {@see webdav_storage_port} sowie {@see context_area} fuer die neu
 * hinzukommende Ausfallbehandlung des externen Ablage-Vertrag-Adapters bzw.
 * des Moodle-Schreibwegs - "an beiden Orten", ohne die webdav-spezifische
 * Ursachensprache zweimal zu pflegen (die bleibt bei {@see pointer_writer::reason_for()}).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pending_write_translation {

    /** @var string Vorgang "anlegen". */
    public const OP_CREATE = 'anlegen';

    /** @var string Vorgang "ueberschreiben". */
    public const OP_OVERWRITE = 'überschreiben';

    /** @var string Vorgang "anhaengen". */
    public const OP_APPEND = 'anhängen';

    /**
     * @var string Vorgang "unbekannt" (Issue #561): der Vorab-Lese-Check vor
     *      einem Schreibvorgang ist selbst mit einem Ausfall gescheitert -
     *      ob am Ort schon etwas lag, ist damit unbekannt, nicht binaer
     *      "anlegen" oder "ueberschreiben".
     */
    public const OP_UNKNOWN = 'unbekannt';

    /**
     * Vermerkt einen Ausstand und baut die fuenfteilige Ausfallantwort (Issue
     * #492/#516/#540): (1) Pfad und Vorgang; (2) die vom Aufrufer bereits
     * fertige Ursache in Lehrkraftsprache; (3) "noch nicht gespeichert,
     * vermerkt (Kennung ...)"; (4) die Anweisung an die KI, den Inhalt zu
     * behalten und mit `ausstand=` nachzutragen; (5) das vom Aufrufer bereits
     * fertige Ziel (Instanzname+Host extern, eine ortsbeschreibende
     * Kurzformel bei Private Files). Nie ein absoluter Serverpfad,
     * Benutzername, Passwort, HTTP-Code oder Antwortrumpf (Geheimnis-Test) -
     * der Rohcode geht stattdessen ins Zugriffsprotokoll.
     *
     * Kann die Notiz selbst nicht geschrieben werden (Private-Files-Quote
     * voll), sagt die Antwort das ausdruecklich statt die urspruengliche
     * Ursache zu verschweigen.
     *
     * @param string $errorclass Fehlerklasse, nie ein Freitext (Issue #516).
     * @param string $logmessage Interne, entwicklerorientierte Meldung - nur fuers Zugriffsprotokoll.
     * @param string $clientpath
     * @param string $operation Eine der OP_*-Konstanten.
     * @param string $reason Ursache in Lehrkraftsprache, inkl. "spaeter nachtragen"/"an Ihrem Speicher ist etwas zu tun".
     * @param string $target Instanzname+Host (extern) bzw. eine ortsbeschreibende Kurzformel (Private Files).
     * @param int $courseid Kurs-ID des Eintrags in der Ausstandsnotiz (Issue #516) - 0, wenn keinem Kurs zugeordnet.
     * @return \moodle_exception
     */
    public static function record_and_translate(
        string $errorclass,
        string $logmessage,
        string $clientpath,
        string $operation,
        string $reason,
        string $target,
        int $courseid
    ): \moodle_exception {
        access_log::log_failure($logmessage);

        try {
            $identifier = pending_write_notice::record($clientpath, $operation, $errorclass, $courseid);
        } catch (\moodle_exception $quotaerror) {
            if ($quotaerror->errorcode !== 'ausstandnotequotaexceeded') {
                throw $quotaerror;
            }
            return new \moodle_exception('ausstandnotewritefailed', 'local_coursepilot', '', (object) [
                'path' => $clientpath,
                'operation' => $operation,
            ]);
        }

        return new \moodle_exception('ausstandwritefailed', 'local_coursepilot', '', (object) [
            'path' => $clientpath,
            'operation' => $operation,
            'reason' => $reason,
            'kennung' => $identifier,
            'target' => $target,
        ]);
    }
}
