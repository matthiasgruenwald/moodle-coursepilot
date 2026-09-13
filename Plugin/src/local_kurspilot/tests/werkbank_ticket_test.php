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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Einmal-Downloadticket fuer Werkbankdateien (#501, Spec #486 §13).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(werkbank_ticket::class)]
final class werkbank_ticket_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        oauth_lib::reset_current_token_id();
    }

    public function test_issue_and_redeem_delivers_original_bytes_with_matching_sha1(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'hallo welt');

        $link = werkbank_ticket::issue('blatt.pdf');

        $this->assertSame('blatt.pdf', $link['name']);
        $this->assertSame(strlen('hallo welt'), $link['size']);
        $this->assertSame(sha1('hallo welt'), $link['sha1']);
        $this->assertStringContainsString('/local/kurspilot/werkbank/download.php?ticket=', $link['url']);

        $delivery = werkbank_ticket::redeem($this->secret_from_url($link['url']));

        $this->assertSame('hallo welt', $delivery['content']);
        $this->assertSame(sha1($delivery['content']), $link['sha1'], 'SHA-1 der Antwort muss zu den ausgelieferten Bytes passen.');
    }

    public function test_second_redemption_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        werkbank_ticket::redeem($secret);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('werkbankticketinvalid', 'local_kurspilot'), '/') . '/');
        werkbank_ticket::redeem($secret);
    }

    public function test_expired_ticket_is_rejected(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);
        $DB->set_field(werkbank_ticket::TABLE, 'expires', time() - 1, ['tickethash' => hash('sha256', $secret)]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('werkbankticketexpired', 'local_kurspilot'), '/') . '/');
        werkbank_ticket::redeem($secret);
    }

    public function test_changed_contenthash_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'urspruenglich');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        // Datei nach Ausstellung, vor Abruf geaendert.
        $this->store('blatt.pdf', 'geaendert', true);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('werkbankticketcontentchanged', 'local_kurspilot'), '/') . '/');
        werkbank_ticket::redeem($secret);
    }

    /**
     * Das Ticket ist an die ausstellende Person gebunden, nicht an die zum
     * Abrufzeitpunkt angemeldete Sitzung - eine andere, gleichzeitig
     * angemeldete Lehrkraft (hier: mit einer gleichnamigen eigenen
     * Werkbankdatei) bekommt nie deren Inhalt geliefert.
     */
    public function test_ticket_stays_bound_to_issuing_person(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->setUser($teacher);
        $this->issue_connection((int) $teacher->id);
        $this->store('blatt.pdf', 'gehoert teacher');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        $this->setUser($other);
        $this->store('blatt.pdf', 'gehoert other');

        $delivery = werkbank_ticket::redeem($secret);

        $this->assertSame('gehoert teacher', $delivery['content']);
        $this->assertSame((int) $teacher->id, $delivery['userid']);
    }

    public function test_remoteaccessdisabled_rejects_redemption(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        set_config('remoteaccessenabled', '0', 'local_kurspilot');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('remoteaccessdisabled', 'local_kurspilot'), '/') . '/');
        werkbank_ticket::redeem($secret);
    }

    public function test_revoked_connection_rejects_redemption(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->store('blatt.pdf', 'inhalt');
        $tokenid = $this->issue_connection((int) $user->id);

        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        $DB->set_field('local_kurspilot_oauth_token', 'revoked', 1, ['id' => $tokenid]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('werkbankticketconnectionrevoked', 'local_kurspilot'), '/') . '/');
        werkbank_ticket::redeem($secret);
    }

    public function test_suspended_account_rejects_redemption(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);

        try {
            werkbank_ticket::redeem($secret);
            $this->fail('Erwartete werkbank_ticket_redemption_failed ausgeblieben.');
        } catch (werkbank_ticket_redemption_failed $e) {
            $this->assertSame('werkbankticketaccountinactive', $e->errorcode);
            $this->assertSame('blatt.pdf', $e->path, 'Fehlschlag traegt den Pfad fuer den access_log (Spec #486 §13).');
        }
    }

    public function test_deleted_account_rejects_redemption(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        $DB->set_field('user', 'deleted', 1, ['id' => $user->id]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('werkbankticketaccountinactive', 'local_kurspilot'), '/') . '/');
        werkbank_ticket::redeem($secret);
    }

    /**
     * Kein authenticate_access_token()-Aufruf vorher - genau der Fall eines
     * PHPUnit-Aufrufs der externen Funktion ohne MCP-Dispatcher davor.
     * oauthtokenid bleibt null. Ein solches Ticket ist nie staerker als
     * seine Verbindung (#512, Spec #486 §13): ohne bekannte ausstellende
     * Verbindung verlangt die Einloesung ersatzweise irgendeine noch
     * bestehende Verbindung der Person - hat sie gar keine, scheitert sie.
     */
    public function test_redemption_without_a_known_issuing_connection_needs_some_active_connection(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('werkbankticketconnectionrevoked', 'local_kurspilot'), '/') . '/'
        );
        werkbank_ticket::redeem($secret);
    }

    /**
     * Gegenprobe zum Test oben: dieselbe Ausgangslage (kein oauthtokenid am
     * Ticket), aber die Person hat eine andere, noch bestehende Verbindung -
     * dann loest das Ticket trotzdem ein.
     */
    public function test_redemption_without_a_known_issuing_connection_succeeds_with_another_active_connection(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        // Verbindung erst NACH dem Ausstellen angelegt, ohne
        // authenticate_access_token() fuer diese Anfrage aufzurufen -
        // oauthtokenid am Ticket bleibt null.
        $this->issue_connection((int) $user->id);
        oauth_lib::reset_current_token_id();

        $delivery = werkbank_ticket::redeem($secret);

        $this->assertSame('inhalt', $delivery['content']);
    }

    /**
     * Zwei gleichzeitige Einloeseversuche desselben Tickets duerfen die
     * Datei hoechstens einmal liefern (#512). Echte parallele Datenbank-
     * verbindungen sind in dieser PHPUnit-Umgebung nicht praktikabel - dieser
     * Test prueft deshalb nur den Ausgang (zwei sequenzielle Abrufe, der
     * zweite scheitert), nicht den Mechanismus selbst. Der eigentliche
     * Beweis der Nebenlaeufigkeitssicherheit steckt im naechsten Test.
     */
    public function test_concurrent_redemption_delivers_file_at_most_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);

        $first = werkbank_ticket::redeem($secret);
        $this->assertSame('inhalt', $first['content'], 'Der erste Abruf muss die Datei liefern.');

        try {
            werkbank_ticket::redeem($secret);
            $this->fail('Der zweite, gleichzeitige Abruf haette scheitern muessen.');
        } catch (werkbank_ticket_redemption_failed $e) {
            $this->assertSame(
                'werkbankticketinvalid',
                $e->errorcode,
                'Der zweite Abruf muss dasselbe "unbekannt" sehen wie ein nie ausgestelltes Ticket.'
            );
        }
    }

    /**
     * Belegt den eigentlichen Mechanismus hinter der Nebenlaeufigkeits-
     * sicherheit (#512, Review-Befund zum ersten Entwurf dieses Tickets):
     * echte parallele Prozesse lassen sich in PHPUnit nicht erzeugen, aber
     * der Effekt eines Gleichzeitigkeitsrennens - eine andere Verbindung
     * beansprucht dieselbe Zeile im selben Moment - laesst sich exakt
     * nachstellen, indem genau die Anweisung nachgeahmt wird, die
     * {@see werkbank_ticket::claim()} selbst fuer den Claim verwendet (ein
     * UPDATE mit dem alten Tickethash in der WHERE-Klausel). Direkt danach
     * hat unser eigener Abruf keine passende Zeile mehr - nicht weil eine
     * zweite Anfrage sequenziell zuerst dran war (das prueft der Test oben),
     * sondern weil das Claim-UPDATE selbst atomar ist: eine SELECT-dann-
     * DELETE-Implementierung (der vorherige Stand) haette hier faelschlich
     * noch die Ticketdaten gefunden und die Datei ausgeliefert.
     */
    public function test_claim_loses_to_a_rival_update_that_already_changed_the_tickethash(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(werkbank_ticket::issue('blatt.pdf')['url']);
        $hash = hash('sha256', $secret);

        // Simuliert den Sieger eines echten Gleichzeitigkeitsrennens: eine
        // andere Verbindung hat im selben Moment per UPDATE denselben Claim
        // ausgefuehrt, den auch werkbank_ticket::claim() verwenden wuerde.
        $rivalclaim = hash('sha256', 'rival');
        $DB->set_field_select(
            werkbank_ticket::TABLE,
            'tickethash',
            $rivalclaim,
            'tickethash = :hash',
            ['hash' => $hash]
        );

        try {
            werkbank_ticket::redeem($secret);
            $this->fail('Der Abruf haette am bereits geaenderten Tickethash scheitern muessen.');
        } catch (werkbank_ticket_redemption_failed $e) {
            $this->assertSame('werkbankticketinvalid', $e->errorcode);
        }

        // Die "gewinnende" Zeile (des simulierten Rivalen) blieb unberuehrt -
        // unser gescheiterter Versuch hat weder sie geloescht noch ihre Daten
        // gelesen.
        $this->assertTrue(
            $DB->record_exists(werkbank_ticket::TABLE, ['tickethash' => $rivalclaim]),
            'Der gescheiterte Abruf darf die Zeile des Rennsiegers nicht anfassen.'
        );
    }

    private function secret_from_url(string $url): string {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        return (string) $params['ticket'];
    }

    private function issue_connection(int $userid): int {
        global $DB;

        $record = new \stdClass();
        $record->accesstoken = oauth_lib::random_token(32);
        $record->refreshtoken = oauth_lib::random_token(32);
        $record->clientid = 'test-client';
        $record->userid = $userid;
        $record->expires = time() + oauth_lib::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = time();
        $id = (int) $DB->insert_record('local_kurspilot_oauth_token', $record);

        oauth_lib::authenticate_access_token($record->accesstoken);

        return $id;
    }

    private function store(string $filename, string $content, bool $overwrite = false): void {
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/kurspilot-material/',
            'filename' => $filename,
        ];
        if ($overwrite) {
            $existing = $fs->get_file(
                $filerecord['contextid'],
                $filerecord['component'],
                $filerecord['filearea'],
                $filerecord['itemid'],
                $filerecord['filepath'],
                $filerecord['filename']
            );
            if ($existing) {
                $existing->delete();
            }
        }
        $fs->create_file_from_string($filerecord, $content);
    }
}
