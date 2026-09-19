<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/choice/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_module;

/**
 * Updates the name, intro and/or options of an existing mod_choice
 * (Abstimmung) activity. Option handling reuses choice_update_instance(), the
 * same code path as the Moodle edit form: existing options are matched by
 * position (preserving their IDs), surplus new options are appended, and
 * options left empty are removed.
 */
class update_choice extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'name'    => new external_value(PARAM_TEXT, 'New choice title', VALUE_DEFAULT, ''),
            'intro'   => new external_value(PARAM_RAW,  'New description (optional)', VALUE_DEFAULT, ''),
            'options' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Option text'),
                'New choice options, 2 to 6 entries (empty = keep existing options)',
                VALUE_DEFAULT,
                []
            ),
            'visible'   => new external_value(PARAM_INT,  '1 = visible, 0 = hidden, -1 = no change', VALUE_DEFAULT, -1),
            'timeopen'  => new external_value(PARAM_INT,  'Opening timestamp (Unix, 0 = no limit). -1 = no change.', VALUE_DEFAULT, -1),
            'timeclose' => new external_value(PARAM_INT,  'Closing timestamp (Unix, 0 = no limit). -1 = no change.', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(
        int $cmid,
        string $name = '',
        string $intro = '',
        array $options = [],
        int $visible = -1,
        int $timeopen = -1,
        int $timeclose = -1
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'name'    => $name,
            'intro'   => $intro,
            'options' => $options,
            'visible' => $visible,
            'timeopen' => $timeopen,
            'timeclose' => $timeclose,
        ]);

        $cm = get_coursemodule_from_id('choice', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $choice = $DB->get_record('choice', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($params['name'] !== '') {
            $choice->name = $params['name'];
        }
        if ($params['intro'] !== '') {
            $choice->intro = $params['intro'];
            $choice->introformat = FORMAT_HTML;
        }
        if ($params['timeopen'] >= 0) {
            $choice->timeopen = $params['timeopen'];
        }
        if ($params['timeclose'] >= 0) {
            $choice->timeclose = $params['timeclose'];
        }
        $choice->instance = $choice->id;
        $choice->coursemodule = $cm->id;

        $existingoptions = array_values($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id ASC'));

        $newoptions = array_values(array_filter(
            array_map('trim', $params['options']),
            static fn($text) => $text !== ''
        ));
        if (!empty($newoptions)) {
            if (count($newoptions) < 2) {
                throw new \invalid_parameter_exception('Eine Abstimmung braucht mindestens 2 Optionen.');
            }
            if (count($newoptions) > 6) {
                throw new \invalid_parameter_exception('Eine Abstimmung hat maximal 6 Optionen.');
            }
            $choice->option = [];
            $choice->limit = [];
            $choice->optionid = [];
            foreach ($newoptions as $key => $text) {
                $choice->option[$key] = $text;
                $choice->limit[$key] = 0;
                if (isset($existingoptions[$key])) {
                    $choice->optionid[$key] = $existingoptions[$key]->id;
                }
            }
            for ($key = count($newoptions); $key < count($existingoptions); $key++) {
                $choice->option[$key] = '';
                $choice->limit[$key] = 0;
                $choice->optionid[$key] = $existingoptions[$key]->id;
            }
        } else {
            foreach ($existingoptions as $key => $opt) {
                $choice->option[$key] = $opt->text;
                $choice->limit[$key] = (int) $opt->maxanswers;
                $choice->optionid[$key] = $opt->id;
            }
        }

        choice_update_instance($choice);

        if ($params['visible'] >= 0) {
            $DB->set_field('course_modules', 'visible', $params['visible'], ['id' => $cm->id]);
        }

        rebuild_course_cache($cm->course, true);

        $storedoptions = $DB->get_records('choice_options', ['choiceid' => $choice->id], 'id ASC', 'text');
        $stored = $DB->get_record('choice', ['id' => $choice->id], 'timeopen, timeclose', MUST_EXIST);

        return [
            'cmid'    => $params['cmid'],
            'name'    => $choice->name,
            'options' => array_values(array_map(static fn($opt) => (string) $opt->text, $storedoptions)),
            'timeopen' => (int) $stored->timeopen,
            'timeclose' => (int) $stored->timeclose,
            'message' => 'Choice updated successfully.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'name'    => new external_value(PARAM_TEXT, 'Current choice title'),
            'options' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Option text'),
                'Current choice options'
            ),
            'timeopen' => new external_value(PARAM_INT, 'Current opening timestamp (0 = no limit)'),
            'timeclose' => new external_value(PARAM_INT, 'Current closing timestamp (0 = no limit)'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
