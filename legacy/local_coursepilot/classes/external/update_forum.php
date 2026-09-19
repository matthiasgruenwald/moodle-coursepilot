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
require_once($CFG->dirroot . '/mod/forum/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;

/**
 * Updates the name, intro and/or type of an existing mod_forum activity. The
 * full forum record is loaded and only the provided fields are overridden, so
 * forum_update_instance() sees every field it reads (assessed, scale,
 * grade_forum, forcesubscribe, ...). cmidnumber and coursemodule come from the
 * course_modules record, because forum_grade_item_update() reads cmidnumber
 * unguarded and it is not part of the forum table.
 */
class update_forum extends external_api {

    private const FORUM_TYPES = ['general', 'qanda', 'eachuser', 'single'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'name'    => new external_value(PARAM_TEXT, 'New forum title', VALUE_DEFAULT, ''),
            'intro'   => new external_value(PARAM_RAW,  'New description (optional)', VALUE_DEFAULT, ''),
            'type'    => new external_value(PARAM_TEXT, 'New forum type: general, qanda, eachuser or single (empty = keep)', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_INT,  '1 = visible, 0 = hidden, -1 = no change', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(int $cmid, string $name = '', string $intro = '', string $type = '', int $visible = -1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'name'    => $name,
            'intro'   => $intro,
            'type'    => $type,
            'visible' => $visible,
        ]);

        $cm = get_coursemodule_from_id('forum', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);

        if ($params['name'] !== '') {
            $forum->name = $params['name'];
        }
        if ($params['intro'] !== '') {
            $forum->intro = $params['intro'];
            $forum->introformat = FORMAT_HTML;
        }
        if ($params['type'] !== '') {
            if (!in_array($params['type'], self::FORUM_TYPES, true)) {
                throw new \invalid_parameter_exception(
                    'Unzulaessiger Forumtyp "' . $params['type'] . '". Erlaubt sind: ' . implode(', ', self::FORUM_TYPES) . '.'
                );
            }
            $forum->type = $params['type'];
        }

        $forum->instance     = $forum->id;
        $forum->coursemodule = $cm->id;
        $forum->cmidnumber   = $cm->idnumber ?? '';

        forum_update_instance($forum, null);

        if ($params['visible'] >= 0) {
            $DB->set_field('course_modules', 'visible', $params['visible'], ['id' => $cm->id]);
        }

        rebuild_course_cache($cm->course, true);

        return [
            'cmid'    => $params['cmid'],
            'name'    => $forum->name,
            'type'    => $forum->type,
            'message' => 'Forum updated successfully.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'name'    => new external_value(PARAM_TEXT, 'Current forum title'),
            'type'    => new external_value(PARAM_TEXT, 'Current forum type'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
