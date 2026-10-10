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
 * Context-area reads (#343): successful reads, traversal attacks, foreign
 * areas and user isolation, plus proof that no write endpoint exists.
 * Since #490, covers external storage through a second-generation context
 * pointer and the fake WebDAV transport.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(read_context_file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\context_files::class)]
final class read_context_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /** Canonical names resolve existing German files without migrating storage. */
    public function test_canonical_names_read_legacy_files_and_return_actual_paths(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        foreach (['templates.md' => 'vorlagen.md', 'notepad.md' => 'merkzettel.md', 'CONTEXT-people.md' => 'CONTEXT.personen.md'] as $canonical => $legacy) {
            $this->create_context_file($user, '/coursepilot/', $legacy, '# Legacy ' . $legacy);
            $result = read_context_file::execute($canonical);
            $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);
            $this->assertSame($legacy, $result['path']);
            $this->assertSame($legacy, $result['filename']);
            $this->assertSame('# Legacy ' . $legacy, $result['content']);
            $this->assertSame(sha1($result['content']), $result['contenthash']);
            $this->assertSame($result, read_context_file::execute($legacy));
        }
    }

    public function test_canonical_file_takes_precedence_over_legacy_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'templates.md', '# Canonical');
        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Legacy');
        $result = read_context_file::execute('templates.md');
        $this->assertSame('templates.md', $result['path']);
        $this->assertSame('# Canonical', $result['content']);
    }

    public function test_legacy_fallback_preserves_personal_data_guard(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'CONTEXT.personen.md', $this->marked_content());
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('contextfilelocked', 'local_coursepilot', 'CONTEXT-people.md'));
        read_context_file::execute('CONTEXT-people.md');
    }

    public function test_legacy_fallback_stays_in_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'templates.md', '# Current');
        $this->create_context_file($user, '/previouslocation/', 'vorlagen.md', '# Previous legacy');
        $this->write_pointer_with_previous_location($user, 'previouslocation');
        $result = read_context_file::execute('templates.md', true);
        $this->assertSame('vorlagen.md', $result['path']);
        $this->assertSame('# Previous legacy', $result['content']);
    }

    public function test_external_legacy_fallback_and_canonical_precedence(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Legacy');
        $legacy = read_context_file::execute('templates.md');
        $this->assertSame('vorlagen.md', $legacy['path']);
        $this->assertSame('# Legacy', $legacy['content']);
        $this->assertNotSame('', $legacy['contenthash']);
        $fake->seed_file('/Coursepilot/Kontext/templates.md', '# Canonical');
        $canonical = read_context_file::execute('templates.md');
        $this->assertSame('templates.md', $canonical['path']);
        $this->assertSame('# Canonical', $canonical['content']);
    }

    public function test_external_legacy_fallback_locks_marked_content(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/CONTEXT.personen.md', $this->marked_content());
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('contextfilelocked', 'local_coursepilot', 'CONTEXT-people.md'));
        read_context_file::execute('CONTEXT-people.md');
    }

    public function test_canonical_external_read_failure_is_not_a_missing_file(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Legacy');
        $fake->deny_auth();
        try {
            read_context_file::execute('templates.md');
            $this->fail('Authentication failure must remain visible.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavexternalerror', $e->errorcode);
        }
    }

    /**
     * The root template file uses the same read contract as every context file.
     */
    public function test_reads_root_template_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Gemerkte Vorlagen');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Gemerkte Vorlagen', $result['content']);
        $this->assertSame('vorlagen.md', $result['filename']);
        // Return the relative requested path without the root prefix; otherwise
        // clients build coursepilot/... and reach /coursepilot/coursepilot/... (#425 F1).
        $this->assertSame('vorlagen.md', $result['path']);
    }

    /**
     * contenthash and timemodified (Spec 0016 §2) support concurrency protection
     * and manual-change detection in the write path.
     */
    public function test_returns_contenthash_and_timemodified(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'vorlagen.md', '# Gemerkte Vorlagen');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame(sha1('# Gemerkte Vorlagen'), $result['contenthash']);
        $this->assertGreaterThan(0, $result['timemodified']);
    }

    /**
     * Files in subfolders are readable without a special case.
     */
    public function test_reads_subfolder_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/faecher/mathe/', 'profil.md', '# Mathe-Fachprofil');

        $result = read_context_file::execute('faecher/mathe/profil.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Mathe-Fachprofil', $result['content']);
    }

    /**
     * CRITICAL: Moodle parameter validation rejects traversal (../) at the API
     * boundary, keeping files outside the root unreachable.
     */
    public function test_traversal_attempt_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/', 'secret.txt', 'geheim');

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('../secret.txt');
    }

    /**
     * CRITICAL: person A cannot read person B's file even with its exact name.
     */
    public function test_person_a_cannot_read_person_bs_file(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $this->create_context_file($teachera, '/coursepilot/', 'lerngruppe-a.md', 'Vertraulich A');

        $this->setUser($teacherb);
        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe-a.md');
    }

    /**
     * No parameter allows callers to change contextid, itemid or component.
     */
    public function test_execute_parameters_expose_no_area_selector(): void {
        $definition = read_context_file::execute_parameters()->keys;
        $this->assertSame(['path', 'previous_location'], array_keys($definition));
    }

    /**
     * previous_location without open legacy storage is a named error (#498, Spec #486 §6).
     */
    public function test_previous_location_switch_without_open_previouslocation_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            read_context_file::execute('plan.md', true);
            $this->fail('The switch must reject reads without open legacy storage.');
        } catch (\moodle_exception $e) {
            $this->assertSame('previouslocationclosed', $e->errorcode);
        }
    }

    /**
     * With legacy storage open, the switch reads the previous location. The
     * identical filenames have different content to distinguish both locations.
     */
    public function test_previous_location_switch_reads_from_the_previous_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/coursepilot/', 'plan.md', '# Aktuell');
        $this->create_context_file($user, '/previouslocation/', 'plan.md', '# Alt');
        $this->write_pointer_with_previous_location($user, 'previouslocation');

        $current = read_context_file::execute('plan.md');
        $current = external_api::clean_returnvalue(read_context_file::execute_returns(), $current);
        $this->assertSame('# Aktuell', $current['content']);

        $previous = read_context_file::execute('plan.md', true);
        $previous = external_api::clean_returnvalue(read_context_file::execute_returns(), $previous);
        $this->assertSame('# Alt', $previous['content']);
    }

    /**
     * The personal-data guard and all resolution checks apply to the previous
     * location too (#498 acceptance criterion).
     */
    public function test_previous_location_switch_still_locks_marked_content(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_context_file($user, '/previouslocation/', 'lerngruppe.md', $this->marked_content());
        $this->write_pointer_with_previous_location($user, 'previouslocation');

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md', true);
    }


    /**
     * The context area has exactly two write endpoints (#408/#409, Spec 0016
     * §4): write_context_file and append_context_file. Uploading or saving
     * material in the context area remains excluded. The material store
     * (Spec 0018 §2/§4.2, #428) has its own upload_material_file tool, so the
     * save/upload exclusion applies only to context tools.
     */
    public function test_context_write_surface_is_exactly_two_tools(): void {
        $writetools = [];
        foreach (\local_coursepilot\privacy_surface::allowed_tools() as $toolname => $functionname) {
            if (!str_contains($toolname, 'context')) {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase('save', $toolname);
            $this->assertStringNotContainsStringIgnoringCase('upload', $toolname);
            if (\local_coursepilot\tool_registry::is_write($toolname)) {
                $writetools[] = $toolname;
            }
        }
        sort($writetools);
        $this->assertSame(
            ['coursepilot_append_context_file', 'coursepilot_write_context_file'],
            $writetools
        );
    }

    /**
     * The personal-data switch (#344, ADR 0011) defaults to off: without an
     * explicit set_config(), personal_data::allowed() returns false.
     */
    public function test_personal_data_switch_defaults_off(): void {
        $this->resetAfterTest();
        $this->assertFalse(\local_coursepilot\personal_data::allowed());
    }

    /**
     * With the switch off, marked personal content is unreadable and returns a clear error.
     */
    public function test_personal_data_marked_file_unreadable_when_switch_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $this->marked_content());

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md');
    }

    /**
     * With the switch on, the same file is readable byte-for-byte, without
     * automatic redaction or rewriting.
     */
    public function test_personal_data_marked_file_readable_when_switch_on(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $content = $this->marked_content();
        $this->create_context_file($user, '/coursepilot/', 'lerngruppe.md', $content);

        $result = read_context_file::execute('lerngruppe.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame($content, $result['content']);
    }

    /**
     * Unmarked content remains readable unchanged in either switch position.
     */
    public function test_unmarked_file_readable_regardless_of_switch(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $content = "---\ntype: vorhaben\ncoursepilot:\n  personenbezug: false\n---\n# Sachtext";
        $this->create_context_file($user, '/coursepilot/', 'sachtext.md', $content);

        $resultoff = read_context_file::execute('sachtext.md');
        $resultoff = external_api::clean_returnvalue(read_context_file::execute_returns(), $resultoff);
        $this->assertSame($content, $resultoff['content']);

        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $resulton = read_context_file::execute('sachtext.md');
        $resulton = external_api::clean_returnvalue(read_context_file::execute_returns(), $resulton);
        $this->assertSame($content, $resulton['content']);
    }

    /**
     * External context storage (#490, Spec #486 §2/§6) uses the WebDAV client
     * and returns the same tool response as Moodle storage.
     */
    public function test_reads_external_context_file_via_webdav(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame('# Extern gemerkt', $result['content']);
        $this->assertSame('vorlagen.md', $result['path']);
        $this->assertSame('vorlagen.md', $result['filename']);
    }

    /**
     * External reads return a nonempty checksum (#513, Spec #486 §4/§6),
     * as with list_context_files, here for the single-file read path.
     */
    public function test_external_read_returns_a_nonempty_checkvalue(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertNotSame('', $result['contenthash']);
    }

    /**
     * Without ETags (IServ), the checksum uses modification time: weaker but
     * still nonempty (#513, Spec §4).
     */
    public function test_external_read_returns_a_nonempty_checkvalue_without_etag(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern gemerkt');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertNotSame('', $result['contenthash']);
    }

    /**
     * A missing external file returns the same not-found error as Moodle storage.
     */
    public function test_missing_external_file_throws_contextfilenotfound(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');

        try {
            read_context_file::execute('vorlagen.md');
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilenotfound', $e->errorcode);
        }
    }

    /**
     * The personal-data check applies to external content exactly as in Moodle (Spec §6).
     */
    public function test_personal_data_marked_file_unreadable_when_external_and_switch_off(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $this->marked_content());

        $this->expectException(\moodle_exception::class);
        read_context_file::execute('lerngruppe.md');
    }

    public function test_personal_data_marked_file_readable_when_external_and_switch_on(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $content = $this->marked_content();
        $fake->seed_file('/Coursepilot/Kontext/lerngruppe.md', $content);
        set_config('allowpersonaldata', 1, 'local_coursepilot');

        $result = read_context_file::execute('lerngruppe.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);

        $this->assertSame($content, $result['content']);
    }

    /**
     * Read failures return an error without a pending write (#492, CONTEXT.md):
     * nothing has been lost because no write was attempted.
     */
    public function test_read_failure_creates_no_pending_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->deny_auth();

        try {
            read_context_file::execute('vorlagen.md');
            $this->fail('Authentication denial must reject the read.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavexternalerror', $e->errorcode);
            // Issue #516: read failures explicitly name the context gap. PHPUnit
            // resolves English only; write_context_file_test::
            // test_german_messages_carry_the_required_wording() checks German wording.
            $this->assertStringContainsString('context gap', $e->getMessage());
        }

        $this->assertSame([], \local_coursepilot\pending_write_notice::list_grouped());
    }

    /**
     * The German wording explicitly names "Kontext-Lücke" and never "Ausstand"
     * (#516 acceptance criterion, CONTEXT.md).
     */
    public function test_german_read_failure_message_names_the_context_gap(): void {
        $string = [];
        require(__DIR__ . '/../../lang/de/local_coursepilot.php');

        $this->assertStringContainsString('Kontext-Lücke', $string['webdavexternalerror']);
        $this->assertStringNotContainsString('Ausstand', $string['webdavexternalerror']);
    }

    /**
     * Neither the tool name nor its response reveals the storage location (Spec §6/§15).
     */
    public function test_external_read_response_reveals_no_storage_location(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/vorlagen.md', '# Extern');

        $result = read_context_file::execute('vorlagen.md');
        $result = external_api::clean_returnvalue(read_context_file::execute_returns(), $result);
        $encoded = json_encode($result);

        $this->assertStringNotContainsString($this->fixtureserver, $encoded);
        $this->assertStringNotContainsString($this->fixturekonto, $encoded);
    }

    /**
     * Returns context content with the coursepilot.personenbezug: true marker.
     *
     * @return string Context-file content with the legacy frontmatter marking
     *         "coursepilot.personenbezug: true".
     */
    private function marked_content(): string {
        return "---\ntype: lerngruppe\ncoursepilot:\n  personenbezug: true\n  weitergabe: nicht_weitergeben\n---\n# S. M., 7a";
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
