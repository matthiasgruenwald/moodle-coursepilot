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
 * Context writes (#408, Spec 0016 §4.1): normal operation and guards for
 * paths, extensions, size, concurrency, personal data and quota. External
 * storage (#491) covers conditional WebDAV writes with Nextcloud ETags
 * and IServ modification times, conflicts, missing folders, full storage
 * and independence from Moodle quotas and capabilities.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(write_context_file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\pointer_writer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\context_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\context_area::class)]
final class write_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Create new files and explicitly report creation so path typos are
     * visible in chat.
     */
    public function test_creates_new_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->write('plan.md', '# Plan');

        $this->assertTrue($result['created']);
        $this->assertSame(
            get_string('contextfilecreated', 'local_coursepilot', 'plan.md'),
            $result['message']
        );
        $this->assertSame('# Plan', $this->read_stored($user, '/coursepilot/', 'plan.md'));
        $this->assertSame(6, $result['size']);
    }

    /**
     * Create subfolders without a special case.
     */
    public function test_creates_file_in_subfolder(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->write('faecher/mathe/profil.md', '# Mathe');

        $this->assertSame('# Mathe', $this->read_stored($user, '/coursepilot/faecher/mathe/', 'profil.md'));
    }

    /**
     * Overwrite reports previous and new sizes (Spec 0016 §5.4).
     */
    public function test_overwrites_existing_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', 'alt');

        $result = $this->write('plan.md', '# Neuer Plan');

        $this->assertFalse($result['created']);
        $this->assertSame(
            get_string('contextfileoverwritten', 'local_coursepilot', (object) [
                'path' => 'plan.md',
                'before' => 3,
                'after' => 12,
            ]),
            $result['message']
        );
        $this->assertSame('# Neuer Plan', $this->read_stored($user, '/coursepilot/', 'plan.md'));
    }

    /**
     * Reject path segments outside [A-Za-z0-9_-].
     */
    public function test_rejects_invalid_path_segment(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('faecher/ma the/profil.md', '# Mathe');
    }

    /**
     * A ../ segment cannot escape the context area.
     */
    public function test_rejects_traversal(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('../plan.md', '# Plan');
    }

    /**
     * Accept only .md files; context storage contains no materials
     * (Spec 0016 §5.1).
     */
    public function test_rejects_non_markdown_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        // Assert the exact error key, not just any moodle_exception (#540).
        // Extension validation lives inside private_files_storage_port and the
        // new outage wrapper. Without context_area::is_moodle_call_error(), it
        // would return pendingwritefailed rather than contextfilenotmarkdown
        // and incorrectly record a pending entry.
        try {
            $this->write('notiz.txt', 'Text');
            $this->fail('Wrong file extension should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        }
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Reject operations exceeding 1 MB (Spec 0016 §5.2).
     */
    public function test_rejects_oversized_content(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('plan.md', str_repeat('x', 1024 * 1024 + 1));
    }

    /**
     * Accept exactly 1 MB; the limit is inclusive.
     */
    public function test_accepts_content_at_the_size_limit(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = $this->write('plan.md', str_repeat('x', 1024 * 1024));

        $this->assertSame(1024 * 1024, $result['size']);
    }

    /**
     * A matching contenthash permits writing.
     */
    public function test_accepts_matching_contenthash(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', 'alt');

        $result = $this->write('plan.md', 'neu', sha1('alt'));

        $this->assertFalse($result['created']);
    }

    /**
     * A mismatched hash aborts atomically, preserving the file.
     */
    public function test_rejects_stale_contenthash_and_leaves_file_untouched(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', 'zwischendurch von Hand geaendert');

        try {
            $this->write('plan.md', 'neu', sha1('alt'));
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        $this->assertSame(
            'zwischendurch von Hand geaendert',
            $this->read_stored($user, '/coursepilot/', 'plan.md')
        );
    }

    /**
     * A hash for a deleted or missing file is also a conflict.
     */
    public function test_rejects_contenthash_for_missing_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->write('plan.md', 'neu', sha1('alt'));
    }

    /**
     * Reject marked personal data when #344 is disabled; create no file.
     */
    public function test_rejects_personal_data_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        try {
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Personal data should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('personenbezug', strtolower($e->getMessage()));
        }

        $this->assertNull($this->stored_file($user, '/coursepilot/', 'lerngruppe.md'));
    }

    /**
     * Files blocked from reading must also be protected from overwriting
     * to prevent destructive bypass of #344 (Spec 0016 §4.2).
     */
    public function test_rejects_overwriting_a_marked_file_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        try {
            $this->write('lerngruppe.md', '# harmlos');
            $this->fail('Overwriting should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->read_stored($user, '/coursepilot/', 'lerngruppe.md')
        );
    }

    /**
     * Allow the same content when the switch is enabled.
     */
    public function test_accepts_personal_data_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $content = $this->marked_content();
        $this->write('lerngruppe.md', $content);

        $this->assertSame($content, $this->read_stored($user, '/coursepilot/', 'lerngruppe.md'));
    }

    /**
     * Reject marked personal data on unapproved external storage even with
     * #344 enabled (#493, ADR 0021 §3); perform no PUT.
     */
    public function test_rejects_marked_content_at_disallowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        try {
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Disallowed storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilehostnotallowed', $e->errorcode);
        }

        $this->assertSame([], array_values(array_filter(
            $fake->requests(),
            static fn (array $r): bool => $r['method'] === 'PUT'
        )));
    }

    /**
     * Allow marked files on approved domains and subdomains.
     */
    public function test_accepts_marked_content_at_allowed_external_host(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        set_config('personaldatahosts', 'example.test', 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $result = $this->write('lerngruppe.md', $this->marked_content());

        $this->assertTrue($result['created']);
    }

    /**
     * With #344 disabled, contextfilelocked takes precedence over storage
     * approval. These are independent checks.
     */
    public function test_marked_content_at_disallowed_host_with_switch_off_reports_locked(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        try {
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Personal data should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }
    }

    /**
     * External targets blocked from reading must also be protected from
     * overwriting, like {@see test_rejects_overwriting_a_marked_file_when_switch_off()}
     * for Moodle (#515, Spec #486 §6). Previously the external path passed
     * unmarked replacement content directly to webdav_storage_port::write().
     */
    public function test_rejects_overwriting_a_marked_external_file_when_switch_off(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        try {
            $this->write('lerngruppe.md', '# harmlos');
            $this->fail('Overwriting should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->external_content($fake, '/Coursepilot/Kontext/lerngruppe.md')
        );
        $this->assertSame([], array_values(array_filter(
            $fake->requests(),
            static fn (array $r): bool => $r['method'] === 'PUT'
        )));
    }

    /**
     * A stale mark cache must not bypass the lock. Inspect actual external
     * target content, not the cached flag (#515, criterion 3).
     */
    public function test_stale_mark_memory_cannot_bypass_the_external_lock(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $seeded = $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        // The cache says unmarked for this exact path/size/time/ETag key.
        // A cache-dependent lock would incorrectly allow overwrite.
        \local_coursepilot\mark_memory::remember(
            'lerngruppe.md',
            strlen($this->marked_content()),
            $seeded['lastmodified'],
            $seeded['etag'],
            false
        );

        try {
            $this->write('lerngruppe.md', '# harmlos');
            $this->fail('The stale marker memory must not have overridden the lock.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->external_content($fake, '/Coursepilot/Kontext/lerngruppe.md')
        );
    }

    /**
     * External append already checks the target when the switch is off
     * (#515, criterion 2). See
     * {@see \local_coursepilot\external\append_context_file::execute_external()}.
     */
    public function test_appending_to_a_marked_external_file_when_switch_off_is_rejected_too(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        try {
            append_context_file::execute('lerngruppe.md', "\n- Notiz");
            $this->fail('Appending should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->external_content($fake, '/Coursepilot/Kontext/lerngruppe.md')
        );
    }

    /**
     * Unmarked content may use any storage; personaldatahosts applies
     * only to marked files.
     */
    public function test_unmarked_content_ignores_host_allowlist(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $result = $this->write('plan.md', '# Unmarkiert');

        $this->assertTrue($result['created']);
    }

    /**
     * Require moodle/user:manageownfiles for writes (Spec 0016 §1.1).
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
        $this->write('plan.md', '# Plan');
    }

    /**
     * Quota errors report remaining MB (Spec 0016 §1.3) and link to
     * location selection (#491, Spec #486 §6).
     */
    public function test_rejects_when_user_quota_exceeded(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->userquota = 1024;
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            $this->write('plan.md', str_repeat('x', 2048));
            $this->fail('Quota overrun should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('MB', $e->getMessage());
            $this->assertStringContainsString(
                \local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE,
                $e->getMessage()
            );
        }
    }

    /**
     * External {@see write_context_file} creates with If-None-Match: *
     * (#491, Spec #486 §4/§6). The Nextcloud fake supplies ETags.
     */
    public function test_creates_new_external_file_with_if_none_match_star(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $result = $this->write('plan.md', '# Plan');

        $this->assertTrue($result['created']);
        $this->assertSame('plan.md', $result['path']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame('*', $puts[0]['headers']['If-None-Match'] ?? null);
        $this->assertArrayNotHasKey('If-Match', $puts[0]['headers']);
    }

    /**
     * External {@see write_context_file} overwrites with If-Match: <ETag>
     * in Nextcloud mode.
     */
    public function test_overwrites_existing_external_file_with_if_match(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $seeded = $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $result = $this->write('plan.md', '# Neuer Plan');

        $this->assertFalse($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertSame($seeded['etag'], $puts[0]['headers']['If-Match'] ?? null);
    }

    /**
     * IServ without ETags uses getlastmodified as a weaker substitute
     * without changing the caller’s tool contract.
     */
    public function test_overwrites_existing_external_file_without_etag_iserv_mode(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $result = $this->write('plan.md', '# Neuer Plan');

        $this->assertFalse($result['created']);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);
        $this->assertArrayNotHasKey('If-Match', $puts[0]['headers']);
    }

    /**
     * Concurrency protection (#513, Spec #486 §4/§6): simulate another chat
     * editing directly in fake storage between read and write, before the
     * request. The earlier expected_contenthash is stale; return a conflict
     * and preserve the intervening edit rather than the attempted content.
     */
    public function test_stale_checkvalue_from_earlier_read_is_rejected_as_conflict(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $gelesen = read_context_file::execute('plan.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        // The second chat writes before the first begins its write request.
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'handaenderung');

        try {
            $this->write('plan.md', '# Neuer Plan', $gelesen['contenthash']);
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        $this->assertSame('handaenderung', $this->external_content($fake, '/Coursepilot/Kontext/plan.md'));
    }

    /**
     * A matching current check value permits overwriting (#513).
     */
    public function test_matching_checkvalue_allows_overwrite(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $gelesen = read_context_file::execute('plan.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $result = $this->write('plan.md', '# Neuer Plan', $gelesen['contenthash']);

        $this->assertFalse($result['created']);
        $this->assertSame('# Neuer Plan', $this->external_content($fake, '/Coursepilot/Kontext/plan.md'));
    }

    /**
     * Without an ETag, compare modification times for weaker concurrency
     * protection (#513, Spec §4).
     */
    public function test_stale_checkvalue_without_etag_is_rejected_as_conflict_iserv_mode(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $gelesen = read_context_file::execute('plan.md');
        $gelesen = external_api::clean_returnvalue(read_context_file::execute_returns(), $gelesen);

        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'handaenderung');

        try {
            $this->write('plan.md', '# Neuer Plan', $gelesen['contenthash']);
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }
    }

    /**
     * Pending-entry replay never overwrites unchecked (#513). Missing check
     * values for existing targets cause conflicts instead of replacing
     * changed content.
     */
    public function test_ausstand_retry_without_checkvalue_is_rejected_when_file_exists(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['entries'][0]['identifier'];

        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        $fake2->seed_file('/Coursepilot/Kontext/plan.md', 'inzwischen gewachsen');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake2);

        try {
            write_context_file::execute('plan.md', '# Plan', '', $kennung);
            $this->fail('Backfilling without a check value should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }
        $this->assertSame('inzwischen gewachsen', $this->external_content($fake2, '/Coursepilot/Kontext/plan.md'));
    }

    /**
     * Replay to a still-missing target needs no check value; creation is
     * already protected by If-None-Match: *.
     */
    public function test_ausstand_retry_creates_missing_file_without_checkvalue(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['entries'][0]['identifier'];

        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake2);

        $result = write_context_file::execute('plan.md', '# Plan', '', $kennung);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Create missing intermediate folders with MKCOL (#491, Spec #486 §4).
     */
    public function test_creates_missing_folder_levels_via_mkcol(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $this->write('faecher/mathe/profil.md', '# Mathe');

        $mkcols = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MKCOL'));
        $this->assertNotEmpty($mkcols);
        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertCount(1, $puts);

        // MKCOL never targets /Coursepilot/Kontext itself, only its subfolders
        // (#514, criterion 3).
        $roottargets = array_filter($mkcols, static function (array $r): bool {
            return rtrim((string) parse_url($r['url'], PHP_URL_PATH), '/') === '/Coursepilot/Kontext';
        });
        $this->assertSame([], array_values($roottargets));
    }

    /**
     * Missing, moved or renamed external context roots prevent all
     * creation, including subfolders (#514, criteria 1+3). Perform no PUT
     * or MKCOL; return a named error and record a pending entry.
     */
    public function test_rejects_write_when_context_root_is_missing_and_creates_no_folder(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        // Do not seed /Coursepilot/Kontext; the root is intentionally missing.

        $message = '';
        try {
            $this->write('faecher/mathe/profil.md', '# Mathe');
            $this->fail('Missing context-area root should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $this->assertStringContainsString('location selection page', $message);

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('contextrootmissing', $ausstaende[0]['entries'][0]['error_class']);

        $this->assertSame([], array_values(array_filter(
            $fake->requests(),
            static fn (array $r): bool => in_array($r['method'], ['PUT', 'MKCOL'], true)
        )));
    }

    /**
     * HTTP 412 produces a conflict with reread-and-merge instructions,
     * preserves content and creates no pending entry (#491, Spec #486 §4/§6).
     */
    public function test_external_conflict_reports_konflikt_and_leaves_content_unchanged(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $decorator = new \local_coursepilot\tests\webdav\stale_read_transport($fake, '/Coursepilot/Kontext/plan.md', $fake);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $decorator);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        // The failed PUT preserves the intervening manual edit simulated by
        // the decorator between reading and writing.
        $this->assertSame('handaenderung', $this->external_content($fake, '/Coursepilot/Kontext/plan.md'));
    }

    /**
     * Creating through webdav_storage_port::write() first observes a missing
     * file, then uses put_new() with If-None-Match: *. If another writer creates
     * it meanwhile, return 412 and preserve that file (#491).
     */
    public function test_external_create_conflicts_when_file_appears_meanwhile(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $decorator = new \local_coursepilot\tests\webdav\stale_read_transport($fake, '/Coursepilot/Kontext/plan.md', $fake);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $decorator);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        $this->assertSame('handaenderung', $this->external_content($fake, '/Coursepilot/Kontext/plan.md'));
    }

    /**
     * Full external storage (507) is an outage (#492, ADR 0023). Report
     * path, operation, teacher-facing cause, unsaved status with identifier,
     * AI instructions and connection name/host, never raw HTTP details.
     * Also record a pending entry.
     */
    public function test_external_storage_full_records_ausstand_with_five_part_message(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['path']);
        $this->assertCount(1, $ausstaende[0]['entries']);
        $this->assertSame('create', $ausstaende[0]['entries'][0]['operation']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::STORAGE_FULL,
            $ausstaende[0]['entries'][0]['error_class']
        );

        // Check variable values independently of the resolved language pack
        // (English here; see test_german_messages_carry_the_required_wording()).
        $kennung = $ausstaende[0]['entries'][0]['identifier'];
        $this->assertStringContainsString('plan.md', $message);
        $this->assertStringContainsString('create', $message);
        $this->assertStringContainsString($kennung, $message);
        $this->assertStringContainsString('Meine Cloud', $message);
        $this->assertStringContainsString($this->fixtureserver, $message);
        // Secret-leak test (Spec #486): no HTTP code, response body or password.
        $this->assertStringNotContainsString('507', $message);
        $this->assertStringNotContainsString($fake->secret(), $message);
    }

    /**
     * Authentication rejected during personal-data preflight (401) must
     * not abort without a pending entry (#505 finding 10). The actual write
     * encounters the same outage and handles it completely.
     */
    public function test_external_write_records_ausstand_on_login_rejected_during_preread(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');
        $fake->deny_auth();

        try {
            $this->write('plan.md', '# Neu');
            $this->fail('Rejected login should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('plan.md', $ausstaende[0]['path']);
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::AUTH_REJECTED,
            $ausstaende[0]['entries'][0]['error_class']
        );
    }

    /**
     * Failed preflight authentication leaves prior existence unknown (#561).
     * Unlike test_external_write_records_ausstand_on_login_rejected_during_preread,
     * this target never existed. A normal write without create_only must
     * not incorrectly record an overwrite.
     */
    public function test_preread_failure_on_a_never_written_path_records_unknown_operation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->deny_auth();

        try {
            $this->write('plan.md', '# Neu');
            $this->fail('Rejected login should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(
            \local_coursepilot\pending_write_translation::OP_UNKNOWN,
            $ausstaende[0]['entries'][0]['operation']
        );
    }

    /**
     * Overwriting an existing external file records overwrite rather than
     * create in its pending entry.
     */
    public function test_external_overwrite_failure_records_ueberschreiben_operation(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        // Only PUT fails. MKCOL and existence-check PROPFIND succeed, allowing
        // create/overwrite classification. fill_storage() instead blocks MKCOL
        // before existence is known; test_external_storage_full_records_ausstand_with_five_part_message
        // uses that behavior for the creation case.
        $onlyputfails = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the write context file test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    return new \local_coursepilot\webdav\webdav_response(507, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $onlyputfails);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('overwrite', $ausstaende[0]['entries'][0]['operation']);
    }

    /**
     * Deleted WebDAV instances create pending entries, not merely a
     * webdav_error (ADR 0023).
     */
    public function test_deleted_webdav_instance_records_ausstand(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $pointerlocation = context_files::resolve_pointer_location();
        $DB->delete_records('repository_instances', ['id' => $pointerlocation->instanceid]);

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Deleted instance should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdavinstancemissing', $ausstaende[0]['entries'][0]['error_class']);
        // A deleted instance requires action at storage rather than later retry (#516).
        $this->assertStringContainsString('something needs to be done on your storage', $message);
    }

    /**
     * Revoked WebDAV authorization also creates a pending entry (ADR 0023).
     */
    public function test_revoked_webdav_freischaltung_records_ausstand(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $DB->delete_records('role_capabilities', ['capability' => 'repository/webdav:view']);
        accesslib_clear_all_caches_for_unit_testing();

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Revoked enablement should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('webdavnotenabled', $ausstaende[0]['entries'][0]['error_class']);
        // Revoked authorization requires action at storage rather than later retry (#516).
        $this->assertStringContainsString('something needs to be done on your storage', $message);
    }

    /**
     * Changed verification markers also create pending entries (ADR 0023).
     */
    public function test_changed_fingerprint_records_ausstand(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $DB->set_field(
            'repository_instance_config',
            'value',
            'anderer-server.test',
            ['name' => 'webdav_server']
        );

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Changed check attribute should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame('webdavfingerprintchanged', $ausstaende[0]['entries'][0]['error_class']);
        // Changed verification markers require action at storage rather than later retry (#516).
        $this->assertStringContainsString('something needs to be done on your storage', $message);
    }

    /**
     * Rejected authentication (401/403, ADR 0022) advises action at the
     * storage location (#516).
     */
    public function test_auth_rejected_classifies_as_etwas_zu_tun(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $onlyputfails401 = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the write context file test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    return new \local_coursepilot\webdav\webdav_response(401, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $onlyputfails401);

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Rejected login should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::AUTH_REJECTED,
            $ausstaende[0]['entries'][0]['error_class']
        );
        $this->assertStringContainsString('something needs to be done on your storage', $message);
    }

    /**
     * Unreachable storage (timeout/DNS, ADR 0022) advises later replay (#516).
     */
    public function test_unreachable_classifies_as_spaeter_nachtragen(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        // Only PUT fails. Existence and personal-data PROPFIND reads work so
        // the request reaches the actual write rather than failing on a read.
        $onlyputfails = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the write context file test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    throw new \local_coursepilot\webdav\webdav_transport_exception('DNS-Aufloesung fehlgeschlagen (Simuliert).');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $onlyputfails);

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Unreachable storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::UNREACHABLE,
            $ausstaende[0]['entries'][0]['error_class']
        );
        $this->assertStringContainsString('can be added later', $message);
    }

    /**
     * Unclear or throttled responses (unnamed statuses, ADR 0022) advise
     * later replay after silent retries lasting at most 5 seconds (#516).
     */
    public function test_unclear_classifies_as_spaeter_nachtragen(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        // Only PUT fails, see test_unreachable_classifies_as_spaeter_nachtragen().
        $onlyputfails = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the write context file test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'PUT') {
                    return new \local_coursepilot\webdav\webdav_response(500, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $onlyputfails);

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Unclear storage state should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(
            \local_coursepilot\webdav\webdav_error::UNCLEAR,
            $ausstaende[0]['entries'][0]['error_class']
        );
        $this->assertStringContainsString('can be added later', $message);
    }

    /**
     * IServ check 8 rejects paths outside Files/ with a pending entry,
     * just like checks 2–6 (#497/#516, Spec #486 §2/§8).
     */
    public function test_iserv_pruefung_8_records_ausstand_on_write(): void {
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

        $message = '';
        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Path outside "Files/" should have been rejected.');
        } catch (\moodle_exception $e) {
            $message = $e->getMessage();
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertCount(1, $ausstaende);
        $this->assertSame('webdaviservfilesonly', $ausstaende[0]['entries'][0]['error_class']);
        $this->assertSame('create', $ausstaende[0]['entries'][0]['operation']);
        $this->assertStringContainsString('something needs to be done on your storage', $message);
        // No network access: check 8 fails during pointer resolution before WebDAV requests.
        $this->assertSame([], $fake->requests());
    }

    /**
     * Invalid paths remain request errors even if IServ check 8 would fail
     * the location (#541 review). Validate paths before translating location
     * failures; never record pending content that could not be written.
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
            $this->write('notiz.txt', 'Inhalt');
            $this->fail('A disallowed extension should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
        $this->assertSame([], $fake->requests());
    }

    /**
     * Personal-data validation remains active when check 8 makes a location
     * unresolvable (#516 review). Marked content with the switch off is
     * a location-independent request error, not a pending entry.
     */
    public function test_iserv_pruefung_8_still_rejects_marked_content_when_switch_off(): void {
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
            $this->write('lerngruppe.md', $this->marked_content());
            $this->fail('Personal content with the switch off should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
        $this->assertSame([], $fake->requests());
    }

    /**
     * Every pending entry includes course ID, never content, hashes or
     * server details (#516).
     */
    public function test_ausstand_entry_carries_course_id(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            write_context_file::execute('plan.md', '# Plan', '', '', false, 42);
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }

        $ausstaende = \local_coursepilot\pending_write_notice::list_grouped();
        $this->assertSame(42, $ausstaende[0]['entries'][0]['course_id']);
    }

    /**
     * Conflicts (412) explicitly create no pending entry (ADR 0023 §2).
     */
    public function test_external_conflict_records_no_ausstand_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        $decorator = new \local_coursepilot\tests\webdav\stale_read_transport($fake, '/Coursepilot/Kontext/plan.md', $fake);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $decorator);

        try {
            $this->write('plan.md', '# Neuer Plan');
            $this->fail('Conflict should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Request errors, such as oversized content, create no pending entry;
     * the content remains in the conversation (ADR 0023 §2).
     */
    public function test_call_error_records_no_ausstand_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $this->expectException(\moodle_exception::class);
        try {
            $this->write('plan.md', str_repeat('x', 1024 * 1024 + 1));
        } finally {
            $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
        }
    }

    /**
     * A successful write with pending_entry=<id> marks the entry complete
     * within the same request (ADR 0023 §3).
     */
    public function test_ausstand_parameter_dismisses_entry_on_successful_retry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fill_storage();

        try {
            $this->write('plan.md', '# Plan');
            $this->fail('Full storage should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingwritefailed', $e->errorcode);
        }
        $kennung = \local_coursepilot\pending_write_notice::list_grouped()[0]['entries'][0]['identifier'];

        // Replace the full-storage fake to simulate storage responding again.
        $fake2 = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake2->seed_folder('/Coursepilot/Kontext');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake2);

        $result = write_context_file::execute('plan.md', '# Plan', '', $kennung);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * External remaining quota reports no limit; Moodle quotas do not
     * apply (#491, Spec #486 §6).
     */
    public function test_external_write_ignores_moodle_quota(): void {
        global $CFG;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $CFG->userquota = 1;

        $result = $this->write('plan.md', str_repeat('x', 4096));

        $this->assertTrue($result['created']);
    }

    /**
     * moodle/user:manageownfiles does not apply externally (#491, Spec #486 §6).
     */
    public function test_external_write_succeeds_without_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $result = $this->write('plan.md', '# Plan');

        $this->assertTrue($result['created']);
    }

    /**
     * Provides external content.
     *
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
     * The German language pack explicitly describes creation or overwrite
     * and previous/new sizes (Spec 0016 §5.4). Inspect it directly because
     * PHPUnit resolves only English.
     */
    public function test_german_messages_carry_the_required_wording(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');

        $this->assertStringContainsString('neu angelegt', $string['contextfilecreated']);
        $this->assertStringContainsString('überschrieben', $string['contextfileoverwritten']);
        $this->assertStringContainsString('{$a->before}', $string['contextfileoverwritten']);
        $this->assertStringContainsString('{$a->after}', $string['contextfileoverwritten']);
        $this->assertStringContainsString('neu lesen', $string['contextfilechanged']);
        $this->assertStringContainsString('MB', $string['contextquotaexceeded']);

        // Five-part outage message (#492, ADR 0023): path/operation, cause,
        // unsaved status with ID, AI instruction, instance name/host.
        // German wording uses real umlauts.
        $this->assertStringContainsString('{$a->path}', $string['pendingwritefailed']);
        $this->assertStringContainsString('{$a->operation}', $string['pendingwritefailed']);
        $this->assertStringContainsString('{$a->reason}', $string['pendingwritefailed']);
        $this->assertStringContainsString('Noch nicht gespeichert', $string['pendingwritefailed']);
        $this->assertStringContainsString('Kennung {$a->identifier}', $string['pendingwritefailed']);
        $this->assertStringContainsString('pending_entry="{$a->identifier}"', $string['pendingwritefailed']);
        $this->assertStringContainsString('{$a->target}', $string['pendingwritefailed']);
        $this->assertStringContainsString('voll', $string['pendingnotewritefailed']);
        $this->assertStringContainsString('Speicherplatz', $string['pendingnotequotaexceeded']);

        // Part 4 explicitly names pending_entry= for replay and prohibits
        // choosing another storage location (#516).
        $this->assertStringContainsString('keinesfalls an einem anderen Ort ablegen', $string['pendingwritefailed']);

        // Teacher-facing German text never calls pending writes Ausstand (#516, CONTEXT.md).
        foreach (
            [
            'pendingwritefailed',
            'pendingnotewritefailed',
            'pendingnotequotaexceeded',
            'pendingunknown',
            'pendingdismissed',
            'storagelocationmarkerpending',
            ] as $key
        ) {
            $this->assertStringNotContainsString('Ausstand', $string[$key], "\"$key\" must not contain \"Ausstand\".");
        }

        // Unapproved-storage errors explicitly explain that this storage is
        // not approved for personal data (#493, ADR 0021 §3).
        $this->assertStringContainsString(
            'Dieser Speicher ist für personenbezogene Daten nicht zugelassen',
            $string['contextfilehostnotallowed']
        );
    }

    /**
     * The endpoint is registered in the Coursepilot service and allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_write_context_file',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_write_context_file',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_write_context_file'));
    }

    /**
     * No parameter selects contextid, itemid or component.
     */
    public function test_execute_parameters_expose_no_area_selector(): void {
        $this->assertSame(
            ['path', 'content', 'expected_contenthash', 'pending_entry', 'create_only', 'courseid'],
            array_keys(write_context_file::execute_parameters()->keys)
        );
    }

    /**
     * create_only protects existing Moodle files, guaranteeing safe copying
     * of old content without overwriting (#498, Spec #486 §9).
     */
    public function test_nur_anlegen_rejects_existing_moodle_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', 'alt');

        try {
            write_context_file::execute('plan.md', '# Neu', '', '', true);
            $this->fail('Overwriting should have been rejected with nur_anlegen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilealreadyexists', $e->errorcode);
        }

        $this->assertSame('alt', $this->read_stored($user, '/coursepilot/', 'plan.md'));
    }

    /**
     * create_only creates normally when the target is missing.
     */
    public function test_nur_anlegen_creates_new_moodle_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = write_context_file::execute('plan.md', '# Neu', '', '', true);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
    }

    /**
     * External create_only also prevents overwriting; no PUT to existing files.
     */
    public function test_nur_anlegen_rejects_existing_external_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'alt');

        try {
            write_context_file::execute('plan.md', '# Neu', '', '', true);
            $this->fail('Overwriting should have been rejected with nur_anlegen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilealreadyexists', $e->errorcode);
        }

        $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
        $this->assertSame([], $puts);
    }

    /**
     * For marked existing external targets with #344 off,
     * contextfilealreadyexists precedes contextfilelocked, matching Moodle
     * (#515). See {@see write_context_file::require_personal_data_allowed()}.
     */
    public function test_nur_anlegen_reports_already_exists_even_for_a_marked_external_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        try {
            write_context_file::execute('lerngruppe.md', '# Neu', '', '', true);
            $this->fail('Overwriting should have been rejected with nur_anlegen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilealreadyexists', $e->errorcode);
        }

        $this->assertSame(
            $this->marked_content(),
            $this->external_content($fake, '/Coursepilot/Kontext/lerngruppe.md')
        );
    }

    /**
     * A real external preflight read error aborts writing rather than
     * bypassing locks (#515). Unlike missing files (404), unclear errors
     * cannot imply success; see webdav_client’s no-silent-success rule.
     */
    public function test_peek_read_failure_aborts_the_write_instead_of_bypassing_the_lock(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $getfails = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the write context file test.
             *
             * @param fake_webdav_transport $inner The inner.
             */
            public function __construct(
                /** @var fake_webdav_transport The inner. */
                private readonly fake_webdav_transport $inner,
            ) {
            }

            /**
             * Provides request.
             *
             * @param string $method The method.
             * @param string $url The url.
             * @param array $headers The headers.
             * @param ?string $body The body.
             * @return \local_coursepilot\webdav\webdav_response
             */
            public function request(string $method, string $url, array $headers = [], ?string $body = null): \local_coursepilot\webdav\webdav_response {
                if ($method === 'GET') {
                    return new \local_coursepilot\webdav\webdav_response(503, [], '');
                }
                return $this->inner->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $getfails);

        $this->expectException(\moodle_exception::class);
        try {
            $this->write('lerngruppe.md', '# harmlos');
        } finally {
            $this->assertSame(
                $this->marked_content(),
                $this->external_content($fake, '/Coursepilot/Kontext/lerngruppe.md')
            );
        }
    }

    /**
     * User A always writes in their own context, never user B’s area.
     */
    public function test_writes_only_into_own_area(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teacherb);
        $this->write('plan.md', '# B');

        $this->assertNull($this->stored_file($teachera, '/coursepilot/', 'plan.md'));
        $this->assertSame('# B', $this->read_stored($teacherb, '/coursepilot/', 'plan.md'));
    }

    /**
     * Private Files replay also rejects missing check values for existing
     * targets (#540), matching
     * {@see test_ausstand_retry_without_checkvalue_is_rejected_when_file_exists()}
     * externally rather than silently replacing changed content.
     */
    public function test_moodle_ausstand_retry_without_checkvalue_is_rejected_when_file_exists(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', 'inzwischen gewachsen');

        try {
            write_context_file::execute('plan.md', '# Plan', '', 'IRGENDEINEKENNUNG');
            $this->fail('Backfilling without a check value should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('storageconflict', $e->errorcode);
        }

        $this->assertSame('inzwischen gewachsen', $this->read_stored($user, '/coursepilot/', 'plan.md'));
    }

    /**
     * Private Files replay to missing targets needs no check value, just
     * like external storage; no existing content can be overwritten.
     */
    public function test_moodle_ausstand_retry_creates_missing_file_without_checkvalue(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = write_context_file::execute('plan.md', '# Plan', '', 'IRGENDEINEKENNUNG');
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
    }

    /**
     * Successful replay completes the entry in the same call for Moodle
     * context storage too, regardless of the original outage location
     * (#540, criterion 3).
     */
    public function test_moodle_successful_write_dismisses_the_ausstand_entry(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $kennung = \local_coursepilot\pending_write_notice::record('plan.md', 'create', 'irgendeinefehlerklasse', 0);

        $result = write_context_file::execute('plan.md', '# Plan', '', $kennung);
        $result = external_api::clean_returnvalue(write_context_file::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * Returns validated endpoint response.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash
     * @return array Validated endpoint response.
     */
    private function write(string $path, string $content, string $expectedcontenthash = ''): array {
        $result = write_context_file::execute($path, $content, $expectedcontenthash);
        return external_api::clean_returnvalue(write_context_file::execute_returns(), $result);
    }

    /**
     * Provides marked content.
     *
     * @return string Content with the legacy frontmatter marking "personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n---\n# S. M., 7a";
    }

    /**
     * Provides stored file.
     *
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
     * Reads stored.
     *
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
     * Creates context file.
     *
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
