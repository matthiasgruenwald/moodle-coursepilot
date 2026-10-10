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

use local_coursepilot\material_files;
use local_coursepilot\oauth_lib;
use local_coursepilot\workbench_ticket;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only tool for single-use workbench download links (#501, Spec #486 §13).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(create_workbench_download_links::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\oauth_lib::class)]
final class create_workbench_download_links_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        oauth_lib::reset_current_token_id();
    }

    public function test_returns_url_name_size_and_sha1_per_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt eins');
        $this->store('ordner/blatt2.pdf', 'inhalt zwei');

        $result = create_workbench_download_links::execute(['blatt.pdf', 'ordner/blatt2.pdf']);

        $this->assertCount(2, $result['links']);
        $this->assertSame('blatt.pdf', $result['links'][0]['name']);
        $this->assertSame(strlen('inhalt eins'), $result['links'][0]['size']);
        $this->assertSame(sha1('inhalt eins'), $result['links'][0]['sha1']);
        $this->assertStringContainsString('/local/coursepilot/workbench/download.php?ticket=', $result['links'][0]['url']);
        $this->assertSame('blatt2.pdf', $result['links'][1]['name']);
        $this->assertSame('blatt.pdf, ordner/blatt2.pdf', $result['path'], 'For the access_log: all paths comma-separated.');

        // Return only URL, name, size and SHA-1, never ready-made curl commands (Spec #486 §13).
        $this->assertSame(['path', 'name', 'size', 'sha1', 'url'], array_keys($result['links'][0]));
    }

    public function test_ticket_actually_delivers_the_same_bytes(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Without a known connection, redemption requires another active user
        // connection (#512). Like other tests, simulate the external function
        // without MCP dispatch; the ticket’s oauthtokenid remains null.
        $this->issue_connection((int) $user->id);
        oauth_lib::reset_current_token_id();
        $this->store('blatt.pdf', 'originalbytes');

        $link = create_workbench_download_links::execute(['blatt.pdf'])['links'][0];
        $query = parse_url($link['url'], PHP_URL_QUERY);
        parse_str((string) $query, $params);

        $delivery = workbench_ticket::redeem((string) $params['ticket']);

        $this->assertSame('originalbytes', $delivery['content']);
        $this->assertSame(sha1($delivery['content']), $link['sha1']);
    }

    public function test_rejects_path_outside_the_werkbank(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        create_workbench_download_links::execute(['../../../etc/passwd']);
    }

    public function test_rejects_missing_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        create_workbench_download_links::execute(['nichtvorhanden.pdf']);
    }

    /**
     * Provides issue connection.
     *
     * @param int $userid The userid.
     * @return int
     */
    private function issue_connection(int $userid): int {
        global $DB;

        $roleid = create_role('Remote access', 'remote' . $userid, '', '');
        assign_capability(\local_coursepilot\remote_access::CAPABILITY, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $userid, \context_system::instance()->id);
        $record = new \stdClass();
        $record->accesstokenhash = hash('sha256', oauth_lib::random_token(32));
        $record->refreshtokenhash = hash('sha256', oauth_lib::random_token(32));
        $record->clientid = 'test-client';
        $record->userid = $userid;
        $record->expires = time() + oauth_lib::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = time();
        $record->connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $record->userid, 'clientid' => $record->clientid, 'revoked' => $record->revoked,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $record->timecreated,
        ]);
        return (int) $DB->insert_record('local_coursepilot_oauth_token', $record);
    }

    /**
     * Stores the create workbench download links test.
     *
     * @param string $path The path.
     * @param string $content The content.
     */
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
