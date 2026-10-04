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

use core_external\external_api;
use local_coursepilot\context_files;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Append to context files (#409, Spec 0016 §4.2): normal operation,
 * creation, personal-data marking, the soft 1 MB warning and atomicity.
 * External storage (#491) adds read-modify-write with If-Match and
 * mandatory rotation guidance.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(append_context_file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\context_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\context_area::class)]
final class append_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Appending grows an existing file and reports its new total size
     * (Spec 0016 §5.4).
     */
    public function test_appends_to_existing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'journal.md', "# Journal\n");

        $result = $this->append('journal.md', "- Stunde 1\n");

        $this->assertFalse($result['created']);
        $this->assertSame("# Journal\n- Stunde 1\n", $this->read_stored($user, '/coursepilot/', 'journal.md'));
        $this->assertSame(21, $result['size']);
        $this->assertSame(
            get_string('contextfileappended', 'local_coursepilot', (object) [
                'path' => 'journal.md',
                'size' => 21,
            ]),
            $result['message']
        );
    }

    /**
     * Create a missing target and explicitly report creation so a path typo
     * is visible in chat.
     */
    public function test_creates_missing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->append('journal.md', '# Journal');

        $this->assertTrue($result['created']);
        $this->assertSame(
            get_string('contextfilecreated', 'local_coursepilot', 'journal.md'),
            $result['message']
        );
        $this->assertSame('# Journal', $this->read_stored($user, '/coursepilot/', 'journal.md'));
    }

    /**
     * Path rules also apply to appending (Spec 0016 §5.1).
     */
    public function test_rejects_invalid_path_segment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('faecher/ma the/journal.md', 'x');
    }

    /**
     * A ../ segment cannot escape the context area.
     */
    public function test_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('../journal.md', 'x');
    }

    /**
     * Accept only .md files; context storage does not contain materials.
     */
    public function test_rejects_non_markdown_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        // Assert the exact error key, not just any moodle_exception (#540).
        // Without context_area::is_moodle_call_error(), outage handling would
        // incorrectly record a pending entry; see write_context_file_test.
        try {
            $this->append('notiz.txt', 'x');
            $this->fail('Wrong file extension should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        }
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Limit the appended content itself to 1 MB (Spec 0016 §5.2).
     */
    public function test_rejects_oversized_content(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->append('journal.md', str_repeat('x', 1024 * 1024 + 1));
    }

    /**
     * The target may grow beyond 1 MB. Append succeeds with rotation advice
     * rather than an error (Spec 0016 §5.2/§8.4).
     */
    public function test_oversized_target_file_gets_rotation_hint(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'journal.md', str_repeat('x', 1024 * 1024));

        $result = $this->append('journal.md', 'y');

        $this->assertSame(1024 * 1024 + 1, $result['size']);
        $this->assertStringContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
            $result['message']
        );
    }

    /**
     * Below the threshold, return no rotation warning.
     */
    public function test_small_file_has_no_rotation_hint(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'journal.md', 'kurz');

        $result = $this->append('journal.md', 'x');

        $this->assertStringNotContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
            $result['message']
        );
    }

    /**
     * Reject appends to files marked as personal data when the #344 switch
     * is off, preventing an append bypass (Spec 0016 §4.2).
     */
    public function test_rejects_marked_target_file_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        try {
            $this->append('lerngruppe.md', "\n- Notiz");
            $this->fail('Personal data should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->read_stored($user, '/coursepilot/', 'lerngruppe.md')
        );
    }

    /**
     * Allow the same append when the switch is enabled.
     */
    public function test_appends_to_marked_target_file_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $this->append('lerngruppe.md', "\n- Notiz");

        $this->assertSame(
            $this->marked_content() . "\n- Notiz",
            $this->read_stored($user, '/coursepilot/', 'lerngruppe.md')
        );
    }

    /**
     * A missing target has no frontmatter or personal-data marking.
     * Create it even when the switch is off (Spec 0016 §5.5).
     */
    public function test_creates_missing_file_without_frontmatter_check(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->append('lerngruppe.md', '# Lerngruppe');

        $this->assertTrue($result['created']);
    }

    /**
     * Reject appends to marked external targets on unapproved storage
     * (#493, ADR 0021 §3), even when the #344 switch is enabled.
     */
    public function test_rejects_append_to_marked_file_at_disallowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        try {
            $this->append('lerngruppe.md', "\n- Notiz");
            $this->fail('Disallowed storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }
    }

    /**
     * Reject new marked files on unapproved storage too: inspect the entire
     * resulting file (Spec #486 §6).
     */
    public function test_rejects_append_creating_marked_file_at_disallowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        try {
            $this->append('lerngruppe.md', $this->marked_content());
            $this->fail('Disallowed storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }
    }

    /**
     * Allow the same append on approved storage.
     */
    public function test_accepts_append_to_marked_file_at_allowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        set_config('personaldatahosts', 'example.test', 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $result = $this->append('lerngruppe.md', "\n- Notiz");

        $this->assertFalse($result['created']);
    }

    /**
     * Require moodle/user:manageownfiles to write (Spec 0016 §1.1).
     */
    public function test_rejects_missing_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        $this->append('journal.md', 'x');
    }

    /**
     * If the operation fails, for example due to user quota, preserve the
     * original target without partially appended content.
     */
    public function test_failed_append_leaves_target_file_untouched(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->userquota = 1024;
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'journal.md', 'alt');

        try {
            $this->append('journal.md', str_repeat('x', 2048));
            $this->fail('Quota overrun should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('MB', $e->getMessage());
        }

        $this->assertSame('alt', $this->read_stored($user, '/coursepilot/', 'journal.md'));
    }

    /**
     * Moodle storage reads, combines and writes within one server call
     * (Spec 0016 §4.2/§5.3), so no previously read version is required.
     * expected_contenthash applies only to external storage (#513, Spec #486
     * §6), where a prior read may occur. The original parameter-contract test
     * [path, content, pending_entry] predates this parameter.
     */
    public function test_execute_parameters_expose_expected_contenthash_for_the_external_branch(): void {
        $this->assertSame(
            ['path', 'content', 'pending_entry', 'expected_contenthash', 'courseid'],
            array_keys(append_context_file::execute_parameters()->keys)
        );
    }

    /**
     * Consecutive appends lose no data; each reads the current file itself.
     */
    public function test_consecutive_appends_accumulate(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->append('journal.md', 'a');
        $this->append('journal.md', 'b');
        $result = $this->append('journal.md', 'c');

        $this->assertSame('abc', $this->read_stored($user, '/coursepilot/', 'journal.md'));
        $this->assertSame(3, $result['size']);
    }

    /**
     * User A never appends to user B’s context area.
     */
    public function test_appends_only_into_own_area(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teacherb);
        $this->append('journal.md', '# B');

        $this->assertNull($this->stored_file($teachera, '/coursepilot/', 'journal.md'));
        $this->assertSame('# B', $this->read_stored($teacherb, '/coursepilot/', 'journal.md'));
    }

    /**
     * The German language pack must describe appended content, total size
     * and rotation guidance (Spec 0016 §5.4). Inspect the pack directly
     * because the PHPUnit instance resolves only English.
     */
    public function test_german_messages_carry_the_required_wording(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');

        $this->assertStringContainsString('angehängt', $string['contextfileappended']);
        $this->assertStringContainsString('{$a->size}', $string['contextfileappended']);
        $this->assertStringContainsString('Rotation', $string['contextfilerotation']);
    }

    /**
     * External {@see append_context_file} uses read-modify-write with If-Match
     * (#491, Spec #486 §4/§6).
     */
    public function test_appends_to_existing_external_file_with_if_match(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $seeded = $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");

        $result = $this->append('journal.md', "- Stunde 1\n");

        $this->assertFalse($result['created']);
        $this->assertSame(21, $result['size']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame($seeded['etag'], $puts[0]['headers']['If-Match'] ?? null);
        $this->assertSame("# Journal\n- Stunde 1\n", $puts[0]['body']);
    }

    /**
     * Concurrency protection (#513, Spec #486 §4/§6): simulate another chat
     * editing between read and append directly in fake storage before the
     * request, without a decorator.
     */
    public function test_stale_checkvalue_from_earlier_read_is_rejected_as_conflict(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");

        $gelesen = read_context_file::execute('journal.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n- Handaenderung\n");

        try {
            $this->append('journal.md', "- Stunde 1\n", $gelesen['contenthash']);
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        $this->assertSame(
            "# Journal\n- Handaenderung\n",
            $this->external_content($fake, '/Coursepilot/Kontext/journal.md')
        );
    }

    /**
     * A matching check value permits the append (#513).
     */
    public function test_matching_checkvalue_allows_append(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");

        $gelesen = read_context_file::execute('journal.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $this->append('journal.md', "- Stunde 1\n", $gelesen['contenthash']);

        $this->assertSame(
            "# Journal\n- Stunde 1\n",
            $this->external_content($fake, '/Coursepilot/Kontext/journal.md')
        );
    }

    /** A concurrent edit after preflight must not replace the checked/authorised target. */
    #[\PHPUnit\Framework\Attributes\DataProvider('concurrent_edits')]
    public function test_append_rejects_change_after_preflight(bool $withchecksum, bool $marked): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', "# Journal\n");
        $checksum = $withchecksum ? read_context_file::execute('journal.md')['contenthash'] : '';
        $replacement = $marked ? $this->marked_content() : '# Concurrent edit';
        $transport = new class($fake, $replacement) implements \local_coursepilot\webdav\webdav_transport {
            private bool $changed = false;

            public function __construct(private readonly fake_webdav_transport $inner, private readonly string $replacement) {
            }

            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                $response = $this->inner->request($method, $url, $headers, $body);
                if (!$this->changed && $method === 'GET' && str_ends_with($url, '/journal.md')) {
                    $this->changed = true;
                    $this->inner->seed_file('/Coursepilot/Kontext/journal.md', $this->replacement);
                }
                return $response;
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $transport);

        try {
            $this->append('journal.md', "\nAppend", $checksum);
            $this->fail('A changed target must be rejected instead of appending to an unchecked state.');
        } catch (\local_coursepilot\storage_conflict_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }
        $this->assertSame($replacement, $this->external_content($fake, '/Coursepilot/Kontext/journal.md'));
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    public static function concurrent_edits(): array {
        return [[true, false], [false, true]];
    }

    /**
     * Replaying pending_entry never overwrites unchecked (#513). If a target
     * exists but its check value is missing, return a conflict instead of
     * silently extending changed content.
     */
    public function test_ausstand_retry_without_checkvalue_is_rejected_when_file_exists(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['entries'][0]['identifier'];

        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        $fake2->seed_file('/Coursepilot/Kontext/journal.md', 'inzwischen gewachsen');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake2);

        try {
            append_context_file::execute('journal.md', 'x', $kennung);
            $this->fail('Backfilling without a check value should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }
        $this->assertSame('inzwischen gewachsen', $this->external_content($fake2, '/Coursepilot/Kontext/journal.md'));
    }

    /**
     * Create missing external targets with If-None-Match: *.
     */
    public function test_creates_missing_external_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $result = $this->append('journal.md', '# Journal');

        $this->assertTrue($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertSame('*', $puts[0]['headers']['If-None-Match'] ?? null);
    }

    /**
     * A missing external context root prevents all creation, as with writes
     * (#514). See
     * {@see \local_coursepilot\external\write_context_file_test::test_rejects_write_when_context_root_is_missing_and_creates_no_folder()}.
     */
    public function test_rejects_append_when_context_root_is_missing_and_creates_no_folder(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        // Do not seed /Coursepilot/Kontext; the root is intentionally missing.

        try {
            $this->append('journal.md', '# Journal');
            $this->fail('Missing context-area root should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('contextrootmissing', $ausstaende[0]['entries'][0]['error_class']);
        $this->assertSame([], array_values(array_filter(
            $fake->requests(),
            static fn (array $r): bool => in_array($r['method'], ['PUT', 'MKCOL'], true)
        )));
    }

    /**
     * Create missing intermediate folders with MKCOL, but never create the
     * context root (#514, criteria 2+3), matching
     * {@see \local_coursepilot\external\write_context_file_test::test_creates_missing_folder_levels_via_mkcol()}.
     */
    public function test_creates_missing_folder_levels_via_mkcol_without_touching_the_root(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $this->append('faecher/mathe/journal.md', '# Mathe');

        $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
        $this->assertNotEmpty($mkcols);
        $roottargets = array_filter($mkcols, static function (array $r): bool {
            return rtrim((string) parse_url($r['url'], PHP_URL_PATH), '/') === '/Coursepilot/Kontext';
        });
        $this->assertSame([], array_values($roottargets));
    }

    /**
     * External rotation advice uses the same 1 MB threshold as Moodle storage
     * (#505 finding 9). Explicitly mention 1 MB; showing it for small files
     * would be misleading.
     */
    public function test_external_append_over_limit_carries_rotation_hint(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', str_repeat('x', 1024 * 1024));

        $result = $this->append('journal.md', 'y');

        $this->assertSame(1024 * 1024 + 1, $result['size']);
        $this->assertStringContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
            $result['message']
        );
    }

    /**
     * External files below the threshold receive no rotation advice
     * (#505 finding 9).
     */
    public function test_external_append_under_limit_has_no_rotation_hint(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/journal.md', 'kurz');

        $result = $this->append('journal.md', 'x');

        $this->assertStringNotContainsString(
            get_string('contextfilerotation', 'local_coursepilot'),
            $result['message']
        );
    }

    /**
     * External storage full (507) is an outage (#492, ADR 0023). Record
     * append rather than create or overwrite and return the five-part
     * outage response.
     */
    public function test_external_append_storage_full_records_ausstand(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
            $this->assertStringContainsString('journal.md', $e->getMessage());
            $this->assertStringContainsString('identifier', $e->getMessage());
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('journal.md', $ausstaende[0]['path']);
        $this->assertSame('append', $ausstaende[0]['entries'][0]['operation']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['entries'][0]['error_class']
        );
    }

    /**
     * Rejected authentication (401) during the personal-data preflight read
     * must not abort without a pending entry (#505 finding 10). The actual
     * write encounters the same outage and handles it completely, as on overwrite.
     */
    public function test_external_append_records_ausstand_on_login_rejected_during_preread(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->deny_auth();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Rejected login should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('journal.md', $ausstaende[0]['path']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::AUTH_REJECTED,
            $ausstaende[0]['entries'][0]['error_class']
        );
    }

    /**
     * A deleted WebDAV instance during preflight also creates a pending entry
     * (#505 finding 10). Unlike overwriting, the append preflight previously
     * used a read branch without outage tolerance.
     */
    public function test_external_append_records_ausstand_on_deleted_instance(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $pointerlocation = context_files::resolve_pointer_location();
        $DB->delete_records('repository_instances', ['id' => $pointerlocation->instanceid]);

        try {
            $this->append('journal.md', 'x');
            $this->fail('Deleted instance should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdavinstancemissing', $ausstaende[0]['entries'][0]['error_class']);
    }

    /**
     * Every pending entry includes the course ID, including appends (#516).
     */
    public function test_ausstand_entry_carries_course_id(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            append_context_file::execute('journal.md', 'x', '', '', 42);
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(42, $ausstaende[0]['entries'][0]['course_id']);
    }

    /**
     * Check 8 (IServ area, #497/#516, Spec #486 §2/§8) also creates a pending
     * entry during append.
     */
    public function test_iserv_pruefung_8_records_ausstand_on_append(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Groups/Klasse7a', [
            'server' => $this->fixtureserver,
            'basepath' => $this->fixturebasispfad,
            'account' => $this->fixturekonto,
            'iserv' => true,
        ]);
        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            $this->append('journal.md', 'x');
            $this->fail('Path outside "Files/" should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdaviservfilesonly', $ausstaende[0]['entries'][0]['error_class']);
        $this->assertSame([], $fake->requests());
    }

    /**
     * An invalid path remains a request error even if IServ check 8 would
     * also reject the location. See write_context_file_test.php (#541 review).
     */
    public function test_iserv_pruefung_8_does_not_shadow_an_invalid_path(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Groups/Klasse7a', [
            'server' => $this->fixtureserver,
            'basepath' => $this->fixturebasispfad,
            'account' => $this->fixturekonto,
            'iserv' => true,
        ]);
        $fake = new fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        try {
            $this->append('notiz.txt', 'x');
            $this->fail('A disallowed extension should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
        $this->assertSame([], $fake->requests());
    }

    /**
     * pending_entry=<id> marks an entry complete after successful append
     * replay (ADR 0023 §3).
     */
    public function test_ausstand_parameter_dismisses_entry_on_successful_append(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->append('journal.md', 'x');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['entries'][0]['identifier'];

        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake2);

        $result = append_context_file::execute('journal.md', 'x', $kennung);
        $result = external_api::clean_returnvalue(append_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * External storage ignores moodle/user:manageownfiles and user quota
     * (#491, Spec #486 §6).
     */
    public function test_external_append_ignores_moodle_quota_and_capability(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $CFG->userquota = 1;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $result = $this->append('journal.md', str_repeat('x', 4096));

        $this->assertTrue($result['created']);
    }

    /**
     * The endpoint is registered in the Coursepilot service and allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_append_context_file',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_append_context_file',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_append_context_file'));
    }

    /**
     * @param string $path
     * @param string $content
     * @return array Validated endpoint response.
     */
    private function append(string $path, string $content, string $expectedcontenthash = ''): array {
        $result = append_context_file::execute($path, $content, '', $expectedcontenthash);
        return external_api::clean_returnvalue(append_context_file::execute_returns(), $result);
    }

    /**
     * @param fake_webdav_transport $fake
     * @param string $path
     * @return string
     */
    private function external_content(fake_webdav_transport $fake, string $path): string {
        // Read through the client like a real caller; fake internal storage has no public read access.
        $client = new \local_coursepilot\webdav\webdav_client($fake);
        return $client->get('https://fake.example' . $path);
    }

    /**
     * @return string Content with the legacy frontmatter marking "personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n---\n# S. M., 7a";
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @return \stored_file|null
     */
    private function stored_file(\stdClass $user, string $filepath, string $filename): ?\stored_file {
        $file = get_file_storage()->get_file(
            \context_user::instance($user->id)->id,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            $filepath,
            $filename
        );
        return $file ?: null;
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @return string
     */
    private function read_stored(\stdClass $user, string $filepath, string $filename): string {
        $file = $this->stored_file($user, $filepath, $filename);
        $this->assertNotNull($file, 'Expected file missing: ' . $filepath . $filename);
        return $file->get_content();
    }

    /**
     * @param \stdClass $user
     * @param string $filepath
     * @param string $filename
     * @param string $content
     */
    private function create_context_file(\stdClass $user, string $filepath, string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => context_files::COMPONENT,
            'filearea' => context_files::FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }
}
