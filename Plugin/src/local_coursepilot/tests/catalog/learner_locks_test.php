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

namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Catalog learner-lock contract (Issue #583): every catalog answers
 * learner_locks() and grade_origin(); each condition is evaluable and
 * consistent with its field's type and value range.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(learner_locks::class)]
#[CoversClass(assign::class)]
#[CoversClass(quiz::class)]
#[CoversClass(choice::class)]
#[CoversClass(forum::class)]
#[CoversClass(label::class)]
#[CoversClass(page::class)]
#[CoversClass(url::class)]
#[CoversClass(folder::class)]
#[CoversClass(resource::class)]
final class learner_locks_test extends \advanced_testcase {
    /**
     * Provides fields by name.
     *
     * @param string $catalogclass Type: class-string<module_catalog>.
     * @return array<string, field>
     */
    private function fields_by_name(string $catalogclass): array {
        $byname = [];
        $fields = array_merge(shared_block::fields(), $catalogclass::fields(), $catalogclass::pseudofields());
        foreach ($fields as $field) {
            $byname[$field->name] = $field;
        }
        return $byname;
    }

    public function test_every_registered_catalog_declares_valid_locks(): void {
        foreach (registry::known_modnames() as $modname) {
            $catalogclass = registry::for($modname);
            $this->assertTrue(is_subclass_of($catalogclass, module_catalog::class), $modname);
            $fields = $this->fields_by_name($catalogclass);

            foreach ($catalogclass::learner_locks() as $fieldname => $condition) {
                $where = $modname . '.' . $fieldname;
                $this->assertArrayHasKey($fieldname, $fields, $where . ': Feld fehlt im Katalog.');
                $this->assertContains($condition['op'] ?? null, learner_locks::OPS, $where . ': unbekannter Operator.');
                $this->assertNotSame('', trim((string) ($condition['reason'] ?? '')), $where . ': Grund fehlt.');

                $field = $fields[$fieldname];
                if ($condition['op'] === 'nonzero' || $condition['op'] === 'greater') {
                    $this->assertSame('PARAM_INT', $field->type, $where . ': Zahlbedingung auf Nicht-Zahlfeld.');
                }
                if ($condition['op'] !== 'nonzero') {
                    $this->assertArrayHasKey('value', $condition, $where . ': Vergleichswert fehlt.');
                }
                if (in_array($condition['op'], ['equals', 'not_equals'], true) && $field->values !== null) {
                    $this->assertContains($condition['value'], $field->values, $where . ': Wert ausserhalb des Wertebereichs.');
                }
            }
        }
    }

    public function test_every_registered_catalog_declares_a_grade_origin(): void {
        foreach (registry::known_modnames() as $modname) {
            $this->assertContains(registry::for($modname)::grade_origin(), learner_locks::GRADE_ORIGINS, $modname);
        }
        $this->assertSame(learner_locks::GRADE_TEACHER, assign::grade_origin());
        $this->assertSame(learner_locks::GRADE_TEACHER, forum::grade_origin());
        $this->assertSame(learner_locks::GRADE_AUTOMATIC, quiz::grade_origin());
        $this->assertSame(learner_locks::GRADE_NONE, label::grade_origin());
    }

    /**
     * Edge case #583: a quiz with an essay question needs teacher grading;
     * the instance-level answer refines the activity-type answer.
     */
    public function test_quiz_with_manually_graded_question_counts_as_teacher_graded(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);

        quiz_add_quiz_question($questiongenerator->create_question('truefalse', null, ['category' => $category->id])->id, $quiz);
        $this->assertSame(learner_locks::GRADE_AUTOMATIC, quiz::grade_origin((int) $quiz->id));

        quiz_add_quiz_question($questiongenerator->create_question('essay', null, ['category' => $category->id])->id, $quiz);
        $this->assertSame(learner_locks::GRADE_TEACHER, quiz::grade_origin((int) $quiz->id));
    }

    public function test_initial_lock_inventory_from_582(): void {
        $this->assertSame(
            ['submissiondrafts', 'attemptreopenmethod', 'cutoffdate', 'timelimit', 'requireallteammemberssubmit',
                'preventsubmissionnotingroup', 'markingworkflow', 'blindmarking'],
            array_keys(assign::learner_locks())
        );
        $this->assertSame(
            ['attempts', 'navmethod', 'timeclose', 'timelimit', 'quizpassword', 'subnet', 'browsersecurity'],
            array_keys(quiz::learner_locks())
        );
        $this->assertSame(['allowupdate', 'timeclose'], array_keys(choice::learner_locks()));
        $this->assertSame(['cutoffdate', 'lockdiscussionafter', 'blockafter'], array_keys(forum::learner_locks()));
        foreach (['label', 'page', 'url', 'folder', 'resource'] as $modname) {
            $this->assertSame([], registry::for($modname)::learner_locks(), $modname);
        }
    }

    public function test_matches_evaluates_each_operator(): void {
        $this->assertTrue(learner_locks::matches(['op' => 'equals', 'value' => 'manual'], 'manual'));
        $this->assertFalse(learner_locks::matches(['op' => 'equals', 'value' => 'manual'], 'untilpass'));
        $this->assertTrue(learner_locks::matches(['op' => 'equals', 'value' => 1], '1'));
        $this->assertTrue(learner_locks::matches(['op' => 'equals', 'value' => 1], true));
        $this->assertFalse(learner_locks::matches(['op' => 'equals', 'value' => 0], null));
        $this->assertTrue(learner_locks::matches(['op' => 'not_equals', 'value' => '-'], 'safebrowser'));
        $this->assertFalse(learner_locks::matches(['op' => 'not_equals', 'value' => '-'], '-'));
        $this->assertFalse(learner_locks::matches(['op' => 'not_equals', 'value' => ''], null));
        $this->assertTrue(learner_locks::matches(['op' => 'greater', 'value' => 0], 2));
        $this->assertFalse(learner_locks::matches(['op' => 'greater', 'value' => 0], 0));
        $this->assertFalse(learner_locks::matches(['op' => 'greater', 'value' => 0], -1));
        $this->assertTrue(learner_locks::matches(['op' => 'nonzero'], 1767225600));
        $this->assertFalse(learner_locks::matches(['op' => 'nonzero'], '0'));
    }

    public function test_find_names_named_and_default_locks(): void {
        $found = learner_locks::find(assign::class, ['attemptreopenmethod' => 'manual'], ['submissiondrafts' => 1]);

        $this->assertSame(['submissiondrafts', 'attemptreopenmethod'], array_column($found, 'id'));
        $this->assertStringContainsString('form default', $found[0]['detail']);
        $this->assertStringContainsString('"attemptreopenmethod" = "manual"', $found[1]['detail']);
    }

    public function test_find_changed_skips_a_lock_the_patch_only_repeats(): void {
        $before = ['attemptreopenmethod' => 'manual', 'submissiondrafts' => 0];

        $this->assertSame([], learner_locks::find_changed(assign::class, ['attemptreopenmethod' => 'manual'], $before));
        $this->assertSame(
            ['submissiondrafts'],
            array_column(learner_locks::find_changed(assign::class, ['submissiondrafts' => 1], $before), 'id')
        );
    }

    /**
     * Normal presets avoid learner locks. Exception: quiz final-test, where
     * limited attempts are intentional and mode selection confirms the lock
     * (learner_locks::confirmed_with_mode()).
     */
    public function test_bundles_set_no_lock_except_the_final_test_mode(): void {
        foreach (registry::known_modnames() as $modname) {
            $catalogclass = registry::for($modname);
            foreach ($catalogclass::bundles() as $name => $values) {
                $ids = array_column(learner_locks::find($catalogclass, $values), 'id');
                $expected = ($modname === 'quiz' && $name === 'final-test') ? ['attempts'] : [];
                $this->assertSame($expected, $ids, $modname . '/' . $name);
            }
        }
    }

    public function test_assert_confirmed_rejects_unconfirmed_locks_and_names_them(): void {
        $found = learner_locks::find(assign::class, ['attemptreopenmethod' => 'manual', 'cutoffdate' => 1767225600]);

        try {
            learner_locks::assert_confirmed('assign', $found, ['cutoffdate']);
            $this->fail('An unconfirmed learner lock must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
            $this->assertStringContainsString('attemptreopenmethod', $e->getMessage());
            $this->assertStringNotContainsString('"cutoffdate"', $e->getMessage());
        }

        learner_locks::assert_confirmed('assign', $found, ['cutoffdate', 'attemptreopenmethod']);
    }

    public function test_chosen_mode_confirms_only_the_locks_it_brings(): void {
        $bundle = quiz::bundles()['final-test'];

        $this->assertContains('attempts', learner_locks::confirmed_with_mode([], $bundle, []));
        $this->assertNotContains('attempts', learner_locks::confirmed_with_mode([], $bundle, ['attempts' => 5]));
        $this->assertContains('timeclose', learner_locks::confirmed_with_mode(['timeclose'], $bundle, []));
    }

    public function test_matches_rejects_an_unknown_operator(): void {
        $this->assertFalse(learner_locks::matches(['op' => 'contains', 'value' => 1], 1));
    }

    public function test_existing_reads_settings_and_catalog_aliases(): void {
        $locks = learner_locks::existing(quiz::class, ['password' => 'geheim', 'attempts' => 0, 'navmethod' => 'free']);

        $this->assertSame(['quizpassword'], array_column($locks, 'field'));
        $this->assertSame('"geheim"', $locks[0]['value_json']);
        $this->assertNotSame('', $locks[0]['reason']);
    }

    public function test_condition_json_is_null_for_fields_that_cannot_lock(): void {
        $this->assertSame('null', learner_locks::condition_json(assign::class, 'name'));
        $this->assertSame('manual', json_decode(learner_locks::condition_json(assign::class, 'attemptreopenmethod'))->value);
    }

    public function test_confirm_parameter_defaults_to_an_empty_list(): void {
        $parameter = learner_locks::confirm_parameter();

        $this->assertInstanceOf(\core_external\external_multiple_structure::class, $parameter);
        $this->assertSame(VALUE_DEFAULT, $parameter->required);
        $this->assertSame([], $parameter->default);
    }

    public function test_graded_instances_refine_the_activity_type_origin(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gradedassign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $ungradedassign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 0]);
        $ratedforum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'assessed' => 1, 'scale' => 10]);
        $plainforum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);

        $this->assertSame(learner_locks::GRADE_TEACHER, assign::grade_origin((int) $gradedassign->id));
        $this->assertSame(learner_locks::GRADE_NONE, assign::grade_origin((int) $ungradedassign->id));
        $this->assertSame(learner_locks::GRADE_TEACHER, forum::grade_origin((int) $ratedforum->id));
        $this->assertSame(learner_locks::GRADE_NONE, forum::grade_origin((int) $plainforum->id));
    }
}
