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
 * Pending-note translation for external location failures (Issue #492, ADR
 * 0023): the WebDAV failure vocabulary and the five-part failure answer.
 * Writing itself runs through {@see webdav_storage_port} (Issue #645); this
 * class only records failures that {@see context_area} observes before the
 * adapter is reached (pointer resolution, preflight read).
 *
 * A conflict is a caller error and never creates a pending note (ADR 0023
 * point 2). Every other failure at storage, connection or location records
 * an entry in the pending note before the error is returned.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_writer {

    /**
     * @var string Vorgang "anlegen" - Ausstandsnotiz-Vokabular (ADR 0023).
     *      Oeffentlich (Issue #505 Befund #10): die Personenbezugs-
     *      Vorpruefungen der Schreibendpunkte brauchen dasselbe Vokabular fuer
     *      {@see record_preread_failure()}.
     */
    public const OP_CREATE = pending_write_translation::OP_CREATE;

    /** @var string Vorgang "anhaengen". */
    public const OP_APPEND = pending_write_translation::OP_APPEND;

    /**
     * @var string Vorgang "unbekannt" (Issue #561) - siehe
     *      {@see pending_write_translation::OP_UNKNOWN}.
     */
    public const OP_UNKNOWN = pending_write_translation::OP_UNKNOWN;

    /**
     * @var string[] moodle_exception-Fehlerschluessel, die genauso einen
     *      Ausstand anlegen wie ein {@see webdav_error} - Ort-Ausfaelle im
     *      Sinne von ADR 0023. Die ersten fuenf kommen aus
     *      {@see \local_coursepilot\webdav\webdav_instance::resolve()}
     *      (geloeschte Instanz, entzogene Freischaltung, geaendertes
     *      Pruefmerkmal, u.a.); `contextrootmissing` kommt dagegen aus
     *      {@see webdav_storage_port} (Issue #514: die
     *      Kontextbereich-Wurzel fehlt am externen Ort). Jeder andere
     *      moodle_exception-Fehlerschluessel, der aus diesem Zweig entkommt,
     *      ist ein Programmierfehler und laeuft unveraendert weiter.
     */
    private const LOCATION_FAILURE_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
        'webdavfingerprintchanged',
        'contextrootmissing',
        // Pruefung 8 (Issue #516, Spec #486 §2/§8): "Scheitert ein
        // Schreibvorgang an einer der Pruefungen 2 bis 6 oder 8, entsteht ein
        // Ausstand." Pruefung 1 (Pointer unlesbar/unvollstaendig) und
        // Pruefung 7 (Verschachtelung) bleiben bewusst aussen vor - beides
        // sind Aufruffehler, keine Ausfaelle an Speicher/Verbindung/Ort.
        'webdaviservfilesonly',
    ];

    /**
     * @var string[] Fehlerklassen/-schluessel, deren Ursache sich "spaeter"
     *      von selbst loest (voruebergehend) statt an der Verbindung der
     *      Lehrkraft zu haengen - Teil 2 der fuenfteiligen Ausfallantwort
     *      (Issue #516, Spec #486 §8: "spaeter nachtragen" vs. "an Ihrem
     *      Speicher ist etwas zu tun"). Jede andere Fehlerklasse gilt als
     *      "an Ihrem Speicher ist etwas zu tun".
     */
    private const LATER_CLASSES = [
        webdav_error::UNCLEAR,
        webdav_error::UNREACHABLE,
    ];

    /**
     * @var array<string, string> Fehlerklasse/-schluessel => Ursache in
     *      Lehrkraftsprache, Teil 2 der fuenfteiligen Ausfallantwort
     *      (Issue #492).
     */
    private const REASONS = [
        // Bewusst nicht alarmierend formuliert (Issue #529): eine Drosselung
        // durch den eingebauten Bruteforce-/Rate-Schutz einer fremden
        // Nextcloud-Instanz ist ein erwartbarer, normaler Vorgang, keine
        // Störung, über die man sich wundern müsste.
        webdav_error::UNCLEAR => 'the storage is briefly throttling requests (normal on some Nextcloud instances)',
        webdav_error::NOT_FOUND => 'the target folder cannot be reached there',
        webdav_error::AUTH_REJECTED => 'the login to the storage was rejected',
        webdav_error::UNREACHABLE => 'the storage is currently unreachable',
        webdav_error::STORAGE_FULL => 'the storage is full',
        webdav_error::BLOCKED => 'access to the storage is blocked',
        webdav_error::REDIRECTED => 'the storage redirected to a different address',
        'webdavinstancemissing' => 'the connection no longer exists',
        'webdavinstanceforeign' => 'the connection no longer belongs to you',
        'webdavnotenabled' => 'external storage is no longer enabled for you',
        'webdavauthunsupported' => 'the connection uses a login method that is no longer supported',
        'webdavfingerprintchanged' => 'server, path or account of the connection have changed',
        'contextrootmissing' => 'the selected context area no longer exists there (moved, deleted'
            . ' or renamed) — please choose again on the location selection page',
        'webdaviservfilesonly' => 'the selected path is outside "Files/" on IServ',
    ];

    /**
     * Uebersetzt einen Ausfall beim Vorab-Lesen genauso wie einen Ausfall
     * beim echten Schreiben (Issue #505 Befund #10): dieselbe Ausstandsnotiz,
     * derselbe fuenfteilige Text. Genutzt von den Personenbezugs-
     * Vorpruefungen der Schreibendpunkte ({@see \local_coursepilot\external\write_context_file},
     * {@see \local_coursepilot\external\append_context_file}), wenn das GET vor
     * dem eigentlichen Schreibversuch an Verbindung/Ort scheitert (abgelehnte
     * Anmeldung, nicht erreichbar, unklar/gedrosselt, ...).
     *
     * Bricht bewusst *vor* dem eigentlichen Schreibversuch ab, statt einfach
     * durchzureichen: bei einer echten Ausfallklasse (dieselbe, die auch der
     * anschliessende Schreibversuch treffen wuerde) bleibt dadurch nichts
     * geschrieben. Wuerde man stattdessen unbesehen zum Schreibversuch
     * durchreichen, koennte eine markierte Zieldatei ungeprueft ueberschrieben
     * werden, falls ausgerechnet nur dieses eine Vorab-GET scheitert, der
     * anschliessende PUT aber durchgeht - genau der Fall, den
     * der Vorab-Lese-Check laut Issue #515 nicht stillschweigend uebergehen
     * darf.
     *
     * @param webdav_error $e
     * @param pointer_location $location
     * @param string $clientpath
     * @param string $operation Eine der OP_*-Konstanten.
     * @param int $courseid Kurs-ID fuer den Eintrag der Ausstandsnotiz, 0 ohne Kurs.
     * @return \moodle_exception
     */
    public static function record_preread_failure(
        webdav_error $e,
        pointer_location $location,
        string $clientpath,
        string $operation,
        int $courseid
    ): \moodle_exception {
        return self::translate_or_record($e, $clientpath, $location, $operation, $courseid);
    }

    /**
     * `Konflikt` bekommt die bestehende, auf Zusammenfuehren gerichtete
     * Meldung und legt **keinen** Ausstand an (ADR 0023 Punkt 2: Konflikt ist
     * ein Aufruffehler, kein Ausfall); jeder andere Fehler ist ein Ausfall im
     * Sinne der Ausstandsnotiz und laeuft ueber {@see fail()}.
     */
    private static function translate_or_record(
        webdav_error $e,
        string $clientpath,
        pointer_location $location,
        string $operation,
        int $courseid
    ): \moodle_exception {
        if ($e->errorclass === webdav_error::CONFLICT) {
            return new \moodle_exception('contextfileexternalconflict', 'local_coursepilot', '', $clientpath);
        }
        return self::fail(
            $e->errorclass,
            $e->getMessage(),
            $clientpath,
            $operation,
            (string) ($location->fingerprint['server'] ?? ''),
            $location->instanceid,
            $courseid
        );
    }

    /**
     * Ort-Ausfaelle legen ebenfalls einen Ausstand an - jeder andere
     * moodle_exception-Fehlerschluessel laeuft unveraendert weiter, er gehoert
     * nicht zu diesem Zweig. Zwei Quellen (Issue #516): die ersten sechs
     * Codes aus {@see \local_coursepilot\webdav\webdav_instance::resolve()}
     * (Pruefungen 2-6: "eine geloeschte Instanz, eine entzogene
     * Freischaltung, ein geaendertes Pruefmerkmal", dazu #514s
     * "contextrootmissing") - dort ist der Pointer bereits aufgeloest,
     * $location also gesetzt; `webdaviservfilesonly` (Pruefung 8) dagegen
     * kommt aus {@see \local_coursepilot\context_pointer::resolve_target()}
     * *waehrend* der Pointer-Aufloesung selbst - $location ist dort noch
     * `null`, Host und Instanz-ID kommen dann aus dem $a der Ausnahme.
     *
     * Oeffentlich (Issue #541): {@see context_area} ruft dies inzwischen auch
     * direkt fuer `webdaviservfilesonly` auf, sobald die Pointer-Aufloesung
     * selbst schon scheitert.
     *
     * @param \moodle_exception $e
     * @param string $clientpath
     * @param pointer_location|null $location null bei `webdaviservfilesonly`.
     * @param string $operation Eine der OP_*-Konstanten.
     * @param int $courseid Kurs-ID fuer den Eintrag der Ausstandsnotiz, 0 ohne Kurs.
     * @return \moodle_exception
     */
    public static function record_location_failure(
        \moodle_exception $e,
        string $clientpath,
        ?pointer_location $location,
        string $operation,
        int $courseid
    ): \moodle_exception {
        if (!in_array($e->errorcode, self::LOCATION_FAILURE_CODES, true)) {
            return $e;
        }
        if ($location !== null) {
            $host = (string) ($location->fingerprint['server'] ?? '');
            $instanceid = $location->instanceid;
        } else {
            $host = (string) ($e->a->server ?? '');
            $instanceid = is_numeric($e->a->instanceid ?? null) ? (int) $e->a->instanceid : null;
        }
        return self::fail($e->errorcode, $e->getMessage(), $clientpath, $operation, $host, $instanceid, $courseid);
    }

    /**
     * Vermerkt einen Ausstand ({@see pending_write_notice::record()}) und baut die
     * fuenfteilige Ausfallantwort (Issue #492/#516, Spec #486 §8/§10): (1)
     * Pfad und Vorgang; (2) Ursache in Lehrkraftsprache, mit dem Hinweis
     * "spaeter nachtragen" oder "an Ihrem Speicher ist etwas zu tun" (Issue
     * #516); (3) "noch nicht gespeichert, vermerkt (Kennung ...)"; (4) die
     * Anweisung an die KI, den Inhalt zu behalten, mit `pending_entry=`
     * nachzutragen und keinen anderen Ort zu nehmen; (5) Instanzname und
     * Host. Nie ein absoluter Serverpfad, Benutzername, Passwort, HTTP-Code
     * oder Antwortrumpf (Geheimnis-Test) - der Rohcode ($rawmessage, z.B.
     * "HTTP 507") geht stattdessen ins Zugriffsprotokoll.
     *
     * Kann die Notiz selbst nicht geschrieben werden (Private-Files-Quote
     * voll), sagt die Antwort das ausdruecklich statt die urspruengliche
     * Ursache zu verschweigen.
     *
     * @param string $errorclass webdav_error-Konstante oder ein Fehlerschluessel aus LOCATION_FAILURE_CODES.
     * @param string $rawmessage Interne, entwicklerorientierte Meldung (z.B. "HTTP 507") - nur fuers Zugriffsprotokoll.
     * @param string $clientpath
     * @param string $operation Eine der OP_*-Konstanten.
     * @param string $host
     * @param int|null $instanceid
     * @param int $courseid Kurs-ID des Eintrags in der Ausstandsnotiz (Issue #516).
     * @return \moodle_exception
     */
    private static function fail(
        string $errorclass,
        string $rawmessage,
        string $clientpath,
        string $operation,
        string $host,
        ?int $instanceid,
        int $courseid
    ): \moodle_exception {
        return pending_write_translation::record_and_translate(
            $errorclass,
            'WebDAV ' . $errorclass . ': ' . $rawmessage,
            $clientpath,
            $operation,
            self::reason_for($errorclass),
            self::describe_target($host, $instanceid),
            $courseid
        );
    }

    /**
     * Die Ursache in Lehrkraftsprache samt Teil 2 der Ausfallantwort (Issue
     * #516, Spec #486 §8): "spaeter nachtragen" fuer eine Ursache, die sich
     * voraussichtlich von selbst loest, sonst "an Ihrem Speicher ist etwas zu
     * tun". Oeffentlich (Issue #540), weil {@see webdav_storage_port} dieselbe
     * WebDAV-Ursachensprache braucht, ohne sie zweimal zu pflegen.
     *
     * @param string $errorclass
     * @return string
     */
    public static function reason_for(string $errorclass): string {
        $reason = self::REASONS[$errorclass] ?? ('error class "' . $errorclass . '"');
        return $reason . ' – ' . self::classify($errorclass);
    }

    /**
     * Teil 2 der Ausfallantwort (Issue #516, Spec #486 §8): "spaeter
     * nachtragen" fuer eine Ursache, die sich voraussichtlich von selbst
     * loest, sonst "an Ihrem Speicher ist etwas zu tun".
     *
     * @param string $errorclass
     * @return string
     */
    private static function classify(string $errorclass): string {
        return in_array($errorclass, self::LATER_CLASSES, true)
            ? 'this can be added later'
            : 'something needs to be done on your storage';
    }

    /**
     * "Instanzname und Host" (Teil 5 der Ausfallantwort) - nie der volle
     * Basispfad (der koennte auf ein Verzeichnis des Speichers verweisen),
     * das Pruefmerkmal im Pointer traegt aber bereits nur den Host, kein
     * Geheimnis. Der Instanzname kommt frisch aus der Datenbank, weil eine
     * geloeschte Instanz (webdavinstancemissing) keinen mehr hat - dann
     * bleibt nur der uebergebene Host.
     *
     * Oeffentlich (Issue #540), weil {@see webdav_storage_port} dieselbe
     * Zielbeschreibung braucht, ohne sie zweimal zu pflegen.
     *
     * @param string $host
     * @param int|null $instanceid
     * @return string
     */
    public static function describe_target(string $host, ?int $instanceid): string {
        global $DB;

        $name = $instanceid !== null
            ? $DB->get_field('repository_instances', 'name', ['id' => $instanceid])
            : false;

        if ($name === false || $name === null || $name === '') {
            return $host;
        }
        return $name . ' (' . $host . ')';
    }
}
