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

use local_coursepilot\tests\webdav\fake_webdav_transport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Value objects of the WebDAV layer: error picture, response headers, URL building.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_error::class)]
#[CoversClass(webdav_response::class)]
#[CoversClass(resolved_webdav_instance::class)]
final class webdav_value_objects_test extends \advanced_testcase {
    /**
     * Every error class has its own translated label.
     *
     * @param string $errorclass
     * @param string $stringkey
     */
    #[DataProvider('labelled_classes')]
    public function test_label_translates_each_error_class(string $errorclass, string $stringkey): void {
        $this->assertSame(get_string($stringkey, 'local_coursepilot'), webdav_error::label($errorclass));
    }

    /**
     * Error classes with their language string.
     *
     * @return array<string, array{string, string}>
     */
    public static function labelled_classes(): array {
        return [
            'unclear' => [webdav_error::UNCLEAR, 'webdaverrorunclear'],
            'not found' => [webdav_error::NOT_FOUND, 'webdaverrornotfound'],
            'auth' => [webdav_error::AUTH_REJECTED, 'webdaverrorauthrejected'],
            'unreachable' => [webdav_error::UNREACHABLE, 'webdaverrorunreachable'],
            'full' => [webdav_error::STORAGE_FULL, 'webdaverrorstoragefull'],
            'conflict' => [webdav_error::CONFLICT, 'webdaverrorconflict'],
            'blocked' => [webdav_error::BLOCKED, 'webdaverrorblocked'],
            'redirected' => [webdav_error::REDIRECTED, 'webdaverrorredirected'],
        ];
    }

    /**
     * An unknown class is returned as given, not hidden.
     */
    public function test_label_falls_back_to_the_identifier(): void {
        $this->assertSame('something_new', webdav_error::label('something_new'));
    }

    /**
     * The error keeps its class and uses it as message when none is given.
     */
    public function test_error_message_defaults_to_the_error_class(): void {
        $plain = new webdav_error(webdav_error::CONFLICT);
        $described = new webdav_error(webdav_error::CONFLICT, 'etag differs');

        $this->assertSame(webdav_error::CONFLICT, $plain->errorclass);
        $this->assertSame('conflict', $plain->getMessage());
        $this->assertSame('etag differs', $described->getMessage());
    }

    /**
     * "Not found" is the empty result, every other class goes through the failure builder.
     */
    public function test_empty_when_missing_returns_fallback_only_for_not_found(): void {
        $onfailure = static fn (webdav_error $e): \Throwable => new \RuntimeException('wrapped:' . $e->errorclass);

        $this->assertSame(
            ['empty'],
            webdav_error::empty_when_missing(new webdav_error(webdav_error::NOT_FOUND), ['empty'], $onfailure)
        );
        try {
            webdav_error::empty_when_missing(new webdav_error(webdav_error::AUTH_REJECTED), ['empty'], $onfailure);
            $this->fail('A non-missing error must be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('wrapped:auth_rejected', $e->getMessage());
        }
    }

    /**
     * Header lookup ignores the case of the requested name.
     */
    public function test_response_header_lookup_is_case_insensitive(): void {
        $response = new webdav_response(207, ['etag' => '"abc"'], '<body/>');

        $this->assertSame('"abc"', $response->header('ETag'));
        $this->assertNull($response->header('X-Missing'));
        $this->assertSame(207, $response->statuscode);
        $this->assertSame('<body/>', $response->body);
    }

    /**
     * Directory URLs end with a slash, file URLs do not; segments are encoded, the base is normalised.
     */
    public function test_resolved_instance_builds_encoded_urls(): void {
        $resolved = new resolved_webdav_instance('https://dav.example/base/', new fake_webdav_transport());

        $this->assertSame('https://dav.example/base/Mein%20Ordner/a%26b.md', $resolved->file_url('/Mein Ordner/a&b.md/'));
        $this->assertSame('https://dav.example/base/Mein%20Ordner/', $resolved->directory_url('Mein Ordner'));
        $this->assertSame('https://dav.example/base/', $resolved->directory_url(''));
        $this->assertSame('https://dav.example/base', $resolved->file_url('/'));
    }

    /**
     * The client built from the resolved instance talks through the injected transport.
     */
    public function test_resolved_instance_client_uses_the_given_transport(): void {
        $fake = new fake_webdav_transport();
        $fake->seed_file('/dav/notiz.md', 'Inhalt');
        $resolved = new resolved_webdav_instance('https://fake.example/dav/', $fake);

        $this->assertSame('Inhalt', $resolved->client()->get($resolved->file_url('notiz.md')));
    }
}
