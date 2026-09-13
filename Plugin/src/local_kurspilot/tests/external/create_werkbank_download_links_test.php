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

use local_kurspilot\material_files;
use local_kurspilot\oauth_lib;
use local_kurspilot\werkbank_ticket;

defined('MOODLE_INTERNAL') || die();

/**
 * Rein lesendes Werkzeug: Einmal-Downloadlinks fuer Werkbankdateien (#501,
 * Spec #486 §13).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(create_werkbank_download_links::class)]
final class create_werkbank_download_links_test extends \advanced_testcase {

    public function setUp(): void {
        parent::setUp();
        oauth_lib::reset_current_token_id();
    }

    public function test_returns_url_name_size_and_sha1_per_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt eins');
        $this->store('ordner/blatt2.pdf', 'inhalt zwei');

        $result = create_werkbank_download_links::execute(['blatt.pdf', 'ordner/blatt2.pdf']);

        $this->assertCount(2, $result['links']);
        $this->assertSame('blatt.pdf', $result['links'][0]['name']);
        $this->assertSame(strlen('inhalt eins'), $result['links'][0]['size']);
        $this->assertSame(sha1('inhalt eins'), $result['links'][0]['sha1']);
        $this->assertStringContainsString('/local/kurspilot/werkbank/download.php?ticket=', $result['links'][0]['url']);
        $this->assertSame('blatt2.pdf', $result['links'][1]['name']);
        $this->assertSame('blatt.pdf, ordner/blatt2.pdf', $result['path'], 'Fuer den access_log: alle Pfade kommagetrennt.');

        // Kein fertiges "curl ..." o.ae. in der Antwort - nur URL, Name,
        // Groesse, SHA-1 (Spec #486 §13).
        $this->assertSame(['path', 'name', 'size', 'sha1', 'url'], array_keys($result['links'][0]));
    }

    public function test_ticket_actually_delivers_the_same_bytes(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Ohne bekannte Verbindung braucht die Einloesung ersatzweise
        // irgendeine noch bestehende Verbindung der Person (#512) - dieser
        // Aufruf simuliert wie die anderen Tests hier den externen
        // Funktionsaufruf ohne MCP-Dispatcher davor (oauthtokenid am
        // Ticket bleibt null).
        $this->issue_connection((int) $user->id);
        oauth_lib::reset_current_token_id();
        $this->store('blatt.pdf', 'originalbytes');

        $link = create_werkbank_download_links::execute(['blatt.pdf'])['links'][0];
        $query = parse_url($link['url'], PHP_URL_QUERY);
        parse_str((string) $query, $params);

        $delivery = werkbank_ticket::redeem((string) $params['ticket']);

        $this->assertSame('originalbytes', $delivery['content']);
        $this->assertSame(sha1($delivery['content']), $link['sha1']);
    }

    public function test_rejects_path_outside_the_werkbank(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        create_werkbank_download_links::execute(['../../../etc/passwd']);
    }

    public function test_rejects_missing_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        create_werkbank_download_links::execute(['nichtvorhanden.pdf']);
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
        return (int) $DB->insert_record('local_kurspilot_oauth_token', $record);
    }

    private function store(string $path, string $content): void {
        [$directory, $filename] = material_files::resolve_file($path);
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => $directory,
            'filename' => $filename,
        ], $content);
    }
}
