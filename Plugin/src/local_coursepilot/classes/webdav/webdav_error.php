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

/**
 * Eine benannte Fehlerklasse statt eines nackten Statuscodes (Issue #489,
 * Spec #486 §4, ADR 0022). Jeder Aufrufer von {@see webdav_client}
 * unterscheidet nur diese acht Klassen, nie einen HTTP-Code.
 *
 * Traegt bewusst nie Benutzername, Passwort, Anmeldekopf, Serverpfad oder
 * Antwortrumpf in der Meldung (Spec §3/§8, Geheimnis-Test).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_error extends \RuntimeException {

    /** @var string Kein DAV-XML-Rumpf zu 404, oder ein anderer unklarer Status - stumm wiederholt. */
    public const UNCLEAR = 'unklar/gedrosselt';

    /** @var string 404 mit DAV-XML-Rumpf. */
    public const NOT_FOUND = 'nicht gefunden';

    /** @var string 401/403. */
    public const AUTH_REJECTED = 'Anmeldung abgelehnt';

    /** @var string Zeitueberschreitung, DNS-Fehler. */
    public const UNREACHABLE = 'nicht erreichbar';

    /** @var string 507. */
    public const STORAGE_FULL = 'Speicher voll';

    /** @var string 409/412. */
    public const CONFLICT = 'Konflikt';

    /** @var string Moodles Hostsperre. */
    public const BLOCKED = 'gesperrt';

    /** @var string 3xx-Antwort - der Client folgt keiner Weiterleitung (Issue #510). */
    public const REDIRECTED = 'Weiterleitung abgelehnt';

    /**
     * @param string $errorclass Eine der Konstanten dieser Klasse.
     * @param string $message Interne, entwicklerorientierte Meldung - nie
     *        an eine Lehrkraft gereicht, nie ein Geheimnis.
     */
    public function __construct(
        public readonly string $errorclass,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorclass);
    }

    /**
     * Das eine Fehlerbild des WebDAV-Speichers (Issue #506): "nicht
     * gefunden" heisst leer, jeder andere Fehler bleibt ein benannter Fehler.
     * Vorher an vier fast identischen Stellen dupliziert
     * ({@see \local_coursepilot\pointer_reader::list_entries()}/read_content(),
     * {@see \local_coursepilot\pointer_writer}, {@see \local_coursepilot\ortswahl_lib}) -
     * jetzt die eine Stelle, die alle vier benutzen. Was "jeder andere
     * Fehler" konkret bedeutet, bleibt Sache des Aufrufers: `pointer_reader`
     * uebersetzt sofort in eine Lehrkraft-Meldung, `pointer_writer` reicht den
     * rohen Fehler unveraendert weiter (sein eigener Ausstand-Fang muss ihn
     * noch als {@see webdav_error} erkennen).
     *
     * @template T
     * @param self $e
     * @param T $whenmissing Rueckgabewert, wenn $e "nicht gefunden" ist.
     * @param callable(self): \Throwable $onfailure Baut die Ausnahme fuer
     *        jeden anderen Fehler - oder reicht $e unveraendert durch
     *        ({@see \local_coursepilot\pointer_writer}, dessen eigener
     *        Ausstand-Fang die rohe {@see webdav_error} noch erkennen muss).
     * @return T
     * @throws \Throwable Das Ergebnis von $onfailure($e).
     */
    public static function empty_when_missing(self $e, mixed $whenmissing, callable $onfailure): mixed {
        if ($e->errorclass === self::NOT_FOUND) {
            return $whenmissing;
        }
        throw $onfailure($e);
    }
}
