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

use local_coursepilot\pointer_location;
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact fingerprint, URL, authentication and listing rules of the WebDAV instance resolver (Spec #486 §2, §5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_instance::class)]
final class webdav_instance_contract_test extends \advanced_testcase {
    use caught_throwable;
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Teacher with the WebDAV rights.
     *
     * @return \stdClass
     */
    private function teacher(): \stdClass {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        return $user;
    }

    /**
     * Removes one option row of an instance.
     *
     * @param int $instanceid
     * @param string $name
     */
    private function drop_option(int $instanceid, string $name): void {
        global $DB;
        $DB->delete_records('repository_instance_config', ['instanceid' => $instanceid, 'name' => $name]);
    }

    public function test_the_authentication_must_be_https_basic_and_both_settings_must_exist(): void {
        $user = $this->teacher();
        $good = $this->create_webdav_instance($user);
        $notype = $this->create_webdav_instance($user);
        $this->drop_option($notype, 'webdav_type');
        $other = $this->create_webdav_instance($user, ['webdav_type' => 2]);
        $digest = $this->create_webdav_instance($user, ['webdav_auth' => 'digest']);
        $noauth = $this->create_webdav_instance($user);
        $this->drop_option($noauth, 'webdav_auth');

        $this->assertTrue(webdav_instance::has_supported_auth($good));
        $this->assertFalse(webdav_instance::has_supported_auth($notype));
        $this->assertFalse(webdav_instance::has_supported_auth($other));
        $this->assertFalse(webdav_instance::has_supported_auth($digest));
        $this->assertFalse(webdav_instance::has_supported_auth($noauth));
    }

    public function test_the_fingerprint_is_read_from_its_own_instance_and_normalised(): void {
        $user = $this->teacher();
        $first = $this->create_webdav_instance($user, [
            'webdav_server' => 'one.example.test',
            'webdav_path' => '/Base/Sub/',
            'webdav_user' => 'alice',
        ]);
        $this->create_webdav_instance($user, [
            'webdav_server' => 'two.example.test',
            'webdav_path' => 'Other',
            'webdav_user' => 'bob',
        ]);
        $bare = $this->create_webdav_instance($user);
        foreach (['webdav_server', 'webdav_path', 'webdav_user'] as $name) {
            $this->drop_option($bare, $name);
        }

        $this->assertSame(
            ['server' => 'one.example.test', 'basepath' => 'Base/Sub', 'account' => 'alice'],
            webdav_instance::fingerprint_of($first)
        );
        $this->assertSame(['server' => '', 'basepath' => '', 'account' => ''], webdav_instance::fingerprint_of($bare));
    }

    public function test_a_pointer_fingerprint_is_compared_after_trimming_and_missing_parts_count_as_empty(): void {
        $user = $this->teacher();
        $instanceid = $this->create_webdav_instance($user, ['webdav_path' => '/Coursepilot/']);
        $trimmed = pointer_location::external($instanceid, 'x', ['server' => $this->fixtureserver,
            'basepath' => '//Coursepilot//', 'account' => $this->fixturekonto]);
        $partial = pointer_location::external($instanceid, 'x', ['server' => $this->fixtureserver]);

        $this->assertInstanceOf(resolved_webdav_instance::class, webdav_instance::resolve($trimmed));
        $e = $this->caught(fn() => webdav_instance::resolve($partial));
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('webdavfingerprintchanged', $e->errorcode);

        $bare = $this->create_webdav_instance($user, ['webdav_path' => '']);
        $this->drop_option($bare, 'webdav_path');
        $this->drop_option($bare, 'webdav_user');
        $emptyparts = pointer_location::external($bare, 'x', ['server' => $this->fixtureserver]);
        $this->assertInstanceOf(resolved_webdav_instance::class, webdav_instance::resolve($emptyparts));
    }

    /**
     * Provides option overrides and the expected directory URL of the instance root.
     *
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function base_urls(): array {
        return [
            'default port stored as zero' => [['webdav_port' => '0'], '', 'https://cloud.example.test/Coursepilot/'],
            'empty port' => [['webdav_port' => ''], '', 'https://cloud.example.test/Coursepilot/'],
            'custom port' => [['webdav_port' => '8443'], '', 'https://cloud.example.test:8443/Coursepilot/'],
            'path with slashes' => [['webdav_path' => '/a/b/'], '', 'https://cloud.example.test/a/b/'],
            'no path' => [['webdav_path' => ''], '', 'https://cloud.example.test/'],
            'missing port row' => [[], 'webdav_port', 'https://cloud.example.test/Coursepilot/'],
            'missing path row' => [[], 'webdav_path', 'https://cloud.example.test/'],
        ];
    }

    /**
     * The base URL has port and path exactly once.
     *
     * @param array<string, string> $overrides Option overrides.
     * @param string $drop Option row to remove.
     * @param string $expected Expected root URL.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('base_urls')]
    public function test_the_base_url_has_port_and_path_exactly_once(array $overrides, string $drop, string $expected): void {
        $user = $this->teacher();
        $instanceid = $this->create_webdav_instance($user, $overrides);
        if ($drop !== '') {
            $this->drop_option($instanceid, $drop);
        }
        $fingerprint = webdav_instance::fingerprint_of($instanceid);

        $resolved = webdav_instance::resolve(pointer_location::external($instanceid, 'x', $fingerprint));

        $this->assertSame($expected, $resolved->directory_url(''));
    }

    public function test_without_a_bound_transport_the_instance_credentials_are_used(): void {
        $user = $this->teacher();
        $instanceid = $this->create_webdav_instance($user, ['webdav_user' => 'alice', 'webdav_password' => 'secret-pw']);
        $missing = $this->create_webdav_instance($user);
        $this->drop_option($missing, 'webdav_user');
        $this->drop_option($missing, 'webdav_password');

        foreach ([[$instanceid, 'alice', 'secret-pw'], [$missing, '', '']] as [$id, $username, $password]) {
            $client = webdav_instance::resolve_owned($id)->client();
            $transport = (new \ReflectionProperty($client, 'transport'))->getValue($client);
            $this->assertInstanceOf(curl_transport::class, $transport);
            $this->assertSame($username, (new \ReflectionProperty($transport, 'username'))->getValue($transport));
            $this->assertSame($password, (new \ReflectionProperty($transport, 'password'))->getValue($transport));
        }
    }

    /**
     * Provides root listings and whether they are the five IServ areas.
     *
     * @return array<string, array{array<int, array{name: string, type: string}>, bool}>
     */
    public static function listings(): array {
        $folder = static fn(string $name): array => ['name' => $name, 'type' => 'folder'];
        $areas = array_map($folder, ['Windows', 'Files', 'Temp', 'Print', 'Groups']);
        return [
            'exactly the five areas in any order' => [$areas, true],
            'areas plus a plain file' => [array_merge($areas, [['name' => 'note.txt', 'type' => 'file']]), true],
            'a file named like an area' => [
                array_merge(array_slice($areas, 1), [['name' => 'Windows', 'type' => 'file']]),
                false,
            ],
            'one area missing' => [array_slice($areas, 1), false],
            'one folder too many' => [array_merge($areas, [$folder('Extra')]), false],
            'empty' => [[], false],
        ];
    }

    /**
     * Only the five IServ folders make an IServ root.
     *
     * @param array<int, array{name: string, type: string}> $entries Root listing.
     * @param bool $expected Expected answer.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('listings')]
    public function test_only_the_five_iserv_folders_make_an_iserv_root(array $entries, bool $expected): void {
        $this->assertSame($expected, webdav_instance::is_iserv_listing($entries));
    }

    public function test_a_pointer_fingerprint_with_numbers_is_compared_as_text(): void {
        $user = $this->teacher();
        $instanceid = $this->create_webdav_instance($user, ['webdav_server' => '1234', 'webdav_user' => '5678']);
        $pointer = pointer_location::external(
            $instanceid,
            'x',
            ['server' => 1234, 'basepath' => 'Coursepilot', 'account' => 5678]
        );

        $this->assertInstanceOf(resolved_webdav_instance::class, webdav_instance::resolve($pointer));
    }
}
