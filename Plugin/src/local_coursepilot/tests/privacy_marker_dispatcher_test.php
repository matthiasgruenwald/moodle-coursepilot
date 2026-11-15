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

use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Imported personal-data markers stay locked across upgrades and storage locations.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(personal_data::class)]
#[CoversClass(dispatcher::class)]
#[CoversFunction('xmldb_local_coursepilot_upgrade')]
final class privacy_marker_dispatcher_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /** @var string Authenticated administrator's access token. */
    private string $token;

    protected function setUp(): void {
        global $USER;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = connection::issue_code('privacy-test', (int) $USER->id, 'https://client.example/cb', $challenge);
        $this->token = connection::exchange_code($code, 'privacy-test', 'https://client.example/cb', $verifier)['access_token'];
        set_config('allowpersonaldata', 0, 'local_coursepilot');
    }

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Call the public MCP boundary, including external return validation.
     *
     * @param string $tool Tool name without prefix.
     * @param mixed[] $arguments Tool arguments.
     * @return mixed[] MCP result.
     */
    private function call(string $tool, array $arguments = []): array {
        $response = dispatcher::handle(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_' . $tool, 'arguments' => $arguments]],
            $this->token,
            ['origin' => null, 'pathinfo' => '', 'method' => 'POST']
        );
        $this->assertSame(200, $response['status']);
        return $response['body']['result'];
    }

    /**
     * Editor variants that the old parser cached as unmarked.
     *
     * @return array<string, array{string}>
     */
    public static function imported_markers(): array {
        return [
            'BOM' => ["\xEF\xBB\xBF---\npersonenbezug: true\n---\nText"],
            'blank line' => ["\n---\npersonenbezug: true\n---\nText"],
            'EOF' => ["---\npersonenbezug: true\n---"],
            'colon' => ["---\npersonenbezug : true\n---\nText"],
        ];
    }

    /**
     * The upgrade invalidates stale negative entries before the next listing.
     *
     * @param string $content Imported file content.
     */
    #[DataProvider('imported_markers')]
    public function test_upgrade_relocks_imported_files_and_all_write_paths(string $content): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/coursepilot/db/upgrade.php');
        private_files_storage_port::write_content('/coursepilot/', 'import.md', $content);
        $this->call('list_context_files');
        // Reproduce the persisted result of the pre-fix parser, with the real listing's cache key.
        $DB->set_field('local_coursepilot_context_mark', 'ismarked', 0);
        $cached = $this->call('list_context_files');
        $this->assertFalse($cached['structuredContent']['entries'][0]['locked']);
        set_config('version', 2026101801, 'local_coursepilot');

        $this->assertTrue(\xmldb_local_coursepilot_upgrade(2026101801));

        $listed = $this->call('list_context_files');
        $this->assertTrue($listed['structuredContent']['entries'][0]['locked']);
        $read = $this->call('read_context_file', ['path' => 'import.md']);
        $this->assertTrue($read['isError']);
        $this->assertSame(get_string('contextfilelocked', 'local_coursepilot', 'import.md'), $read['content'][0]['text']);
        foreach (['write_context_file', 'append_context_file'] as $tool) {
            $denied = $this->call($tool, ['path' => 'new.md', 'content' => $content]);
            $this->assertTrue($denied['isError']);
            $this->assertSame(get_string('contextfilelocked', 'local_coursepilot', 'new.md'), $denied['content'][0]['text']);
        }
    }

    /**
     * Both the disabled switch and the separate host approval stop external writes.
     *
     * @param string $content Imported file content.
     */
    #[DataProvider('imported_markers')]
    public function test_imported_markers_cannot_be_sent_to_an_unapproved_host(string $content): void {
        global $USER;
        $instanceid = $this->create_webdav_instance($USER);
        $this->write_v2_pointer($USER, 'context_area', $instanceid, 'Kontext');
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/Kontext');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        set_config('personaldatahosts', '', 'local_coursepilot');
        foreach ([0 => 'contextfilelocked', 1 => 'contextfilehostnotallowed'] as $allowed => $error) {
            set_config('allowpersonaldata', $allowed, 'local_coursepilot');
            foreach (['write_context_file', 'append_context_file'] as $tool) {
                $result = $this->call($tool, ['path' => 'new.md', 'content' => $content]);
                $this->assertTrue($result['isError']);
                $this->assertSame(get_string($error, 'local_coursepilot', 'new.md'), $result['content'][0]['text']);
            }
        }
        $listed = $this->call('list_context_files');
        $this->assertSame([], $listed['structuredContent']['entries']);
    }

    public function test_false_marker_remains_readable_and_writable(): void {
        $content = "\xEF\xBB\xBF\n---\npersonenbezug : false\n---";
        foreach (['write_context_file', 'append_context_file'] as $tool) {
            $written = $this->call($tool, ['path' => $tool . '.md', 'content' => $content]);
            $this->assertArrayNotHasKey('isError', $written);
            $read = $this->call('read_context_file', ['path' => $tool . '.md']);
            $this->assertSame($content, $read['structuredContent']['content']);
        }
        $listed = $this->call('list_context_files');
        $this->assertSame([false, false], array_column($listed['structuredContent']['entries'], 'locked'));
    }
}
