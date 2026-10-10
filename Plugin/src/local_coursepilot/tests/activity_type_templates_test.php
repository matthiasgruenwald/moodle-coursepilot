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

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Bundled knowledge is delivered through public location selection (#603).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection::class)]
#[CoversClass(context_area::class)]
#[CoversClass(private_files_storage_port::class)]
final class activity_type_templates_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    public function test_location_selection_supplies_verified_templates_in_private_files(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $provided = [];

        location_selection::apply([
            'context_area' => ['type' => 'moodle'],
            'material_store' => ['type' => 'moodle'],
        ], $provided);

        $this->assertSame(['book', 'checklist', 'glossary'], $provided);
        foreach ($provided as $modname) {
            $file = context_area::read('activity-types/' . $modname . '.md');
            $this->assertNotNull($file);
            $this->assertSame(file_get_contents(__DIR__ . '/../activity-types/' . $modname . '.md'), $file['content']);
        }
        $this->assertSame(
            ['book.md', 'checklist.md', 'glossary.md'],
            array_column(context_area::list('activity-types')['entries'], 'name')
        );
        $this->assertNull(context_area::read('activity-types/lightboxgallery.md'));
    }
    public function test_repeated_location_selection_has_no_writes_or_template_notice(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $selection = ['context_area' => ['type' => 'moodle'], 'material_store' => ['type' => 'moodle']];
        location_selection::apply($selection);
        $writes = $DB->perf_get_writes();
        $provided = ['stale notice'];

        $this->assertSame([], location_selection::apply($selection, $provided));

        $this->assertSame([], $provided);
        $this->assertSame($writes, $DB->perf_get_writes());
    }

    public function test_location_selection_preserves_teacher_edits_and_locked_files(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $content = "---\ncoursepilot.personenbezug: true\n---\nTeacher's own notes\n";
        get_file_storage()->create_file_from_string(context_files::filerecord(
            context_files::own_context()->id,
            '/coursepilot/activity-types/',
            'book.md'
        ), $content);
        $before = context_area::read('activity-types/book.md');
        $this->assertTrue(personal_data::is_marked($content));
        $this->assertFalse(personal_data::allowed());
        $provided = [];

        location_selection::apply([
            'context_area' => ['type' => 'moodle'],
            'material_store' => ['type' => 'moodle'],
        ], $provided);

        $this->assertSame(['checklist', 'glossary'], $provided);
        $this->assertSame($before, context_area::read('activity-types/book.md'));
        $this->assertSame($content, context_area::read('activity-types/book.md')['content']);
    }

    public function test_missing_private_files_permission_does_not_interrupt_selection(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:manageownfiles', CAP_PROHIBIT, $roleid, \context_user::instance($user->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $provided = [];

        $this->assertSame([], location_selection::apply([
            'context_area' => ['type' => 'moodle'], 'material_store' => ['type' => 'moodle'],
        ], $provided));

        $this->assertSame([], $provided);
        $this->assertNull(context_area::read('activity-types/book.md'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('verified_examples')]
    public function test_delivered_example_creates_and_roundtrips(string $modname, string $name, string $childpath, int $children): void {
        global $DB;
        $this->resetAfterTest();
        // Core-only CI does not install optional modules. Dedicated verification
        // includes real mod_checklist; all delivery/ownership tests still run.
        if ($modname === 'checklist' && !$DB->record_exists('modules', ['name' => 'checklist'])) {
            $this->markTestSkipped('The checklist example requires installed mod_checklist (verified with 4.1.0.8).');
        }
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        location_selection::apply(['context_area' => ['type' => 'moodle'], 'material_store' => ['type' => 'moodle']]);
        $file = context_area::read('activity-types/' . $modname . '.md');
        $this->assertStringContainsString('5.1.7+ (Build: 20260928)', $file['content']);
        $this->assertSame(1, preg_match('/```xml\n(.*?)\n```/s', $file['content'], $matches));
        $created = \local_coursepilot\external\create_activity_from_xml::execute($course->id, $modname, 1, $matches[1]);
        $this->assertGreaterThan(0, $created['cmid']);
        $exported = \local_coursepilot\external\export_activity_backup::execute($created['cmid']);
        $xml = simplexml_load_string($exported['xml']);
        $this->assertSame($name, (string) $xml->{$modname}->name);
        $this->assertCount($children, $xml->xpath($childpath));
        $this->assertTrue((bool) get_fast_modinfo($course->id)->get_cm($created['cmid'])->visible);
        if ($modname === 'glossary') {
            $result = \local_coursepilot\external\add_glossary_entries::execute($created['cmid'], [
                ['concept' => 'Cell', 'definition' => '<p>The basic unit of life.</p>', 'aliases' => ['Biological cell']],
            ]);
            $this->assertTrue($result['entries'][0]['success']);
            $this->assertGreaterThan(0, $result['entries'][0]['entryid']);
        }
    }

    /**
     * Provides verified examples.
     *
     * @return array
     */
    public static function verified_examples(): array {
        return [
            'book' => ['book', 'Short book', '/activity/book/chapters/chapter', 1],
            'checklist' => ['checklist', 'My checklist', '/activity/checklist/items/item', 3],
            'glossary' => ['glossary', 'My glossary', '/activity/glossary/entries/entry', 0],
        ];
    }

    public function test_external_preread_failure_does_not_interrupt_selection_or_other_templates(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot');
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->fail_once('/Coursepilot/Kontext/activity-types/book.md', 401);
        $selection = $this->external_selection();
        get_file_storage()->get_file(
            context_files::own_context()->id,
            'user',
            'private',
            0,
            '/coursepilot/',
            storage_anchor::POINTER_FILENAME
        )->delete();
        try {
            $provided = [];
            $this->assertSame(['context_area'], location_selection::apply($selection, $provided));
            $this->assertSame(['checklist', 'glossary'], $provided);
            $this->assertSame('external', location_selection::current('context_area')['location']);
            $this->assertNull(context_area::read('activity-types/book.md'));
            $this->assertNotNull(context_area::read('activity-types/glossary.md'));
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_external_concurrent_creation_is_preserved_after_conditional_put_conflict(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot');
        $fake->seed_folder('/Coursepilot/Kontext');
        $transport = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the activity type templates test.
             *
             * @param \local_coursepilot\tests\webdav\fake_webdav_transport $fake The fake.
             */
            public function __construct(
                /** @var \local_coursepilot\tests\webdav\fake_webdav_transport The fake. */
                private readonly \local_coursepilot\tests\webdav\fake_webdav_transport $fake,
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
                if ($method === 'PUT' && str_ends_with($url, '/book.md')) {
                    $this->fake->seed_file('/Coursepilot/Kontext/activity-types/book.md', "Teacher's concurrent file");
                }
                return $this->fake->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $transport);
        try {
            $provided = [];
            location_selection::apply($this->external_selection(), $provided);
            $this->assertSame(['checklist', 'glossary'], $provided);
            $this->assertSame("Teacher's concurrent file", context_area::read('activity-types/book.md')['content']);
            $puts = array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'PUT'));
            $this->assertCount(3, $puts);
            foreach ($puts as $put) {
                $this->assertSame('*', $put['headers']['If-None-Match'] ?? null);
            }
            $writes = count(array_filter($fake->requests(), static fn (array $r): bool => in_array($r['method'], ['PUT', 'MKCOL', 'DELETE', 'MOVE'])));
            location_selection::apply($this->external_selection(), $provided);
            $this->assertSame([], $provided);
            $this->assertSame($writes, count(array_filter($fake->requests(), static fn (array $r): bool => in_array($r['method'], ['PUT', 'MKCOL', 'DELETE', 'MOVE']))));
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_private_files_concurrent_creation_is_never_replaced(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // Moodle tracks table changes in the parent process for teardown.
        // Mark files here so child-created rows cannot escape the test reset.
        get_file_storage()->create_directory(context_files::own_context()->id, 'user', 'private', 0, '/coursepilot/');
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/activity_template_create_race.php', (string) $user->id],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors . $output);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['injected']);
        $this->assertSame("Teacher's concurrent file", $result['content']);
        $this->assertSame(['checklist', 'glossary'], $result['provided']);
        $this->assertSame("Teacher's concurrent file", context_area::read('activity-types/book.md')['content']);
    }

    public function test_external_write_outage_retains_new_selection_and_pending_notes(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot');
        $fake->seed_folder('/Coursepilot/Kontext');
        $selection = $this->external_selection();
        get_file_storage()->get_file(
            context_files::own_context()->id,
            'user',
            'private',
            0,
            '/coursepilot/',
            storage_anchor::POINTER_FILENAME
        )->delete();
        $transport = new class ($fake) implements \local_coursepilot\webdav\webdav_transport {
            /**
             * Creates the activity type templates test.
             *
             * @param \local_coursepilot\tests\webdav\fake_webdav_transport $fake The fake.
             */
            public function __construct(
                /** @var \local_coursepilot\tests\webdav\fake_webdav_transport The fake. */
                private readonly \local_coursepilot\tests\webdav\fake_webdav_transport $fake,
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
                    $this->fake->fill_storage();
                }
                return $this->fake->request($method, $url, $headers, $body);
            }
        };
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $transport);
        try {
            $provided = [];
            $this->assertSame(['context_area'], location_selection::apply($selection, $provided));
            $this->assertSame([], $provided);
            $this->assertSame('external', location_selection::current('context_area')['location']);
            $this->assertNull(context_area::read('activity-types/book.md'));
            $this->assertNotEmpty(pending_write_notice::list_grouped());
            $moodle = storage_anchor::port_at(pointer_location::moodle('/coursepilot/'));
            $this->assertNull($moodle->read(context_files::area(), 'activity-types/book.md'));
        } finally {
            \core\di::reset_container();
        }
    }

    /**
     * Provides external selection.
     *
     * @return array
     */
    private function external_selection(): array {
        $current = location_selection::current('context_area');
        return [
            'context_area' => ['type' => 'external', 'instanceid' => $current['instanceid'], 'path' => 'Kontext'],
            'material_store' => ['type' => 'moodle'],
        ];
    }
}
