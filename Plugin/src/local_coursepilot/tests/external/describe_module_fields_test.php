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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Field catalog retrieval (#379): catalog structure, shared fields and
 * label as the first fully cataloged activity type.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(describe_module_fields::class)]
final class describe_module_fields_test extends \advanced_testcase {
    /**
     * Without modname, list supported activity types (user story 13).
     */
    public function test_without_modname_lists_known_activity_types(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = describe_module_fields::execute();
        $result = external_api::clean_returnvalue(describe_module_fields::execute_returns(), $result);

        $this->assertContains('label', $result['known_modnames']);
        $this->assertNull($result['module']);
        $this->assertNotSame('', trim($result['notice']));
    }

    /**
     * Compact output contains fields and bundles, omitting the other four
     * categories with an explicit notice that more detail is available.
     */
    public function test_short_form_omits_extra_categories_and_says_so(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = describe_module_fields::execute('label');
        $result = external_api::clean_returnvalue(describe_module_fields::execute_returns(), $result);

        $this->assertNotEmpty($result['module']['fields']);
        $this->assertSame([], $result['module']['pseudo_fields']);
        $this->assertSame([], $result['module']['blocked_fields']);
        $this->assertSame([], $result['module']['combination_rules']);
        $this->assertSame([], $result['module']['side_effects']);
        $this->assertStringContainsString('full', $result['notice']);
    }

    /**
     * Full output contains all five categories and differs from compact output.
     */
    public function test_full_form_includes_all_five_categories(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $short = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', false)
        );
        $full = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', true)
        );

        $this->assertNotEquals($short, $full, 'Short form and full form must differ.');

        $sperrliste = $full['module']['blocked_fields'];
        $this->assertContains('name', $sperrliste, 'label.name must be blocked - it is derived from the intro.');
        $this->assertContains('course', $sperrliste);
        $this->assertContains('timemodified', $sperrliste);

        $pseudonames = array_column($full['module']['pseudo_fields'], 'name');
        $this->assertContains('coursepagevisibility', $pseudonames);
    }

    /**
     * Output includes shared course_modules fields and label-specific fields
     * without duplicating the shared block in the label class.
     */
    public function test_shared_block_appears_alongside_label_fields(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', true)
        );

        $names = array_column($result['module']['fields'], 'name');
        $this->assertContains('intro', $names, "label's own field is missing.");
        $this->assertContains('visible', $names, 'Gemeinsamer Block fehlt.');
        $this->assertContains('groupmode', $names, 'Gemeinsamer Block fehlt.');
        $this->assertContains('idnumber', $names, 'Gemeinsamer Block fehlt.');

        // No duplicates: each field name appears exactly once.
        $this->assertSame(count($names), count(array_unique($names)), 'A field is duplicated.');
    }

    /**
     * Every catalog field has an explanatory meaning, not merely a field name.
     */
    public function test_every_field_carries_a_german_meaning(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('label', true)
        );

        $allfields = array_merge($result['module']['fields'], $result['module']['pseudo_fields']);
        $this->assertNotEmpty($allfields);
        foreach ($allfields as $field) {
            $this->assertNotSame('', trim($field['meaning']), $field['name'] . ' has no meaning text.');
        }
    }

    /**
     * All four activity types added in #380 support compact and full output.
     */
    public function test_answers_for_page_url_folder_resource_short_and_full(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach (['page', 'url', 'folder', 'resource', 'choice', 'forum', 'assign', 'quiz'] as $modname) {
            $short = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, false)
            );
            $this->assertNotEmpty($short['module']['fields'], "$modname: short form returns no fields.");

            $full = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, true)
            );
            $this->assertNotEmpty($full['module']['pseudo_fields'], "$modname: full form without pseudo fields.");
            $this->assertNotEmpty($full['module']['blocked_fields'], "$modname: full form without blocklist.");
        }
    }

    /**
     * Moodle 5.0 removed printheading; exclude it from the page catalog (#380).
     */
    public function test_page_catalog_omits_printheading(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $full = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('page', true)
        );

        $allnames = array_merge(
            array_column($full['module']['fields'], 'name'),
            array_column($full['module']['pseudo_fields'], 'name')
        );
        $this->assertNotContains('printheading', $allnames);
    }

    /**
     * Fully catalog resource/folder file pseudofields and keep them on
     * the denylist until Spec 0018.
     */
    public function test_file_fields_are_catalogued_and_unlocked(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach (['resource', 'folder'] as $modname) {
            $full = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, true)
            );

            $pseudonames = array_column($full['module']['pseudo_fields'], 'name');
            $this->assertContains('files', $pseudonames, "$modname: 'files' is missing from the pseudo fields.");
            $this->assertNotContains(
                'files',
                $full['module']['blocked_fields'],
                "$modname: 'files' must no longer be blocked since issue #434."
            );
        }
    }

    /**
     * Compact assign output lists common fields and bundles plus a more-detail
     * notice, but not all fields (#382). This stresses two-level output
     * (Spec 0015 §3.1: about 30 instance columns, normally 12 needed).
     */
    public function test_assign_short_form_uses_common_fields_subset(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $short = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('assign', false)
        );
        $full = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('assign', true)
        );

        $shortnames = array_column($short['module']['fields'], 'name');
        $fullnames = array_column($full['module']['fields'], 'name');

        $this->assertContains('name', $shortnames);
        $this->assertContains('duedate', $shortnames);
        $this->assertNotContains('markinganonymous', $shortnames, 'Short form must not list all fields.');
        $this->assertLessThan(count($fullnames), count($shortnames));

        $this->assertNotEmpty($short['module']['field_bundles']);
        $bundlenames = array_column($short['module']['field_bundles'], 'name');
        $this->assertContains('standard', $bundlenames);
        $this->assertContains('exercise', $bundlenames);

        $this->assertContains('markinganonymous', $fullnames, 'Full form must contain all fields.');
    }

    /**
     * Each field exposes its learner-restriction condition as JSON, or
     * null when no restriction is possible, plus note provenance (#583).
     */
    public function test_fields_carry_their_learner_lock_condition(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('assign', true)
        );
        $locks = array_column($result['module']['fields'], 'learner_lock', 'name');

        $lock = json_decode($locks['attemptreopenmethod'], true);
        $this->assertSame('equals', $lock['op']);
        $this->assertSame('manual', $lock['value']);
        $this->assertNotSame('', $lock['reason']);
        $this->assertSame('null', $locks['name']);
        $this->assertSame('teacher', $result['module']['grade_origin']);
    }

    /**
     * Unknown types produce a message naming supported types.
     */
    public function test_unknown_modname_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        describe_module_fields::execute('unbekanntermodultyp');
    }

    /**
     * Reading creates no new Coursepilot capability.
     */
    public function test_introduces_no_write_capability(): void {
        global $DB;

        $this->resetAfterTest();

        $names = $DB->get_fieldset_select('capabilities', 'name', 'component = :component', ['component' => 'local_coursepilot']);
        sort($names);

        $this->assertSame(
            ['local/coursepilot:restoreversion', 'local/coursepilot:use', 'local/coursepilot:useremote', 'local/coursepilot:viewhistory'],
            $names
        );
    }
}
