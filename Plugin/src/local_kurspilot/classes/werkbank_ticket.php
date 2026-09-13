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

namespace local_kurspilot;

/**
 * Einmal-Downloadticket fuer eine Werkbankdatei (Issue #501, Spec #486 §13):
 * ein Client mit Shell (curl) holt eine Werkbankdatei in Originalbytes ueber
 * einen eigenen, unauthentifizierten Endpunkt ({@see \local_kurspilot\werkbank_ticket}
 * ist die Entscheidungslogik dahinter, `werkbank/download.php` die duenne
 * Schale) - das Ticket selbst ist der Berechtigungsnachweis, kein
 * OAuth-Bearer-Header noetig.
 *
 * Gebunden an Person, Pfad und `contenthash`, oeffnet nur die Werkbank (jeder
 * Pfad laeuft ueber {@see material_files::resolve_file()}, das immer die
 * Werkbank auflöst und einen Ausbruch per "."/".." bereits ablehnt). Gilt
 * einmal ab dem ersten Abruf - die Zeile wird beim Nachschlagen atomar per
 * Compare-and-Swap beansprucht ({@see claim()}, #512), unabhaengig davon, ob
 * die anschliessenden Pruefungen bestehen: zwei gleichzeitige Abrufe
 * desselben Tickets liefern die Datei hoechstens einmal, auch wenn beide
 * Anfragen exakt im selben Moment eintreffen. Fest 15 Minuten gueltig, ohne
 * Range-Unterstuetzung (das setzt der Endpunkt um, der nie auf einen
 * Range-Header eingeht).
 *
 * Gespeichert wird nur der Hash des Tickets ({@see issue()}/{@see redeem()}),
 * nie das Geheimnis selbst. Abgelaufene Zeilen werden opportunistisch beim
 * naechsten Ausstellen entfernt ({@see purge_expired()}) - kein eigener
 * geplanter Task fuer eine einzelne, kleine Tabelle.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class werkbank_ticket {

    /** @var string DB-Tabelle der ausgestellten Tickets. */
    public const TABLE = 'local_kurspilot_werkbank_ticket';

    /** @var int Gueltigkeitsdauer in Sekunden - Spec #486 §13: "fest 15 Minuten". */
    public const TTL_SECONDS = 900;

    /**
     * Stellt ein Ticket fuer eine Werkbankdatei der angemeldeten Person aus.
     *
     * @param string $path Pfad relativ zur Werkbankwurzel, z.B. "blatt.pdf".
     * @return array{path: string, name: string, size: int, sha1: string, url: string}
     * @throws \moodle_exception invalidmaterialpath (Pfad ausserhalb der
     *         Werkbank), materialfilenotfound (Datei fehlt)
     */
    public static function issue(string $path): array {
        global $USER, $CFG, $DB;

        [$directory, $filename] = material_files::resolve_file($path);
        $info = material_files::read_content($directory, $filename);
        if ($info === null) {
            throw new \moodle_exception(
                'materialfilenotfound',
                'local_kurspilot',
                '',
                material_files::relative_file($directory, $filename)
            );
        }

        self::purge_expired();

        $secret = oauth_lib::random_token(32);
        $relativepath = material_files::relative_file($directory, $filename);

        $record = new \stdClass();
        $record->userid = (int) $USER->id;
        $record->path = $relativepath;
        $record->contenthash = $info['contenthash'];
        $record->tickethash = hash('sha256', $secret);
        $record->oauthtokenid = oauth_lib::current_token_id();
        $record->expires = time() + self::TTL_SECONDS;
        $record->timecreated = time();
        $DB->insert_record(self::TABLE, $record);

        return [
            'path' => $relativepath,
            'name' => $filename,
            'size' => $info['size'],
            'sha1' => $info['contenthash'],
            'url' => $CFG->wwwroot . '/local/kurspilot/werkbank/download.php?ticket=' . $secret,
        ];
    }

    /**
     * Loest ein Ticket ein - der einzige Weg, die dahinterliegenden Bytes zu
     * lesen. Prueft in dieser Reihenfolge: Notbremse (vor jedem
     * Datenbankzugriff, damit eine global gesperrte Instanz kein einziges
     * Ticket verbraucht), Ticket bekannt (und verbraucht es sofort - ab hier
     * ist es weg, unabhaengig vom Ausgang der folgenden Pruefungen), Ablauf,
     * Bestand der ausstellenden Verbindung, aktives Konto, unveraenderter
     * `contenthash`.
     *
     * @param string $secret Das Ticketgeheimnis aus der URL.
     * @return array{userid: int, path: string, filename: string, mimetype: string,
     *         content: string, size: int}
     * @throws werkbank_ticket_redemption_failed remoteaccessdisabled, werkbankticketinvalid,
     *         werkbankticketexpired, werkbankticketconnectionrevoked,
     *         werkbankticketaccountinactive, werkbankticketcontentchanged
     */
    public static function redeem(string $secret): array {
        global $DB;

        if ((string) get_config('local_kurspilot', 'remoteaccessenabled') === '0') {
            throw new werkbank_ticket_redemption_failed('remoteaccessdisabled', null);
        }

        $ticket = self::claim(hash('sha256', $secret));
        if (!$ticket) {
            // Kein Pfad bekannt - entweder war das Ticket nie ausgestellt,
            // oder ein gleichzeitiger Abruf hat es uns per {@see claim()}
            // bereits vor der Nase weggeschnappt. Aus Sicht dieser Anfrage
            // ununterscheidbar, und das ist gewollt (kein Zeitkanal).
            throw new werkbank_ticket_redemption_failed('werkbankticketinvalid', null);
        }

        self::assert_still_valid($ticket);

        [$directory, $filename] = material_files::resolve_file($ticket->path);
        $file = self::resolve_ticket_file($ticket, $directory, $filename);

        return [
            'userid' => (int) $ticket->userid,
            'path' => $ticket->path,
            'filename' => $filename,
            'mimetype' => (string) ($file->get_mimetype() ?: 'application/octet-stream'),
            'content' => $file->get_content(),
            'size' => (int) $file->get_filesize(),
        ];
    }

    /**
     * Prueft Ablauf, Verbindung und Konto des Ticket-Inhabers (Issue #523:
     * aus redeem() ausgelagert, um die Funktion unter der 50-Zeilen-Grenze
     * zu halten).
     *
     * @param \stdClass $ticket
     * @throws werkbank_ticket_redemption_failed
     */
    private static function assert_still_valid(\stdClass $ticket): void {
        global $DB;

        if ((int) $ticket->expires < time()) {
            throw new werkbank_ticket_redemption_failed('werkbankticketexpired', $ticket->path);
        }

        // Nie staerker als seine Verbindung (Spec #486 §13): ein Ticket mit
        // bekannter ausstellenden Verbindung braucht sie noch bestehend.
        // Ein Ticket OHNE bekannte Verbindung (#512: z.B. weil die
        // ausstellende Anfrage nie durch den OAuth-Dispatcher lief) ist
        // deshalb nicht automatisch staerker - es verlangt ersatzweise
        // irgendeine noch bestehende Verbindung der Person. Ausstellbar ist
        // es damit weiterhin (kein zusaetzlicher Ausstellungs-Check noetig),
        // aber es ueberlebt einen Sammelwiderruf (#338) genauso wenig wie
        // ein Ticket mit bekannter Verbindung.
        $hasconnection = $ticket->oauthtokenid !== null
            ? oauth_lib::connection_active((int) $ticket->oauthtokenid)
            : oauth_lib::has_active_connection((int) $ticket->userid);
        if (!$hasconnection) {
            throw new werkbank_ticket_redemption_failed('werkbankticketconnectionrevoked', $ticket->path);
        }

        $user = $DB->get_record('user', ['id' => (int) $ticket->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            throw new werkbank_ticket_redemption_failed('werkbankticketaccountinactive', $ticket->path);
        }
    }

    /**
     * Loest die Werkbankdatei auf und prueft den Contenthash (Issue #523:
     * aus redeem() ausgelagert).
     *
     * @param \stdClass $ticket
     * @param string $directory
     * @param string $filename
     * @return \stored_file
     * @throws werkbank_ticket_redemption_failed
     */
    private static function resolve_ticket_file(\stdClass $ticket, string $directory, string $filename): \stored_file {
        $contextid = \context_user::instance((int) $ticket->userid)->id;
        $file = get_file_storage()->get_file(
            $contextid,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            $directory,
            $filename
        );
        if (!$file || $file->is_directory() || $file->get_contenthash() !== $ticket->contenthash) {
            throw new werkbank_ticket_redemption_failed('werkbankticketcontentchanged', $ticket->path);
        }

        return $file;
    }

    /**
     * Beansprucht die Ticketzeile zum gegebenen Tickethash atomar und liefert
     * sie zurueck - oder null, wenn keine (mehr) existiert (#512).
     *
     * Vorher stand hier ein SELECT nach `tickethash`, gefolgt von einem
     * DELETE nach `id`: zwei getrennte Anweisungen mit einer Luecke
     * dazwischen. Zwei gleichzeitige Abrufe desselben Tickets konnten beide
     * das SELECT bestehen, bevor eine von beiden das DELETE ausfuehrte -
     * beide haetten die Datei ausgeliefert. Diese Methode ersetzt das durch
     * eine einzige atomare UPDATE-Anweisung mit dem alten Tickethash in der
     * WHERE-Klausel (Compare-and-Swap): die Datenbank sperrt die betroffene
     * Zeile fuer die Dauer der Anweisung, ein zeitgleiches zweites UPDATE mit
     * derselben WHERE-Bedingung sieht danach den bereits geaenderten Wert und
     * trifft keine Zeile mehr. Das gilt fuer jede SQL-Datenbank mit
     * zeilenweiser Sperrung bei UPDATE (MySQL/InnoDB, PostgreSQL) und braucht
     * keine von Moodles DB-Abstraktion nicht angebotene Rueckgabe der Anzahl
     * betroffener Zeilen: der Erfolg zeigt sich daran, ob die Zeile danach
     * unter dem eigenen, aus dem Prozess frischen Anspruchsmarker auffindbar
     * ist - kein anderer Prozess kennt ihn.
     *
     * @param string $tickethash sha256 des Ticketgeheimnisses.
     * @return \stdClass|null Die beanspruchte Zeile, oder null.
     */
    private static function claim(string $tickethash): ?\stdClass {
        global $DB;

        $claim = hash('sha256', $tickethash . '|' . random_string(20));
        $DB->set_field_select(self::TABLE, 'tickethash', $claim, 'tickethash = :hash', ['hash' => $tickethash]);

        $ticket = $DB->get_record(self::TABLE, ['tickethash' => $claim]);
        if (!$ticket) {
            return null;
        }
        // Beansprucht, ab hier weg - unabhaengig vom Ausgang der folgenden
        // Pruefungen (Ablauf, Verbindung, Konto, contenthash).
        $DB->delete_records(self::TABLE, ['id' => $ticket->id]);
        return $ticket;
    }

    /**
     * Entfernt abgelaufene Ticketzeilen - opportunistisch bei jedem
     * Ausstellen, statt ueber einen eigenen geplanten Task (Spec #486 §13:
     * "abgelaufene Tickets bleiben nicht dauerhaft liegen").
     *
     * @return void
     */
    private static function purge_expired(): void {
        global $DB;

        $DB->delete_records_select(self::TABLE, 'expires < :now', ['now' => time()]);
    }
}
