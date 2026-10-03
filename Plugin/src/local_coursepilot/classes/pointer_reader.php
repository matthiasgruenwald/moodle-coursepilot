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
use local_coursepilot\webdav\webdav_setup_steps;

/**
 * Shared WebDAV read helpers for the {@see storage_port} adapters and their
 * callers: the weak external check value and the teacher-facing error text.
 * Reading and listing themselves run through {@see webdav_storage_port}
 * (Issue #645); this class no longer interprets locations.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_reader {

    /**
     * Der aus ETag oder `getlastmodified` abgeleitete Pruefwert (Issue #513,
     * Spec #486 §4/§6) - was {@see webdav_storage_port} als Pruefwert
     * (`contenthash` an der Werkzeuggrenze) zurueckgibt und als
     * `expected_contenthash` wieder entgegennimmt und gegen den dann
     * aktuellen Stand prueft.
     *
     * Kein Moodle-`contenthash`: WebDAV kennt keinen Inhalts-Hash, dieser
     * Wert ist ein rein opakes Vergleichsmerkmal. Ein ETag hat Vorrang
     * (Nextcloud); fehlt er (IServ), tritt `getlastmodified` als schwacher
     * Ersatz ein - Sekundenaufloesung, die Werkzeugbeschreibung nennt diese
     * Grenze.
     *
     * @param string|null $etag
     * @param int $timemodified
     * @return string 40-stelliger Hexwert (sha1) - PARAM_ALPHANUMEXT-sicher,
     *         anders als ein roher ETag, der haeufig Anfuehrungszeichen traegt.
     */
    public static function external_checkvalue(?string $etag, int $timemodified): string {
        return $etag !== null ? sha1('etag:' . $etag) : sha1('mtime:' . $timemodified);
    }

    /**
     * Uebersetzt einen {@see webdav_error} in eine an die Lehrkraft
     * gerichtete Meldung - nie mit Host, Konto, Passwort, HTTP-Code oder
     * Antwortrumpf (Geheimnis-Test, Spec #486 Testing Decisions), nur die
     * benannte Fehlerklasse und der Verweis auf die Ortswahlseite.
     *
     * Oeffentlich, weil auch {@see \local_coursepilot\location_selection} (Issue
     * #494: Auflisten/Anlegen auf der Ortswahlseite) denselben Fehlertext
     * braucht - eine Uebersetzung statt zwei fast identischer Kopien.
     *
     * Der Sprachstring ist seit Issue #526 (Spec #486 §5/§8) waehlbar: der
     * Default "webdavexternalerror" ist an die KI gerichtet (Kontext-Lücke),
     * passt aber weder auf die Ortswahlseite selbst (an die Lehrkraft
     * gerichtet, keine KI-Anweisung) noch auf die Materialwerkzeuge (kein
     * Kontextbereich betroffen) - {@see \local_coursepilot\location_selection} und
     * {@see \local_coursepilot\material_files} uebergeben hier ihren eigenen
     * Schluessel.
     *
     * `unklar/gedrosselt` (Issue #529) wechselt am Standardschluessel auf
     * einen eigenen Text: eine Drosselung ist typischerweise binnen Sekunden
     * vorbei, deshalb soll die KI dort selbst kurz warten und denselben
     * Aufruf wiederholen, statt sofort die Lehrkraft zu informieren - anders
     * als bei den uebrigen Fehlerklassen (Anmeldung abgelehnt, Speicher
     * voll, ...), die sich nicht von selbst loesen. Nur am Standardschluessel:
     * ein von Ortswahl/Materialwerkzeugen uebergebener eigener Schluessel
     * bleibt unveraendert - andere Zielgruppe, andere Textlogik.
     *
     * @param webdav_error $e
     * @param string $stringkey
     * @return \moodle_exception
     */
    public static function webdav_exception(webdav_error $e, string $stringkey = 'webdavexternalerror'): \moodle_exception {
        if ($stringkey === 'webdavexternalerror' && $e->errorclass === webdav_error::UNCLEAR) {
            $stringkey = 'webdavexternalerrorunclear';
        }
        return new \moodle_exception($stringkey, 'local_coursepilot', '', (object) [
            // Issue #565: uebersetztes Label statt der fest-deutschen
            // internen Konstante, siehe webdav_error::label().
            'errorclass' => webdav_error::label($e->errorclass),
            'page' => webdav_setup_steps::LOCATION_SELECTION_PAGE,
        ]);
    }
}
