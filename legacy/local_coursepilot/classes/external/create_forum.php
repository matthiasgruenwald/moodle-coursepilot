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
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;

/**
 * Creates a mod_forum activity in a course section. The forum type is one of
 * general (Standardforum), qanda (Frage-Antwort-Forum), eachuser (jede Person
 * ein Thema) or single (einzelnes einfaches Diskussionsthema). add_moduleinfo()
 * delegates to forum_add_instance(), which inserts the forum row, creates the
 * initial discussion for type 'single' and syncs the grade items.
 */
class create_forum extends external_api {

    private const FORUM_TYPES = ['general', 'qanda', 'eachuser', 'single'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT,  'Course ID'),
            'sectionnum' => new external_value(PARAM_INT,  'Section number (0-based)'),
            'name'       => new external_value(PARAM_TEXT, 'Forum title'),
            'intro'      => new external_value(PARAM_RAW,  'Description shown on the course page (optional)', VALUE_DEFAULT, ''),
            'type'       => new external_value(PARAM_TEXT, 'Forum type: general, qanda, eachuser or single', VALUE_DEFAULT, 'general'),
            'visible'    => new external_value(PARAM_INT,  'Visible (1) or hidden (0)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum, string $name, string $intro = '', string $type = 'general', int $visible = 1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'name'       => $name,
            'intro'      => $intro,
            'type'       => $type,
            'visible'    => $visible,
        ]);

        $forumtype = $params['type'];
        if (!in_array($forumtype, self::FORUM_TYPES, true)) {
            throw new \invalid_parameter_exception(
                'Unzulaessiger Forumtyp "' . $forumtype . '". Erlaubt sind: ' . implode(', ', self::FORUM_TYPES) . '.'
            );
        }

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename      = 'forum';
        $moduleinfo->module          = $DB->get_field('modules', 'id', ['name' => 'forum'], MUST_EXIST);
        $moduleinfo->course          = $params['courseid'];
        $moduleinfo->section         = $params['sectionnum'];
        $moduleinfo->name            = $params['name'];
        $moduleinfo->visible         = $params['visible'];
        $moduleinfo->intro           = $params['intro'];
        $moduleinfo->introformat     = FORMAT_HTML;
        $moduleinfo->type            = $forumtype;
        $moduleinfo->cmidnumber      = '';
        $moduleinfo->forcesubscribe  = 0;
        $moduleinfo->trackingtype    = 1;
        $moduleinfo->maxbytes        = 0;
        $moduleinfo->maxattachments  = 1;
        $moduleinfo->assessed        = 0;
        $moduleinfo->scale           = 0;
        $moduleinfo->grade_forum     = 0;
        $moduleinfo->duedate         = 0;
        $moduleinfo->cutoffdate      = 0;
        $moduleinfo->assesstimestart = 0;
        $moduleinfo->assesstimefinish = 0;
        $moduleinfo->warnafter       = 0;
        $moduleinfo->blockafter      = 0;
        $moduleinfo->blockperiod     = 0;
        $moduleinfo->completiondiscussions = 0;
        $moduleinfo->completionreplies     = 0;
        $moduleinfo->completionposts       = 0;
        $moduleinfo->displaywordcount      = 0;
        $moduleinfo->lockdiscussionafter   = 0;
        $moduleinfo->rsstype        = 0;
        $moduleinfo->rssarticles     = 0;

        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $moduleinfo->coursemodule;

        rebuild_course_cache($course->id, true);

        return [
            'cmid'    => $cmid,
            'name'    => $params['name'],
            'type'    => $forumtype,
            'message' => 'Forum "' . $params['name'] . '" successfully created.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID of the created forum'),
            'name'    => new external_value(PARAM_TEXT, 'Forum title'),
            'type'    => new external_value(PARAM_TEXT, 'Forum type'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
