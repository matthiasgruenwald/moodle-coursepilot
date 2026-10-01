<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * Moves one question-bank entry, including every historical version.
 */
class move_question extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'questionid einer beliebigen Version der zu verschiebenden Frage'),
            'targetcategoryid' => new external_value(PARAM_INT, 'ID der Ziel-Fragenbank-Kategorie'),
        ]);
    }

    public static function execute(int $questionid, int $targetcategoryid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'targetcategoryid' => $targetcategoryid,
        ]);

        $version = $DB->get_record('question_versions', ['questionid' => $params['questionid']], '*', MUST_EXIST);
        $entry = $DB->get_record('question_bank_entries', ['id' => $version->questionbankentryid], '*', MUST_EXIST);
        $sourcecategory = $DB->get_record('question_categories', ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $targetcategory = $DB->get_record('question_categories', ['id' => $params['targetcategoryid']], '*', MUST_EXIST);
        $sourcecontext = \context::instance_by_id($sourcecategory->contextid);
        $targetcontext = \context::instance_by_id($targetcategory->contextid);

        self::validate_context($sourcecontext);
        require_capability('local/coursepilot:use', $sourcecontext);
        self::validate_context($targetcontext);
        require_capability('local/coursepilot:use', $targetcontext);
        require_capability('moodle/question:add', $targetcontext);
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id], 'version ASC');
        $questionids = array_values(array_map(static fn($item): int => (int) $item->questionid, $versions));
        foreach ($questionids as $versionid) {
            $question = $DB->get_record('question', ['id' => $versionid], '*', MUST_EXIST);
            question_require_capability_on($question, 'move');
        }

        \core_question\external\move_questions::execute(
            $targetcontext->id,
            $targetcategory->id,
            implode(',', $questionids),
            '/question/bank/managecategories/category.php'
        );

        $entry = $DB->get_record('question_bank_entries', ['id' => $entry->id], '*', MUST_EXIST);
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id], 'version ASC');

        return [
            'questionbankentryid' => (int) $entry->id,
            'targetcategoryid' => (int) $entry->questioncategoryid,
            'versionids' => array_values(array_map(static fn($item): int => (int) $item->questionid, $versions)),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionbankentryid' => new external_value(PARAM_INT, 'Unveränderte Identität des question_bank_entries'),
            'targetcategoryid' => new external_value(PARAM_INT, 'Frisch gelesene Zielkategorie'),
            'versionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'questionid einer erhaltenen Version'),
                'Alle Versionen der Frage in Versionsreihenfolge'
            ),
        ]);
    }
}
