<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use core_external\external_api;
use local_coursepilot\context_files;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Context listing (#343): normal operation, path-escape attacks and
 * user isolation. External storage (#490) uses a v2 context pointer and
 * fake WebDAV transport.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(list_context_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\storage_anchor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\context_files::class)]
final class list_context_files_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * The template file is accessible at the root. Omitting path lists the root.
     */
    public function test_lists_root_including_template_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Vorlagen');
        $this->create_context_file($user, '/coursepilot/', 'index.md', '# Index');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('vorlagen.md', $names);
        $this->assertContains('index.md', $names);
    }

    /**
     * Each file has contenthash and timemodified (Spec 0016 §2). Folders
     * carry an empty hash and zero modification time.
     */
    public function test_file_entries_carry_contenthash_and_timemodified(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Vorlagen');
        $this->create_context_file($user, '/coursepilot/faecher/', 'profil.md', '# Profil');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $file = $this->find_entry($result['entries'], 'vorlagen.md');
        $this->assertSame(sha1('# Vorlagen'), $file['contenthash']);
        $this->assertGreaterThan(0, $file['timemodified']);

        $folder = $this->find_entry($result['entries'], 'faecher');
        $this->assertSame('', $folder['contenthash']);
        $this->assertSame(0, $folder['timemodified']);
    }

    /**
     * Subfolders appear at the root and can themselves be listed using path.
     */
    public function test_lists_subfolder_contents(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/faecher/mathe/', 'profil.md', '# Mathe');

        $root = list_context_files::execute();
        $root = external_api::clean_returnvalue(list_context_files::execute_returns(), $root);
        $folders = array_filter($root['entries'], fn($entry) => $entry['type'] === 'folder');
        $this->assertContains('faecher', array_column($folders, 'name'));

        $sub = list_context_files::execute('faecher/mathe');
        $sub = external_api::clean_returnvalue(list_context_files::execute_returns(), $sub);
        $this->assertContains('profil.md', array_column($sub['entries'], 'name'));
    }

    /**
     * Returned paths match tool inputs: relative to the context root, with
     * an empty path for the root. Previously including coursepilot caused
     * clients to build coursepilot/... and write to /coursepilot/coursepilot/
     * (#425 F1).
     */
    public function test_returned_path_is_relative_to_the_context_root(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/question-types/', 'match.md', '# match');

        $root = list_context_files::execute();
        $root = external_api::clean_returnvalue(list_context_files::execute_returns(), $root);
        $this->assertSame('', $root['path']);

        $sub = list_context_files::execute('question-types');
        $sub = external_api::clean_returnvalue(list_context_files::execute_returns(), $sub);
        $this->assertSame('question-types', $sub['path']);
    }

    /**
     * After moving to Private Files (#407), teachers can add arbitrary files
     * through My files. Listing does not read non-Markdown content and
     * leaves it unlocked; personal-data frontmatter exists only in Markdown.
     */
    public function test_non_markdown_files_are_listed_without_reading_them(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // This frontmatter marker does not apply: the file is not Markdown.
        $this->create_context_file($user, '/coursepilot/', 'notizen.txt', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'notizen.txt');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['locked']);
    }

    /**
     * Reject paths escaping the root at Moodle’s API parameter boundary
     * before resolution runs (CRITICAL).
     */
    public function test_traversal_attempt_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Outside /coursepilot/ but within the same file area: traversal must never expose it.
        $this->create_context_file($user, '/', 'secret.txt', 'geheim');

        $this->expectException(\moodle_exception::class);
        list_context_files::execute('../../secret');
    }

    /**
     * Even if future PARAM_PATH changes allow traversal through the API
     * boundary, context_files independently checks every segment.
     */
    public function test_resolved_directory_never_leaves_root(): void {
        $this->resetAfterTest();
        $this->assertStringStartsWith('/coursepilot/', context_files::resolve_directory('faecher/mathe'));
    }

    /**
     * User A cannot access user B’s context area; no parameter addresses
     * another user’s area (CRITICAL).
     */
    public function test_person_a_never_sees_person_bs_files(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $this->create_context_file($teachera, '/coursepilot/', 'lerngruppe-a.md', 'A');

        $this->setUser($teacherb);
        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $this->assertNotContains('lerngruppe-a.md', array_column($result['entries'], 'name'));
    }

    /**
     * No parameter accepts another file area, itemid or contextid.
     * Isolation is structural rather than conventional.
     */
    public function test_execute_parameters_expose_no_area_selector(): void {
        $definition = list_context_files::execute_parameters()->keys;
        $this->assertSame(['path', 'previous_location'], array_keys($definition));
    }

    /**
     * previous_location without pending old content is a named error,
     * not a silent empty result (#498, Spec #486 §6).
     */
    public function test_vorheriger_ort_switch_without_open_previouslocation_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            list_context_files::execute('', true);
            $this->fail('Without open legacy items the switch should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('previouslocationclosed', $e->errorcode);
        }
    }

    /**
     * With pending old content, the flag lists the previous location.
     */
    public function test_vorheriger_ort_switch_lists_the_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'aktuell.md', '# Aktuell');
        $this->create_context_file($user, '/previouslocation/', 'alt.md', '# Alt');
        $this->write_pointer_with_previous_location($user, 'previouslocation');

        $current = list_context_files::execute();
        $current = external_api::clean_returnvalue(list_context_files::execute_returns(), $current);
        $this->assertContains('aktuell.md', array_column($current['entries'], 'name'));

        $previous = list_context_files::execute('', true);
        $previous = external_api::clean_returnvalue(list_context_files::execute_returns(), $previous);
        $this->assertContains('alt.md', array_column($previous['entries'], 'name'));
        $this->assertNotContains('aktuell.md', array_column($previous['entries'], 'name'));
    }

    /**
     * Show locked entries visibly locked rather than omitting them
     * (#344, ADR 0011).
     */
    public function test_personal_data_marked_file_appears_locked_in_listing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'lerngruppe.md');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['locked']);
    }

    /**
     * Show the same entry unlocked when the switch is enabled.
     */
    public function test_personal_data_marked_file_unlocked_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'lerngruppe.md');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['locked']);
    }

    /**
     * Unmarked files remain unlocked with either switch setting.
     */
    public function test_unmarked_file_never_locked(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'sachtext.md', '# Sachtext ohne Frontmatter');

        $resultoff = list_context_files::execute();
        $resultoff = external_api::clean_returnvalue(list_context_files::execute_returns(), $resultoff);
        $this->assertFalse($this->find_entry($resultoff['entries'], 'sachtext.md')['locked']);

        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $resulton = list_context_files::execute();
        $resulton = external_api::clean_returnvalue(list_context_files::execute_returns(), $resulton);
        $this->assertFalse($this->find_entry($resulton['entries'], 'sachtext.md')['locked']);
    }

    /**
     * The context pointer (#445) is not a working file and stays out of
     * listings even though it physically shares the context folder.
     */
    public function test_pointer_file_is_excluded_from_listing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Vorlagen');
        $this->create_context_file(
            $user,
            '/coursepilot/',
            \local_coursepilot\storage_anchor::POINTER_FILENAME,
            '{"context_area":"coursepilot","materialordner":"coursepilot-material"}'
        );

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $names = array_column($result['entries'], 'name');
        $this->assertContains('vorlagen.md', $names);
        $this->assertNotContains(\local_coursepilot\storage_anchor::POINTER_FILENAME, $names);
    }

    /**
     * External context storage (#490, Spec #486 §2/§6) lists through WebDAV
     * with the same tool-response contract as Moodle storage.
     */
    public function test_lists_external_context_files_via_webdav(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $this->assertContains('vorlagen.md', array_column($result['entries'], 'name'));
    }

    /**
     * External listing returns a nonempty check value for expected_contenthash
     * so writes can actually detect conflicts (#513, Spec #486 §4/§6).
     */
    public function test_external_listing_returns_a_nonempty_checkvalue(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'vorlagen.md');
        $this->assertNotNull($entry);
        $this->assertNotSame('', $entry['contenthash']);
    }

    /**
     * Without an ETag (IServ), use modification time as a weaker, nonempty
     * check value (#513, Spec §4).
     */
    public function test_external_listing_returns_a_nonempty_checkvalue_without_etag(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'vorlagen.md');
        $this->assertNotNull($entry);
        $this->assertNotSame('', $entry['contenthash']);
    }

    /**
     * Missing external folders produce an empty listing, just like a
     * missing Moodle root.
     */
    public function test_listing_missing_external_directory_is_empty(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        // Leave /Coursepilot/Kontext uncreated.

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $this->assertSame([], $result['entries']);
    }

    /**
     * External content uses the same personal-data checks as Moodle
     * (Spec §6); marked entries appear locked.
     */
    public function test_personal_data_marked_file_appears_locked_in_external_listing(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'lerngruppe.md');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['locked']);
    }

    /**
     * Neither tool names nor responses reveal the server, account or
     * instance ID (Spec §6/§15).
     */
    public function test_external_listing_response_reveals_no_storage_location(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern');

        $result = list_context_files::execute();
        $result = external_api::clean_returnvalue(list_context_files::execute_returns(), $result);
        $encoded = json_encode($result);

        $this->assertStringNotContainsString($this->fixtureserver, $encoded);
        $this->assertStringNotContainsString($this->fixturekonto, $encoded);
    }

    /**
     * Mark cache (#493, Spec #486 §6): two unchanged listings fetch a
     * Markdown file only once.
     */
    public function test_second_listing_without_change_does_not_refetch_marked_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        list_context_files::execute();
        list_context_files::execute();

        $gets = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertCount(1, $gets);
    }

    /**
     * A changed file has a new size or ETag, invalidating its cached key
     * and causing another read on the next listing.
     */
    public function test_changed_file_is_refetched_on_next_listing(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $first = list_context_files::execute();
        $first = external_api::clean_returnvalue(list_context_files::execute_returns(), $first);
        $this->assertTrue($this->find_entry($first['entries'], 'lerngruppe.md')['locked']);

        // Manual edit removes the marking and changes size and ETag.
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', '# Unmarkiert, neu geschrieben');

        $second = list_context_files::execute();
        $second = external_api::clean_returnvalue(list_context_files::execute_returns(), $second);
        $this->assertFalse($this->find_entry($second['entries'], 'lerngruppe.md')['locked']);

        $gets = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertCount(2, $gets);
    }

    /**
     * When #344 is enabled, skip checks and Markdown GETs because locked
     * is always false.
     */
    public function test_switch_on_never_fetches_marked_file_content(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        list_context_files::execute();

        $gets = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'GET'));
        $this->assertCount(0, $gets);
    }

    /**
     * Issue #645: the same request returns the same field set at both
     * locations; folders carry no checksum at either location.
     */
    public function test_moodle_and_external_listings_share_the_field_set(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', '# Plan');
        $this->create_context_file($user, '/coursepilot/faecher/', 'mathe.md', '# Mathe');
        $moodle = external_api::clean_returnvalue(list_context_files::execute_returns(), list_context_files::execute());

        [, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', '# Plan');
        $fake->seed_folder('/Coursepilot/Kontext/faecher');
        $external = external_api::clean_returnvalue(list_context_files::execute_returns(), list_context_files::execute());

        foreach (['plan.md', 'faecher'] as $name) {
            $moodleentry = $this->find_entry($moodle['entries'], $name);
            $externalentry = $this->find_entry($external['entries'], $name);
            $this->assertSame(array_keys($moodleentry), array_keys($externalentry), $name);
        }
        $this->assertSame('', $this->find_entry($moodle['entries'], 'faecher')['contenthash']);
        $this->assertSame('', $this->find_entry($external['entries'], 'faecher')['contenthash']);
    }

    /**
     * Issue #645: listing the previous location evaluates the lock against
     * the file at the previous location, not against the same path at the
     * current location.
     */
    public function test_previous_location_lock_is_read_from_the_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', '# harmlos');
        $this->create_context_file($user, '/previouslocation/', 'lerngruppe.md', $this->marked_content());
        $this->write_pointer_with_previous_location($user, 'previouslocation');

        $current = external_api::clean_returnvalue(list_context_files::execute_returns(), list_context_files::execute());
        $previous = external_api::clean_returnvalue(list_context_files::execute_returns(), list_context_files::execute('', true));

        $this->assertFalse($this->find_entry($current['entries'], 'lerngruppe.md')['locked']);
        $this->assertTrue($this->find_entry($previous['entries'], 'lerngruppe.md')['locked']);
    }

    /**
     * Issue #645: an external previous location is listed and read through
     * the WebDAV adapter, read-only and with the same field set.
     */
    public function test_external_previous_location_is_listed_and_read_via_the_adapter(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_pointer_with_external_previous_location($user, $instanceid);
        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        $fake->seed_folder('/Coursepilot/Alt');
        $fake->seed_file('/Coursepilot/Alt/alt.md', '# Alt');

        $listed = external_api::clean_returnvalue(list_context_files::execute_returns(), list_context_files::execute('', true));
        $entry = $this->find_entry($listed['entries'], 'alt.md');
        $this->assertNotNull($entry);
        $this->assertNotSame('', $entry['contenthash']);
        $this->assertFalse($entry['locked']);

        $read = external_api::clean_returnvalue(
            read_context_file::execute_returns(),
            read_context_file::execute('alt.md', true)
        );
        $this->assertSame('# Alt', $read['content']);
        $this->assertSame($entry['contenthash'], $read['contenthash']);
    }

    /**
     * Returns context-file content with the legacy frontmatter marking.
     *
     * @return string Context-file content with the legacy frontmatter marking
     *         "coursepilot.personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n  weitergabe: nicht_weitergeben\n---\n# S. M., 7a";
    }

    /**
     * Finds entry.
     *
     * @param array $entries
     * @param string $name
     * @return array|null
     */
    private function find_entry(array $entries, string $name): ?array {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }
        return null;
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
