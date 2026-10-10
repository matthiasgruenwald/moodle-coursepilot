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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Dispatcher seam (#334): the same decision logic as before in mcp.php,
 * now callable via PHPUnit without exit/superglobals and without a running
 * web server.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(dispatcher::class)]
#[CoversClass(\local_coursepilot\history\file_policy::class)]
final class dispatcher_test extends \advanced_testcase {
    /**
     * Creates a user with a valid OAuth access token (#337) - replaces the
     * former web service token workaround. By default the system-wide
     * editingteacher role is explicitly granted local/coursepilot:useremote
     * (since #579 without an archetype default, the role route of the remote
     * access approval); $withremote = false simulates revoked remote access.
     *
     * @param bool $withremote
     * @return array{0: \stdClass, 1: string} User and access token.
     */
    private function create_authenticated_user(bool $withremote = true): array {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        assign_capability(
            'local/coursepilot:useremote',
            $withremote ? CAP_ALLOW : CAP_PROHIBIT,
            $this->get_role_id('editingteacher'),
            \context_system::instance()->id,
            true
        );
        $token = $this->issue_access_token($user->id);
        return [$user, $token];
    }

    /**
     * Creates an OAuth access token record directly - the simplest way to the
     * real authentication path without replaying the full DCR/PKCE round trip
     * (#336) for every test.
     *
     * @param int $userid
     * @param int $expiresoffset Seconds relative to now (negative = expired).
     * @param bool $revoked
     * @return string The access token.
     */
    private function issue_access_token(int $userid, int $expiresoffset = 3600, bool $revoked = false): string {
        global $DB;

        $accesstoken = oauth_lib::random_token(32);
        $record = new \stdClass();
        $record->accesstokenhash = hash('sha256', $accesstoken);
        $record->refreshtokenhash = hash('sha256', oauth_lib::random_token(32));
        $record->clientid = 'test-client';
        $record->userid = $userid;
        $record->expires = time() + $expiresoffset;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = $revoked ? 1 : 0;
        $record->timecreated = time();
        $record->connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $record->userid, 'clientid' => $record->clientid, 'revoked' => $record->revoked,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $record->timecreated,
        ]);
        $DB->insert_record('local_coursepilot_oauth_token', $record);

        return $accesstoken;
    }

    /**
     * Returns role id.
     *
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Provides headers.
     *
     * @param array $overrides The overrides.
     * @return array{origin: null, pathinfo: string, method: string}
     */
    private function headers(array $overrides = []): array {
        return array_merge(['origin' => null, 'pathinfo' => '', 'method' => 'POST'], $overrides);
    }

    /**
     * Named MCP inputs must survive Moodle's positional External invocation (#633).
     */
    public function test_xml_preview_requires_predecessor_without_mutation(): void {
        $this->resetAfterTest();
        [$token, $arguments] = $this->xml_supersede_fixture();
        unset($arguments['replaces_cmid']);
        $before = $this->xml_mutation_state();
        $response = $this->xml_call($token, $arguments + ['dry_run' => true]);
        $this->assertTrue($response['isError'] ?? false, json_encode($response));
        $this->assertStringContainsString('dry_run needs replaces_cmid', json_encode($response));
        $this->assertEquals($before, $this->xml_mutation_state());
    }

    public function test_xml_preview_with_predecessor_one_changes_no_modules_files_visibility_or_history(): void {
        $this->resetAfterTest();
        [$token, $arguments, $other] = $this->xml_supersede_fixture();
        $this->assertSame(1, $arguments['replaces_cmid']);
        $before = $this->xml_mutation_state();
        // Deliberately reverse input order: only declaration order may govern positional invocation.
        $response = $this->xml_call($token, array_reverse($arguments + ['dry_run' => true, 'hidden' => true], true));
        $this->assertFalse($response['isError'] ?? false, json_encode($response));
        $result = $response['structuredContent'];
        $this->assertSame(0, $result['cmid']);
        $this->assertSame($other->cmid, $result['references'][0]['location_id']);
        $this->assertEquals($before, $this->xml_mutation_state());
    }

    public function test_xml_regular_supersede_keeps_predecessor_and_only_changes_intended_objects(): void {
        global $DB;
        $this->resetAfterTest();
        [$token, $arguments, $other] = $this->xml_supersede_fixture();
        $before = $this->xml_mutation_state();
        $oldbook = $DB->get_record('book', ['id' => $before['course_modules'][1]->instance], '*', MUST_EXIST);
        $response = $this->xml_call($token, $arguments + ['hidden' => true, 'dry_run' => false]);
        $this->assertFalse($response['isError'] ?? false, json_encode($response));
        $new = $response['structuredContent']['cmid'];
        $this->assertGreaterThan(1, $new);
        $this->assertSame(count($before['course_modules']) + 1, $DB->count_records('course_modules'));
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => 1], MUST_EXIST));
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $new], MUST_EXIST));
        $afterbook = $DB->get_record('book', ['id' => $oldbook->id], '*', MUST_EXIST);
        // Moodle updates the modification timestamp when visibility changes.
        unset($oldbook->timemodified, $afterbook->timemodified);
        $this->assertEquals($oldbook, $afterbook);
        $this->assertEquals(
            $before['course_modules'][$other->cmid],
            $DB->get_record('course_modules', ['id' => $other->cmid], '*', MUST_EXIST)
        );
        $this->assertEquals($before['files'], $DB->get_records('files', null, 'id'));
        $oldsection = $before['course_modules'][1]->section;
        $sequence = explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $oldsection], MUST_EXIST));
        $this->assertSame((string) $new, $sequence[array_search('1', $sequence, true) + 1]);
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_version', ['cmid' => $new]));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_version', ['cmid' => 1]));
    }

    /**
     * Synthetic first module has the historically dangerous truthy cmid 1.
     */
    private function xml_supersede_fixture(): array {
        global $DB;
        [$teacher, $token] = $this->create_authenticated_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        // PHPUnit deliberately offsets sequences; request a genuine first cmid in this isolated fixture.
        $DB->get_manager()->reset_sequence('course_modules');
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $this->assertSame(1, (int) $book->cmid);
        $xml = \local_coursepilot\external\export_default_activity::execute($course->id, 'book')['xml'];
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field(
            'course_modules',
            'availability',
            json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => 1, 'e' => 1]], 'showc' => [true]]),
            ['id' => $other->cmid]
        );
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance(1)->id, 'component' => 'mod_book', 'filearea' => 'intro',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'synthetic.txt',
        ], 'synthetic content');
        return [$token, ['courseid' => (int) $course->id, 'modname' => 'book', 'section' => 1,
            'activity_xml' => $xml, 'replaces_cmid' => 1], $other];
    }

    /**
     * Provides xml call.
     *
     * @param string $token The token.
     * @param array $arguments The arguments.
     * @return array
     */
    private function xml_call(string $token, array $arguments): array {
        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'coursepilot_create_activity_from_xml', 'arguments' => $arguments]],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $response['status']);
        return $response['body']['result'];
    }

    /**
     * Snapshot durable activity state; ordinary access auditing is allowed.
     */
    private function xml_mutation_state(): array {
        global $DB;
        $result = [];
        foreach (
            ['course_modules', 'course_sections', 'book', 'book_chapters', 'files',
                'local_coursepilot_cm_version', 'local_coursepilot_cm_file', 'local_coursepilot_cm_version_file'] as $table
        ) {
            $result[$table] = $DB->get_records($table, null, 'id');
        }
        return $result;
    }

    /**
     * History never exposes submission metadata or profile values, including legacy rows.
     */
    public function test_history_comparison_protects_current_and_legacy_data(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $CFG->enableavailability = true;
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        [$teacher, $token] = $this->create_authenticated_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cmid = (int) $assign->cmid;
        $context = \context_module::instance($cmid);
        foreach (
            [['assignsubmission_file', 'submission_files', 'student-secret.pdf'],
                ['mod_assign', 'unknown', 'unknown-secret.pdf'],
                ['mod_assign', 'intro', 'design.png']] as [$component, $area, $name]
        ) {
            get_file_storage()->create_file_from_string([
                'contextid' => $context->id, 'component' => $component, 'filearea' => $area,
                'itemid' => 0, 'filepath' => '/', 'filename' => $name,
            ], 'synthetic bytes');
        }
        $availability = json_encode(['op' => '&', 'c' => [
            ['op' => '|', 'c' => [['type' => 'profile', 'sf' => 'email', 'op' => 'isequalto',
                'v' => 'private-profile@example.invalid']]],
        ]]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
        \local_coursepilot\history\version_writer::capture($cmid, (int) $teacher->id);
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['filename' => 'student-secret.pdf']));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['filename' => 'unknown-secret.pdf']));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_file', ['filename' => 'design.png']));

        // Simulate historical metadata from before the positive allowlist, even with gap=0.
        $versionid = $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cmid, 'version' => 2]);
        foreach (
            [['assignsubmission_file', 'submission_files', 'legacy-student.pdf'],
                ['mod_assign', 'unknown', 'legacy-unknown.pdf']] as [$component, $area, $name]
        ) {
            $fileid = $DB->insert_record('local_coursepilot_cm_file', (object) [
                'component' => $component, 'filearea' => $area, 'filename' => $name,
                'pathnamehash' => sha1($name), 'contenthash' => sha1('synthetic'),
                'itemid' => 0, 'filepath' => '/', 'filesize' => 9, 'mimetype' => 'application/pdf',
                'timemodified' => time(),
            ]);
            $DB->insert_record('local_coursepilot_cm_version_file', (object) [
                'versionid' => $versionid, 'fileid' => $fileid, 'gap' => 0,
            ]);
        }
        foreach (
            ['coursepilot_compare_activity_versions' => ['cmid' => $cmid, 'from_version' => 1, 'to_version' => 2],
                'coursepilot_list_activity_versions' => ['cmid' => $cmid]] as $name => $arguments
        ) {
            $response = dispatcher::handle(['id' => 1, 'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments]], $token, $this->headers());
            $this->assertSame(200, $response['status']);
            $this->assertFalse($response['body']['result']['isError'] ?? false, json_encode($response));
            $encoded = json_encode($response);
            foreach (
                ['student-secret.pdf', 'unknown-secret.pdf', 'legacy-student.pdf', 'legacy-unknown.pdf',
                    'private-profile@example.invalid'] as $secret
            ) {
                $this->assertStringNotContainsString($secret, $encoded);
            }
            if ($name === 'coursepilot_compare_activity_versions') {
                $this->assertStringContainsString('design.png', $encoded);
                $this->assertStringContainsString('***', $encoded);
            }
        }
        $files = \local_coursepilot\history\version_history::files_at($cmid, 2);
        $this->assertSame(['design.png'], array_column($files, 'filename'));
        // Native restore retains the raw conditions; the AI projection must not mutate them.
        $state = \local_coursepilot\history\version_history::state_at($cmid, 2);
        $this->assertSame($availability, $state['availabilityconditionsjson']);
    }

    /**
     * The auth gate applies before the handshake: an unauthenticated initialize
     * returns 401, not the server info.
     */
    public function test_initialize_without_token_returns_401(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], null, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertSame(-32001, $response['body']['error']['code']);
        $this->assertArrayNotHasKey('result', $response['body']);
    }

    /**
     * Legacy era: initialize returns the server info after valid auth.
     */
    public function test_initialize_returns_serverinfo_when_authenticated(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame('local_coursepilot', $response['body']['result']['serverInfo']['name']);

        // Issue #577: the handshake version comes from the same canonical source
        // as version.php - no hard-coded prototype value any more.
        global $CFG;
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');
        $this->assertSame($plugin->release, $response['body']['result']['serverInfo']['version']);
        $this->assertNotSame('0.1.0', $response['body']['result']['serverInfo']['version']);
    }

    /**
     * Modern era: server/discover is served as well.
     */
    public function test_server_discover_returns_supported_versions(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'server/discover'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertContains(
            dispatcher::MODERN_VERSION,
            $response['body']['result']['supportedVersions']
        );
        $this->assertContains(
            dispatcher::LEGACY_VERSION,
            $response['body']['result']['supportedVersions']
        );
    }

    /**
     * #451, acceptance criterion: both handshake paths return the same
     * signpost as 'instructions' - without a local skill file there is no
     * description that a freshly connected client would pick up on.
     */
    public function test_initialize_and_server_discover_return_identical_instructions(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $initialize = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());
        $discover = dispatcher::handle(['id' => 2, 'method' => 'server/discover'], $token, $this->headers());

        $this->assertStringContainsString(
            'coursepilot_list_skills',
            $initialize['body']['result']['instructions'],
            'The signpost must name coursepilot_list_skills explicitly.'
        );
        $this->assertSame(
            $initialize['body']['result']['instructions'],
            $discover['body']['result']['instructions']
        );
    }

    /**
     * #451, acceptance criterion: the tool description of
     * coursepilot_list_skills carries the English hint like 'instructions' -
     * for clients that do not display instructions.
     */
    public function test_list_skills_tool_description_carries_the_same_hint(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        $tools = array_column($response['body']['result']['tools'], 'description', 'name');
        $this->assertStringContainsString(
            'Before planning or writing, call coursepilot_list_skills first.',
            $tools['coursepilot_list_skills']
        );
    }

    /**
     * tools/list is derived from the allowlist.
     */
    public function test_tools_list_is_derived_from_allowlist(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        $names = array_column($response['body']['result']['tools'], 'name');
        $this->assertSame(array_keys(privacy_surface::allowed_tools()), $names);
    }

    /**
     * Parameterless tools must return properties as a JSON object;
     * json_encode([]) would send clients an invalid JSON array (#566).
     */
    public function test_parameterless_tool_properties_are_a_json_object(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());
        $tools = array_column($response['body']['result']['tools'], 'inputSchema', 'name');

        $this->assertInstanceOf(\stdClass::class, $tools['coursepilot_list_skills']['properties']);
        $this->assertStringContainsString(
            '"properties":{}',
            json_encode($tools['coursepilot_list_skills']),
            'coursepilot_list_skills: properties must serialize as a JSON object.'
        );
    }

    /**
     * #342, acceptance criterion: every tool description stays under 2 KB
     * - generically across all listed tools, not just the five new ones.
     */
    public function test_tool_descriptions_stay_under_2kb(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        foreach ($response['body']['result']['tools'] as $tool) {
            $bytes = strlen($tool['description']);
            $this->assertLessThan(2048, $bytes, $tool['name'] . ': description is ' . $bytes . ' bytes long.');
        }
    }

    /**
     * Every MCP schema declaration is the execute_parameters() description
     * validated by Moodle, not a hand-maintained sample.
     */
    public function test_tools_list_schemas_match_external_parameters(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());
        $tools = [];
        foreach ($response['body']['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool['inputSchema'];
        }

        $functions = tool_registry::service_functions();
        foreach (tool_registry::allowed_tools() as $name => $function) {
            $classname = $functions[$function]['classname'];
            $expected = ['type' => 'object']
                + external_schema_converter::from_parameters($classname::execute_parameters())
                + ['additionalProperties' => false];
            if ($expected['properties'] === []) {
                $expected['properties'] = new \stdClass();
            }
            $this->assertEquals($expected, $tools[$name], "{$name}: tools/list schema differs.");
        }
    }

    /**
     * #568, independent contract test (Spec 0025 §Testing Decisions,
     * acceptance criterion 23): the expectation is NOT derived here via
     * external_schema_converter - a bug in the converter (like the eleven
     * contradictory required-field lists from the review of 2026-09-25)
     * must not co-produce the test's own expectation. What is checked is the
     * list actually published by the dispatcher, against purely structural
     * invariants of a closed JSON schema.
     */
    public function test_published_schemas_satisfy_independent_structural_invariants(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        $validtypes = ['string', 'integer', 'number', 'boolean', 'array', 'object'];
        foreach ($response['body']['result']['tools'] as $tool) {
            $schema = $tool['inputSchema'];
            $name = $tool['name'];

            $this->assertSame('object', $schema['type'], "{$name}: inputSchema.type must be 'object'.");
            $this->assertFalse($schema['additionalProperties'], "{$name}: additionalProperties must be false.");

            $properties = $schema['properties'] instanceof \stdClass ? [] : $schema['properties'];
            $required = $schema['required'] ?? [];

            foreach ($required as $requiredname) {
                // Invariant 1: every required field exists as a property -
                // otherwise the closed object (additionalProperties:
                // false) can no longer be satisfied by any call.
                $this->assertArrayHasKey(
                    $requiredname,
                    $properties,
                    "{$name}: required field '{$requiredname}' is not a declared property."
                );
                // Invariant 3: a required field carries no default - an
                // omitted but mandatory value would otherwise be described
                // contradictorily.
                $this->assertArrayNotHasKey(
                    'default',
                    $properties[$requiredname],
                    "{$name}: required field '{$requiredname}' contradictorily carries a default."
                );
            }

            foreach ($properties as $propname => $property) {
                // Invariant 2: the type is one that the JSON schema input
                // validation actually knows.
                $this->assertArrayHasKey('type', $property, "{$name}.{$propname}: no 'type' given.");
                $this->assertContains(
                    $property['type'],
                    $validtypes,
                    "{$name}.{$propname}: unknown type '{$property['type']}'."
                );
            }
        }
    }

    /**
     * resultType is mandatory for revision 2026-07-28 (#337 follow-up,
     * finding from the Claude Code live test: without this field a
     * 2026-07-28 client discards the tools/list response as invalid).
     */
    public function test_tools_list_includes_result_type(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));

        $this->assertSame('complete', $response['body']['result']['resultType']);
        $this->assertIsInt($response['body']['result']['ttlMs']);
        $this->assertSame('private', $response['body']['result']['cacheScope']);
    }

    /**
     * coursepilot_get_course_catalog is actually callable via tools/call,
     * not just listed (#341).
     */
    public function test_course_catalog_tool_is_callable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_get_course_catalog',
                    'arguments' => ['courseid' => $course->id],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame((int) $course->id, $response['body']['result']['structuredContent']['courseid']);
        $this->assertSame('aus Moodle gelesen', $response['body']['result']['structuredContent']['source']);
    }

    /**
     * #569, acceptance criterion: representative dispatcher round trip for the
     * course/activity tools - read, write, version AND restore tool - proving
     * that published field names (input AND return) arrive directly in
     * English (the contract is already declared in English, see
     * update_module_settings::execute_parameters()/execute_returns()) - since
     * #573 there is no translation at this boundary any more anyway. Moodle's
     * own validation (validate_parameters()) remains fully effective - the
     * second test below checks that via an unknown field. #573: the round trip
     * through coursepilot_restore_activity_version closes exactly the gap that
     * left the history.php finding (message/meldung) undiscovered.
     */
    public function test_course_and_activity_tools_are_callable_through_dispatcher_in_english(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Old title',
        ]);

        // Read tool: coursepilot_get_modules, return value exclusively
        // English keys (cmid/sectionnum/modname/name/visible/...).
        $listresponse = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_get_modules',
                    'arguments' => ['courseid' => $course->id],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $listresponse['status']);
        $listed = $listresponse['body']['result']['structuredContent'][0];
        $this->assertSame((int) $page->cmid, $listed['cmid']);
        $this->assertSame('page', $listed['modname']);

        // Write tool: coursepilot_update_module_settings - the published
        // parameter name is "fields_json", not the German "felder_json" from
        // the pre-#569 version.
        $writeresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_update_module_settings',
                    'arguments' => [
                        'cmid' => $page->cmid,
                        'fields_json' => json_encode(['name' => 'New title']),
                    ],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $writeresponse['status']);
        $this->assertArrayNotHasKey('isError', $writeresponse['body']['result']);
        $written = $writeresponse['body']['result']['structuredContent'];
        $this->assertSame((int) $page->cmid, $written['cmid']);
        $this->assertArrayHasKey('message', $written);
        $this->assertArrayHasKey('changes', $written);
        $this->assertSame('name', $written['changes'][0]['field']);
        $this->assertSame(json_encode('New title'), $written['changes'][0]['after_json']);

        // Version tool: coursepilot_list_activity_versions - the patch above
        // has already produced a second version.
        $versionsresponse = dispatcher::handle(
            [
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_list_activity_versions',
                    'arguments' => ['cmid' => $page->cmid],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $versionsresponse['status']);
        $versioned = $versionsresponse['body']['result']['structuredContent'];
        $this->assertArrayHasKey('versions', $versioned);
        $this->assertArrayHasKey('gap_notice', $versioned);
        $this->assertCount(2, $versioned['versions']);
        $this->assertArrayHasKey('summary_line', $versioned['versions'][1]);

        // History tool: coursepilot_restore_activity_version - the return key
        // is "message", not the German "meldung" that history.php still read
        // until this finding (#573: the page calls
        // restore_activity_version::execute() directly, bypassing the
        // dispatcher, and had been broken since #572). Restores version 1,
        // the first of the two versions created above.
        $restoreresponse = dispatcher::handle(
            [
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_restore_activity_version',
                    'arguments' => ['cmid' => $page->cmid, 'target_version' => 1],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $restoreresponse['status']);
        $this->assertArrayNotHasKey('isError', $restoreresponse['body']['result']);
        $restored = $restoreresponse['body']['result']['structuredContent'];
        $this->assertArrayHasKey('message', $restored);
        $this->assertArrayNotHasKey('meldung', $restored);

        // Moodle's own validation remains effective: an unknown field in the
        // patch still fails via validate_patch()/catalog_fields; the
        // translation layer does not get in the way.
        $invalidresponse = dispatcher::handle(
            [
                'id' => 5,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_update_module_settings',
                    'arguments' => [
                        'cmid' => $page->cmid,
                        'fields_json' => json_encode(['doesnotexist' => 'x']),
                    ],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $invalidresponse['status']);
        $this->assertTrue($invalidresponse['body']['result']['isError']);
    }

    /**
     * #570, acceptance criterion: the question bank / question / import / quiz
     * question tools are callable via the public dispatch path with fields
     * declared directly in English - read (coursepilot_get_
     * question_categories), change (coursepilot_ensure_question_category,
     * return keys "created"/"message" instead of "angelegt"/"meldung") and an
     * import/quiz assignment (coursepilot_import_questions_xml with
     * "confirmed" instead of "bestaetigt", followed by coursepilot_
     * add_questions_to_quiz). The confirmation gate of import_questions_xml
     * (suspect case for a supplied idnumber without a match) and the
     * permission gate of add_questions_to_quiz (mod/quiz:manage) remain
     * effective and unchanged.
     */
    public function test_question_bank_tools_are_callable_through_dispatcher_in_english(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        // Change (idempotent creation): coursepilot_ensure_question_bank -
        // return keys "created"/"message", not "angelegt"/"meldung".
        $bankresponse = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_ensure_question_bank',
                    'arguments' => ['courseid' => $course->id, 'name' => 'Question bank #570'],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $bankresponse['status']);
        $bank = $bankresponse['body']['result']['structuredContent'];
        $this->assertTrue($bank['created']);
        $this->assertArrayHasKey('message', $bank);
        $this->assertArrayNotHasKey('angelegt', $bank);
        $this->assertArrayNotHasKey('meldung', $bank);

        // Change: coursepilot_ensure_question_category, the same renaming as
        // above (created/message instead of angelegt/meldung).
        $categoryresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_ensure_question_category',
                    'arguments' => ['name' => 'Category #570', 'parent' => $bank['topcategoryid']],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $categoryresponse['status']);
        $category = $categoryresponse['body']['result']['structuredContent'];
        $this->assertTrue($category['created']);
        $categoryid = (int) $category['id'];

        // Read: coursepilot_get_question_categories - English return keys
        // id/name/parent, as already declared before #570.
        $listresponse = dispatcher::handle(
            [
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_get_question_categories',
                    'arguments' => ['courseid' => $course->id, 'questionbankid' => $bank['questionbankid']],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $listresponse['status']);
        $categories = $listresponse['body']['result']['structuredContent'];
        $names = array_column($categories, 'name');
        $this->assertContains('Category #570', $names);

        // Import assignment with confirmation gate: a supplied idnumber
        // without a match in the target category is a suspect case - nothing
        // is written as long as "confirmed" is not set.
        $xml = self::multichoice_xml_fixture('Dispatcher-Frage #570', 'Was ist 2+2?', 'kp-570-fremd');
        $unconfirmedresponse = dispatcher::handle(
            [
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_import_questions_xml',
                    'arguments' => ['categoryid' => $categoryid, 'xmlcontent' => $xml],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $unconfirmedresponse['status']);
        $unconfirmed = $unconfirmedresponse['body']['result']['structuredContent']['questions'][0];
        $this->assertSame('suspect', $unconfirmed['status']);

        // Note: "confirmed": true confirms explicitly - now it is written.
        $confirmedresponse = dispatcher::handle(
            [
                'id' => 5,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_import_questions_xml',
                    'arguments' => ['categoryid' => $categoryid, 'xmlcontent' => $xml, 'confirmed' => true],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $confirmedresponse['status']);
        $imported = $confirmedresponse['body']['result']['structuredContent']['questions'][0];
        $this->assertSame('first_import', $imported['status']);
        $this->assertArrayNotHasKey('bestaetigt', $imported);

        // Quiz assignment: coursepilot_add_questions_to_quiz appends the
        // imported question, return key "message" instead of "meldung".
        $questionid = $this->latest_version_questionid((int) $imported['questionbankentryid']);
        $quizresponse = dispatcher::handle(
            [
                'id' => 6,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_add_questions_to_quiz',
                    'arguments' => ['cmid' => $quiz->cmid, 'questionids' => [$questionid]],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $quizresponse['status']);
        $appended = $quizresponse['body']['result']['structuredContent'];
        $this->assertArrayHasKey('message', $appended);
        $this->assertArrayNotHasKey('meldung', $appended);
        $this->assertTrue($appended['appended'][0]['added']);

        // Permission gate remains effective: without mod/quiz:manage the same
        // call over the same dispatch path fails in a controlled way.
        $roleid = $this->get_role_id('editingteacher');
        assign_capability('mod/quiz:manage', CAP_PROHIBIT, $roleid, \context_module::instance($quiz->cmid)->id, true);
        $forbiddenresponse = dispatcher::handle(
            [
                'id' => 7,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_add_questions_to_quiz',
                    'arguments' => ['cmid' => $quiz->cmid, 'questionids' => [$questionid]],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $forbiddenresponse['status']);
        $this->assertTrue($forbiddenresponse['body']['result']['isError']);
    }

    /**
     * Builds a minimal Moodle XML with a single multichoice question
     * (#570) - slim copy of
     * {@see \local_coursepilot\external\import_questions_xml_test::multichoice_xml()}
     * for the dispatcher round-trip test, without coupling between test classes.
     *
     * @param string $name
     * @param string $questiontext
     * @param string $idnumber
     * @return string
     */
    private static function multichoice_xml_fixture(string $name, string $questiontext, string $idnumber): string {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name><text>{$name}</text></name>
    <questiontext format="html"><text><![CDATA[{$questiontext}]]></text></questiontext>
    <generalfeedback format="html"><text></text></generalfeedback>
    <defaultgrade>1.0000000</defaultgrade>
    <penalty>0.3333333</penalty>
    <hidden>0</hidden>
    <idnumber>{$idnumber}</idnumber>
    <single>true</single>
    <shuffleanswers>true</shuffleanswers>
    <answernumbering>abc</answernumbering>
    <correctfeedback format="html"><text></text></correctfeedback>
    <partiallycorrectfeedback format="html"><text></text></partiallycorrectfeedback>
    <incorrectfeedback format="html"><text></text></incorrectfeedback>
    <answer fraction="100" format="html">
      <text><![CDATA[4]]></text>
      <feedback format="html"><text></text></feedback>
    </answer>
    <answer fraction="0" format="html">
      <text><![CDATA[5]]></text>
      <feedback format="html"><text></text></feedback>
    </answer>
  </question>
</quiz>
XML;
    }

    /**
     * Returns questionid of the latest version.
     *
     * @param int $questionbankentryid
     * @return int questionid of the latest version
     */
    private function latest_version_questionid(int $questionbankentryid): int {
        global $DB;
        $latest = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$questionbankentryid],
            IGNORE_MULTIPLE
        );
        return (int) $latest->questionid;
    }

    /**
     * #571, acceptance criterion: skill list/retrieval, reading/writing a
     * context file as well as catching up on and dismissing a pending entry
     * with `identifier` are callable via the public dispatch path with fields
     * declared directly in English (Spec 0025 §A, third breakthrough of the
     * expand migration after #569/#570). Return keys
     * "trigger"/"kind"/"length" per skill, "pending_entries"/"notices" instead
     * of "ausstaende"/"hinweise", "referenced_parts"/"corpus_version" instead
     * of "referenzierte_teile"/"korpus_stand" and the input parameter
     * "pending_entry" instead of "ausstand" for the catch-up.
     */
    public function test_context_and_skill_tools_are_callable_through_dispatcher_in_english(): void {
        $this->resetAfterTest();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->setUser($teacher);

        // Skill list: catalog and pending-entry fields named in English.
        $listskillsresponse = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_list_skills', 'arguments' => []],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $listskillsresponse['status']);
        $skills = $listskillsresponse['body']['result']['structuredContent'];
        $this->assertArrayHasKey('pending_entries', $skills);
        $this->assertArrayHasKey('notices', $skills);
        $this->assertArrayNotHasKey('ausstaende', $skills);
        $this->assertArrayNotHasKey('hinweise', $skills);
        $firstskill = $skills['skills'][0];
        $this->assertArrayHasKey('trigger', $firstskill);
        $this->assertArrayHasKey('kind', $firstskill);
        $this->assertArrayHasKey('length', $firstskill);

        // Skill retrieval: content fields named in English.
        $getskillresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_get_skill', 'arguments' => ['name' => 'coursepilot']],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $getskillresponse['status']);
        $skill = $getskillresponse['body']['result']['structuredContent'];
        $this->assertArrayHasKey('referenced_parts', $skill);
        $this->assertArrayHasKey('corpus_version', $skill);

        // Write: coursepilot_write_context_file creates a context file.
        $writeresponse = dispatcher::handle(
            [
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_write_context_file',
                    'arguments' => ['path' => 'plan.md', 'content' => '# Plan #571'],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $writeresponse['status']);
        $this->assertArrayNotHasKey('isError', $writeresponse['body']['result']);
        $this->assertTrue($writeresponse['body']['result']['structuredContent']['created']);

        // Read: coursepilot_read_context_file reads the same content.
        $readresponse = dispatcher::handle(
            [
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_read_context_file',
                    'arguments' => ['path' => 'plan.md'],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $readresponse['status']);
        $this->assertSame('# Plan #571', $readresponse['body']['result']['structuredContent']['content']);

        // Catch-up: an open pending entry disappears as soon as the repeated
        // write with "pending_entry" succeeds.
        $pendingidentifier = pending_write_notice::record('journal.md', 'create', 'storage_full', 0);
        $catchupresponse = dispatcher::handle(
            [
                'id' => 5,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_write_context_file',
                    'arguments' => [
                        'path' => 'journal.md',
                        'content' => '# Journal #571',
                        'pending_entry' => $pendingidentifier,
                    ],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $catchupresponse['status']);
        $this->assertArrayNotHasKey('isError', $catchupresponse['body']['result']);
        $this->assertSame([], pending_write_notice::list_grouped());

        // Dismiss: coursepilot_dismiss_pending_entry with "identifier"
        // explicitly closes a second, independent pending entry.
        $dismissidentifier = pending_write_notice::record('journal.md', 'create', 'storage_full', 0);
        $dismissresponse = dispatcher::handle(
            [
                'id' => 6,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_dismiss_pending_entry',
                    'arguments' => ['identifier' => $dismissidentifier],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $dismissresponse['status']);
        $this->assertArrayNotHasKey('isError', $dismissresponse['body']['result']);
        $this->assertSame($dismissidentifier, $dismissresponse['body']['result']['structuredContent']['identifier']);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * #568, acceptance criterion: coursepilot_dismiss_pending_entry is callable
     * via the public dispatch path with the English field name `identifier` -
     * not just via a direct dismiss_pending_entry::execute() call (which
     * pending_write_notice_test.php/dismiss_pending_entry_test.php already
     * cover). This tool is already declared entirely in English, so it needs
     * no input translation by the dispatcher (Spec 0025 §A, first
     * breakthrough of the expand migration).
     */
    public function test_dismiss_pending_entry_is_callable_through_dispatcher_with_identifier(): void {
        $this->resetAfterTest();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->setUser($teacher);
        $identifier = pending_write_notice::record('plan.md', 'create', 'storage_full', 0);

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_dismiss_pending_entry',
                    'arguments' => ['identifier' => $identifier],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('isError', $response['body']['result']);
        $this->assertSame($identifier, $response['body']['result']['structuredContent']['identifier']);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * #568: an unknown identifier is rejected in a controlled way over the
     * same dispatch path (error result, no silent success and no HTTP error
     * status - the same contract shape as any other failed tool call).
     */
    public function test_dismiss_pending_entry_rejects_unknown_identifier_through_dispatcher(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_dismiss_pending_entry',
                    'arguments' => ['identifier' => 'UNKNOWN1'],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
    }

    /**
     * #568: a missing required field is rejected in a controlled way just the
     * same - the closed object schema (additionalProperties: false, required:
     * ["identifier"]) is the first line of defense at the client for this,
     * Moodle's own parameter validation the second on the server.
     */
    public function test_dismiss_pending_entry_rejects_missing_identifier_through_dispatcher(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_dismiss_pending_entry',
                    'arguments' => [],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
    }

    /**
     * #568: the permission check remains effective on the English breakthrough
     * as well - without moodle/user:manageownfiles the dispatcher rejects the
     * call, just like with the direct execute() call
     * (dismiss_pending_entry_test.php::test_rejects_missing_manageownfiles_capability).
     */
    public function test_dismiss_pending_entry_enforces_manageownfiles_through_dispatcher(): void {
        global $DB;
        $this->resetAfterTest();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->setUser($teacher);
        $identifier = pending_write_notice::record('plan.md', 'create', 'storage_full', 0);

        // CAP_PROHIBIT on the base role "user" overrides every additional
        // role (here editingteacher) - the same proven approach as
        // dismiss_pending_entry_test.php::test_rejects_missing_manageownfiles_capability.
        $roleid = $this->get_role_id('user');
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($teacher->id)->id,
            true
        );

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_dismiss_pending_entry',
                    'arguments' => ['identifier' => $identifier],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
    }

    /**
     * Creates an image file in the given user's material folder -
     * generated via GD instead of loaded from a fixture file, so that the test
     * does not have to ship a binary file. Wider than 768px so that the
     * preview is actually downscaled.
     *
     * @param \stdClass $user
     * @param string $filename
     * @return void
     */
    private function store_material_image(\stdClass $user, string $filename): void {
        $image = imagecreatetruecolor(1600, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $png);
    }

    /**
     * Spec 0018 §3.2/Issue #430: the dispatcher appends a second MCP content
     * block (type "image") to a successful image preview - base64 plus
     * mimeType, no detour via a string in the JSON.
     */
    public function test_preview_material_file_returns_mcp_image_content_block(): void {
        $this->resetAfterTest();
        [$user, $token] = $this->create_authenticated_user();
        $this->store_material_image($user, 'bild.png');

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_preview_material_file',
                    'arguments' => ['path' => 'bild.png'],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $content = $response['body']['result']['content'];
        $this->assertCount(2, $content, 'Text plus image block expected.');
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('image/jpeg', $content[1]['mimeType']);

        $decoded = base64_decode($content[1]['data'], true);
        $this->assertNotFalse($decoded, 'Image data must be valid base64.');
        $info = getimagesizefromstring($decoded);
        $this->assertNotFalse($info, 'Image data must yield an image readable by PHP.');
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertLessThanOrEqual(768, max($info[0], $info[1]));

        // The image byte blob is not sent through the context twice -
        // neither in the JSON text block nor in structuredContent.
        $this->assertArrayNotHasKey('image_base64', $response['body']['result']['structuredContent']);
        $decodedtext = json_decode($content[0]['text'], true);
        $this->assertArrayNotHasKey('image_base64', $decodedtext);
    }

    /**
     * Spec 0018 §3: a non-image file is not an error - "available":
     * false with an explanatory message, still exactly one text block (no
     * image block).
     */
    public function test_preview_of_non_image_file_returns_message_not_error(): void {
        $this->resetAfterTest();
        [$user, $token] = $this->create_authenticated_user();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], '%PDF-1.4 not a real PDF, good enough for the test');

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_preview_material_file',
                    'arguments' => ['path' => 'blatt.pdf'],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('isError', $response['body']['result']);
        $this->assertFalse($response['body']['result']['structuredContent']['available']);
        $this->assertNotEmpty($response['body']['result']['structuredContent']['message']);
        $this->assertCount(1, $response['body']['result']['content'], 'No image block without an image preview.');
    }

    /**
     * The second content type (#430) remains the only extension of the
     * dispatcher (Spec 0018 §3.2) - a tool without image fields still returns
     * exactly one text block with JSON plus structuredContent.
     */
    public function test_tools_without_image_fields_keep_single_text_content_block(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $result = $response['body']['result'];
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertArrayHasKey('structuredContent', $result);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertSame($result['structuredContent'], $decoded);
    }

    /**
     * #572, acceptance criterion: the material/workbench tools are callable
     * via the public dispatch path with fields declared directly in English -
     * read (coursepilot_list_material_files, parameter "location" instead of
     * "ort"), a write path (coursepilot_upload_material_file) and the pure
     * workbench read path (coursepilot_create_workbench_download_links, also
     * behind moodle/user:manageownfiles) with correct return keys.
     */
    public function test_material_and_werkbank_tools_are_callable_through_dispatcher_in_english(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        // Write tool: coursepilot_upload_material_file - already declared in
        // English before #572, here as the required real write path of the
        // group.
        $uploadresponse = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_upload_material_file',
                    'arguments' => ['path' => 'blatt.pdf', 'content_base64' => base64_encode('Content')],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $uploadresponse['status']);
        $this->assertArrayNotHasKey('isError', $uploadresponse['body']['result']);
        $uploaded = $uploadresponse['body']['result']['structuredContent'];
        $this->assertTrue($uploaded['created']);
        $this->assertArrayHasKey('message', $uploaded);

        // Read tool: coursepilot_list_material_files - the published
        // parameter name is "location", not the German "ort" from the
        // pre-#572 version; werkbank and bestand point to the same location
        // without a context pointer (Issue #495).
        $listresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_list_material_files',
                    'arguments' => ['location' => 'workbench'],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $listresponse['status']);
        $listed = $listresponse['body']['result']['structuredContent'];
        $this->assertSame(['blatt.pdf'], array_column($listed['entries'], 'name'));

        // Workbench read path: coursepilot_create_workbench_download_links -
        // purely reading (Issue #501), but behind the same
        // moodle/user:manageownfiles check as the write path above.
        $linksresponse = dispatcher::handle(
            [
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_create_workbench_download_links',
                    'arguments' => ['paths' => ['blatt.pdf']],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $linksresponse['status']);
        $links = $linksresponse['body']['result']['structuredContent']['links'];
        $this->assertSame('blatt.pdf', $links[0]['path']);
        $this->assertArrayHasKey('sha1', $links[0]);
        $this->assertArrayHasKey('url', $links[0]);

        // Without moodle/user:manageownfiles both the write path and the
        // workbench read path are blocked - the material boundary remains
        // permission-controlled, not just a matter of translation.
        // CAP_PROHIBIT on the base role "user" overrides every additional role
        // (here editingteacher) - the same proven approach as
        // test_dismiss_pending_entry_enforces_manageownfiles_through_dispatcher.
        [$restricteduser, $restrictedtoken] = $this->create_authenticated_user();
        $roleid = $this->get_role_id('user');
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($restricteduser->id)->id,
            true
        );

        $deniedresponse = dispatcher::handle(
            [
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_create_workbench_download_links',
                    'arguments' => ['paths' => ['blatt.pdf']],
                ],
            ],
            $restrictedtoken,
            $this->headers()
        );
        $this->assertSame(200, $deniedresponse['status']);
        $this->assertTrue($deniedresponse['body']['result']['isError']);
    }

    /**
     * #572, acceptance criterion: the clone tool and the lineage tool are
     * callable via the public dispatch path with fields declared directly in
     * English - coursepilot_clone_activity returns "message" instead of
     * "meldung", coursepilot_report_clone_lineage returns "source_course_id"
     * instead of "quellkurs_id" and likewise "message".
     */
    public function test_clone_and_lineage_tools_are_callable_through_dispatcher_in_english(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $cloneresponse = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_clone_activity',
                    'arguments' => ['cmid' => $page->cmid, 'title' => 'Clone'],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $cloneresponse['status']);
        $this->assertArrayNotHasKey('isError', $cloneresponse['body']['result']);
        $cloned = $cloneresponse['body']['result']['structuredContent'];
        $this->assertArrayHasKey('message', $cloned);
        $this->assertSame((int) $course->id, $cloned['courseid']);

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $lineageresponse = dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_report_clone_lineage',
                    'arguments' => ['cmid' => $quiz->cmid],
                ],
            ],
            $token,
            $this->headers()
        );
        $this->assertSame(200, $lineageresponse['status']);
        $lineage = $lineageresponse['body']['result']['structuredContent'];
        $this->assertSame([], $lineage['questions']);
        $this->assertArrayHasKey('message', $lineage);
        $this->assertArrayNotHasKey('meldung', $lineage);
    }

    /**
     * The error text of a tool reaches the caller in plain text.
     *
     * invalid_parameter_exception carries the actual message in
     * debuginfo; ->message is only the generic Moodle string
     * ("Invalid parameter value detected"). Anyone who passes on only ->message
     * thereby discards every message that this plugin's tools formulate -
     * "file too large" and "XML broken" arrive at the client as the same
     * meaningless sentence.
     */
    public function test_tool_error_detail_reaches_the_caller(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_export_questions_xml',
                    'arguments' => ['questionids' => []],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertTrue($response['body']['result']['isError']);
        $this->assertSame(
            'Specify at least one questionid.',
            $response['body']['result']['content'][0]['text']
        );
    }

    /**
     * A return contract error carries the only usable hint in debuginfo. It
     * goes into the log as a diagnostic hint, not to the MCP client (#457).
     */
    public function test_diagnostic_detail_keeps_invalid_response_debug_detail(): void {
        $exception = (object) [
            'errorcode' => 'invalidresponse',
            'message' => 'Invalid response value detected.',
            'debuginfo' => "Invalid response value detected in sections[0].modules[0].settings[2].value.\nError code: invalidresponse",
        ];
        $method = new \ReflectionMethod(dispatcher::class, 'diagnostic_detail');

        $this->assertSame(
            'Invalid response value detected in sections[0].modules[0].settings[2].value.',
            $method->invoke(null, $exception)
        );
    }

    /**
     * The rule, not the individual case (#466): EVERY response with a
     * 'result' carries a 'resultType' in revision 2026-07-28 - the revision
     * makes the field mandatory for all results, not only for tools/call.
     *
     * The method list is derived from the dispatcher's case labels, not
     * maintained by hand: the trigger for this round was a single forgotten
     * branch (the error path of tools/call), and a hand-maintained list would
     * have allowed the same mistake a second time. A new branch is thus
     * tested automatically - whoever adds it must either answer it correctly
     * or deliberately list it under the result-less methods.
     *
     * The legacy era remains field-free in mirror image: a 2025-06-18 client
     * discards a response WITH these fields (#400).
     */
    public function test_every_result_carries_resulttype_in_the_modern_era(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        foreach ($this->result_bearing_methods() as $method) {
            $request = ['id' => 1, 'method' => $method] + $this->arguments_for($method);

            $modern = dispatcher::handle($request, $token, $this->headers([
                'protocolversion' => dispatcher::MODERN_VERSION,
            ]));
            $legacy = dispatcher::handle($request, $token, $this->headers([
                'protocolversion' => dispatcher::LEGACY_VERSION,
            ]));

            $this->assertSame('complete', ((array) $modern['body']['result'])['resultType'] ?? null, $method);
            $this->assertArrayNotHasKey('resultType', (array) $legacy['body']['result'], $method);
        }
    }

    /**
     * The error branch of tools/call as well - it was the trigger (#466) and
     * is the only result branch that the derived list above does not reach:
     * it calls every method on its success path.
     */
    public function test_tool_error_result_carries_resulttype(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $request = [
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'coursepilot_export_questions_xml',
                'arguments' => ['questionids' => []],
            ],
        ];

        $modern = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));
        $legacy = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));

        $this->assertTrue($modern['body']['result']['isError']);
        $this->assertTrue($legacy['body']['result']['isError']);
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
        // Caching fields do not belong on tools/call (#458) - not on the
        // error response either.
        $this->assertArrayNotHasKey('ttlMs', $modern['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $modern['body']['result']);
    }

    /**
     * The JSON-RPC methods that the dispatcher answers with a 'result' -
     * read off its case labels.
     *
     * @return string[]
     */
    private function result_bearing_methods(): array {
        $source = file_get_contents(__DIR__ . '/../classes/dispatcher.php');
        preg_match_all("/case '([a-z\/]+)':/", $source, $matches);
        $methods = array_values(array_unique($matches[1]));

        // JSON-RPC does not answer notifications at all (202, empty body) -
        // they are the only permitted case without a 'result'.
        $withoutresult = ['notifications/initialized', 'notifications/cancelled'];

        $this->assertNotEmpty($methods, 'No case labels found in the dispatcher');

        return array_values(array_diff($methods, $withoutresult));
    }

    /**
     * The parameters a method needs to succeed - empty for all that get by
     * without.
     *
     * @param string $method
     * @return array
     */
    private function arguments_for(string $method): array {
        if ($method === 'tools/call') {
            return ['params' => ['name' => 'coursepilot_list_courses']];
        }
        return [];
    }

    /**
     * A response without content remains a JSON object, not an empty array.
     *
     * Per the specification, ping returns an empty result object. In the
     * legacy era no metadata is added - anyone who uses an empty PHP array
     * for this sends "[]" instead of "{}" and breaks the schema.
     */
    public function test_ping_result_stays_an_object_in_the_legacy_era(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            $token,
            $this->headers(['protocolversion' => dispatcher::LEGACY_VERSION])
        );

        $this->assertStringContainsString(
            '"result":{}',
            json_encode($response['body'])
        );
    }

    /**
     * The result metadata of revision 2026-07-28 (resultType/ttlMs/
     * cacheScope) goes only to clients that negotiate exactly this revision.
     *
     * A 2025-06-18 client (Codex, rmcp) discards a tools/call response with
     * these fields entirely ("Unexpected response type", #400) - they are not
     * provided for in its revision.
     */
    public function test_result_metadata_only_for_modern_protocol_version(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $request = [
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'coursepilot_get_course_catalog',
                'arguments' => ['courseid' => $course->id],
            ],
        ];

        $legacy = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));
        $modern = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));
        $unknown = dispatcher::handle($request, $token, $this->headers());

        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
        $this->assertArrayNotHasKey('resultType', $unknown['body']['result']);
        // Issue #458: 'complete' is the only success value that the revision knows
        // for tools/call ('input_required' remains reserved for the MRTR
        // pattern, which we do not offer). Caching fields belong exclusively
        // on list responses - a ttlMs on a write operation suggests to a
        // client that it cache it.
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertArrayNotHasKey('ttlMs', $modern['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $modern['body']['result']);

        $list = ['id' => 2, 'method' => 'tools/list'];
        $legacylist = dispatcher::handle($list, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));
        $modernlist = dispatcher::handle($list, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));
        $this->assertArrayNotHasKey('resultType', $legacylist['body']['result']);
        $this->assertSame('complete', $modernlist['body']['result']['resultType']);
    }

    /**
     * tools/call rejects a tool that is not listed.
     */
    public function test_tools_call_rejects_unlisted_tool(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'not_a_real_tool']],
            $token,
            $this->headers()
        );

        $this->assertSame(404, $response['status']);
        $this->assertSame(-32601, $response['body']['error']['code']);
    }

    /**
     * #401: the three discovery methods that Codex queries unprompted after
     * the handshake return an empty list in the respective correct field
     * instead of a 404 - courtesy, not an offered feature.
     */
    public function test_resources_list_returns_empty_list(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'resources/list'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['result']['resources']);
    }

    /**
     * #401: the three new discovery methods follow the same era switch as
     * tools/list (see test_result_metadata_only_for_modern_protocol_version)
     * - checked representatively on resources/list; the same resultmeta()
     * funnel point serves the other two as well.
     */
    public function test_resources_list_result_metadata_only_for_modern_protocol_version(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();
        $request = ['id' => 1, 'method' => 'resources/list'];

        $legacy = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));
        $modern = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));

        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertSame(300000, $modern['body']['result']['ttlMs']);
        $this->assertSame('private', $modern['body']['result']['cacheScope']);
    }

    /**
     * #401: like test_resources_list_returns_empty_list, for
     * resources/templates/list.
     */
    public function test_resources_templates_list_returns_empty_list(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'resources/templates/list'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['result']['resourceTemplates']);
    }

    /**
     * #401: like test_resources_list_returns_empty_list, for prompts/list.
     */
    public function test_prompts_list_returns_empty_list(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'prompts/list'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['result']['prompts']);
    }

    /**
     * #401, acceptance criterion: the three new discovery methods are not a
     * catch-all for typos - a truly unknown method name still returns
     * 404/-32601.
     */
    public function test_truly_unknown_method_still_returns_404(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'resources/subscribe'], $token, $this->headers());

        $this->assertSame(404, $response['status']);
        $this->assertSame(-32601, $response['body']['error']['code']);
    }

    /**
     * Without an Origin header the origin check does not apply.
     */
    public function test_origin_check_is_skipped_without_header(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(['id' => 1, 'method' => 'ping'], null, $this->headers(['origin' => null]));

        $this->assertNotSame(403, $response['status']);
    }

    /**
     * With a present, disallowed Origin header the check applies.
     */
    public function test_origin_check_rejects_disallowed_origin(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            null,
            $this->headers(['origin' => 'https://evil.example'])
        );

        $this->assertSame(403, $response['status']);
    }

    /**
     * A rejected origin also produces a failure event (#339) - this branch
     * sits before handle_authorized() and does not go through error(), so it
     * needs its own test.
     */
    public function test_origin_rejection_triggers_failure_event(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            null,
            $this->headers(['origin' => 'https://evil.example'])
        );

        $events = array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_failed);
        $this->assertCount(1, $events);
        $sink->close();
    }

    /**
     * CORS preflight (#337 follow-up): a browser fetch() with an Authorization
     * header from an allowed origin sends OPTIONS first. Without the matching
     * Access-Control-* headers the browser blocks the actual POST on the
     * client side - never visible from the server, recognizable only by the
     * connection error on the client side (finding from the Claude.ai custom
     * connector live test).
     */
    public function test_options_preflight_returns_cors_headers_for_allowed_origin(): void {
        $response = dispatcher::handle(
            null,
            null,
            $this->headers(['origin' => 'https://claude.ai', 'method' => 'OPTIONS'])
        );

        $this->assertSame(204, $response['status']);
        $this->assertSame('https://claude.ai', $response['headers']['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('POST', $response['headers']['Access-Control-Allow-Methods']);
    }

    /**
     * The CORS header belongs not only on the preflight but also on the
     * actual response - otherwise the browser discards it despite a
     * successful preflight (#337 follow-up).
     */
    public function test_actual_response_also_carries_cors_header_for_allowed_origin(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            null,
            $this->headers(['origin' => 'https://claude.ai'])
        );

        $this->assertSame('https://claude.ai', $response['headers']['Access-Control-Allow-Origin']);
    }

    /**
     * Error responses are JSON-capable arrays, never HTML.
     */
    public function test_error_responses_are_json_not_html(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(null, null, $this->headers());

        $this->assertIsArray($response['body']);
        $encoded = json_encode($response['body']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('<html', strtolower($encoded));
    }

    /**
     * A valid OAuth access token is mapped to the right person - the tool
     * call runs as this user, not as anyone else (#337).
     */
    public function test_valid_oauth_token_is_mapped_to_correct_person(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers(['protocolversion' => dispatcher::MODERN_VERSION])
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame((int) $course->id, $response['body']['result']['structuredContent']['courses'][0]['id']);
        // Result metadata of revision 2026-07-28 (#337 follow-up, finding from
        // the Claude Code live test: cacheScope "session" is not a valid
        // value, only "public"/"private" - made every tools/call fail).
        // #458: the same live test a second time - 'data' is not a valid
        // resultType at all, and caching fields have no business on a
        // tools/call response.
        $this->assertSame('complete', $response['body']['result']['resultType']);
        $this->assertArrayNotHasKey('ttlMs', $response['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $response['body']['result']);
    }

    /**
     * Person A never sees courses of person B under any circumstances - the
     * tool call runs strictly as the user stored in the token (#337).
     */
    public function test_person_a_never_sees_courses_of_person_b(): void {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course(['shortname' => 'course-a']);
        $courseb = $this->getDataGenerator()->create_course(['shortname' => 'course-b']);
        [$persona, $tokena] = $this->create_authenticated_user();
        [$personb, $tokenb] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($persona->id, $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($personb->id, $courseb->id, 'editingteacher');

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $tokena,
            $this->headers()
        );

        $ids = array_column($response['body']['result']['structuredContent']['courses'], 'id');
        $this->assertSame([(int) $coursea->id], $ids);
        $this->assertNotContains((int) $courseb->id, $ids);
    }

    /**
     * A Moodle web service token (external_tokens, the former workaround) is
     * no longer accepted (#337).
     */
    public function test_moodle_webservice_token_is_no_longer_accepted(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = $DB->get_record('external_services', ['shortname' => privacy_surface::SERVICE_SHORTNAME]);
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $user->id,
            \context_system::instance()
        );

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * An expired OAuth access token is rejected (#337).
     */
    public function test_expired_oauth_token_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_access_token($user->id, -60);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * A revoked OAuth access token (revoked=1, e.g. through refresh rotation)
     * is rejected (#337).
     */
    public function test_revoked_oauth_token_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_access_token($user->id, 3600, true);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * A valid token without local/coursepilot:useremote is rejected -
     * concretely, with the capability name, even if the token itself is valid
     * (#337).
     */
    public function test_valid_token_without_useremote_capability_is_rejected(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user(false);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(403, $response['status']);
        $this->assertSame(-32002, $response['body']['error']['code']);
        $this->assertStringContainsString('local/coursepilot:useremote', $response['body']['error']['message']);
    }

    /**
     * If local/coursepilot:use is missing in every course, the remote access
     * capability alone is not enough - the dispatcher passes the concrete
     * course capability error from list_courses::execute() through unchanged
     * instead of hiding it (#337, acceptance criterion "permission message,
     * not an empty list").
     */
    public function test_useremote_alone_does_not_bypass_course_level_capability(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
        $this->assertStringContainsString('CAPABILITY_MISSING:local/coursepilot:use', $response['body']['result']['content'][0]['text']);
    }

    /**
     * Global kill switch (#338): remoteaccessenabled=0 blocks every further
     * access immediately - even with a valid token and an existing capability.
     */
    public function test_kill_switch_blocks_access_even_with_valid_token(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();
        set_config('remoteaccessenabled', 0, 'local_coursepilot');

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(403, $response['status']);
        $this->assertSame(-32003, $response['body']['error']['code']);
    }

    /**
     * Without a configured value (fresh installation, setting never visited)
     * remote access remains usable - the default is "on".
     */
    public function test_kill_switch_defaults_to_enabled(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
    }

    /**
     * After the bulk revocation (#338) an access with the old token fails -
     * the same auth gate path as for expiry/single revocation.
     */
    public function test_access_with_token_fails_after_bulk_revoke(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        oauth_lib::revoke_all_tokens();
        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * A successful tool call produces an event via the Moodle events API -
     * default setting "read accesses and errors" (#339).
     */
    public function test_successful_tool_call_triggers_access_event(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $sink = $this->redirectEvents();

        dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $events = array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_succeeded);
        $this->assertCount(1, $events);
        $event = array_values($events)[0];
        $this->assertSame('coursepilot_list_courses', $event->other['toolname']);
        $sink->close();
    }

    /**
     * A failed access (invalid token) produces a failure event (#339).
     */
    public function test_failed_authentication_triggers_failure_event(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        dispatcher::handle(['id' => 1, 'method' => 'initialize'], 'not-a-real-token', $this->headers());

        $events = array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_failed);
        $this->assertCount(1, $events);
        $sink->close();
    }

    /**
     * At level "no logging" no entry is created, not even for a failed
     * access (#339).
     */
    public function test_no_events_at_all_when_logging_disabled(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_NONE, 'local_coursepilot');
        [, $token] = $this->create_authenticated_user();
        $sink = $this->redirectEvents();

        dispatcher::handle(['id' => 1, 'method' => 'initialize'], 'not-a-real-token', $this->headers());
        dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $events = array_filter(
            $sink->get_events(),
            fn ($e) => $e instanceof event\tool_access_succeeded || $e instanceof event\tool_access_failed
        );
        $this->assertCount(0, $events);
        $sink->close();
    }

    /**
     * At level "errors only" no entry is created for a successful access, but
     * one is for an error (#339).
     */
    public function test_errors_only_level_skips_successful_access(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $sink = $this->redirectEvents();

        dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );
        dispatcher::handle(['id' => 1, 'method' => 'initialize'], 'not-a-real-token', $this->headers());

        $this->assertCount(
            0,
            array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_succeeded)
        );
        $this->assertCount(
            1,
            array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_failed)
        );
        $sink->close();
    }

    /**
     * No access secret ends up in the log text - not even in the error text of
     * a real auth error triggered via the dispatcher (#339).
     */
    public function test_access_token_never_appears_in_a_logged_event(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        $secrettoken = oauth_lib::random_token(32);

        dispatcher::handle(['id' => 1, 'method' => 'initialize'], $secrettoken, $this->headers());

        foreach ($sink->get_events() as $event) {
            $encoded = json_encode($event->get_data());
            $this->assertStringNotContainsString($secrettoken, $encoded);
        }
        $sink->close();
    }
}
