<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$functions = [

    'local_coursepilot_upload_assignfile' => [
        'classname'     => 'local_coursepilot\external\upload_assignfile',
        'description'   => 'Uploads a file (Base64) as additional attachment to a mod_assign activity.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_upload_assign_intro_image' => [
        'classname'     => 'local_coursepilot\external\upload_assign_intro_image',
        'description'   => 'Uploads an image (Base64) into the mod_assign intro filearea and embeds it in the assignment description.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_set_completion' => [
        'classname'     => 'local_coursepilot\external\set_completion',
        'description'   => 'Activates activity completion tracking (manual or automatic on submit).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_set_restriction' => [
        'classname'     => 'local_coursepilot\external\set_restriction',
        'description'   => 'Sets access restrictions based on completion of other modules.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_label' => [
        'classname'     => 'local_coursepilot\external\update_label',
        'description'   => 'Updates the HTML content of an existing label (Text- und Medienfeld).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_url' => [
        'classname'     => 'local_coursepilot\external\update_url',
        'description'   => 'Updates name and/or URL of an existing external link (mod_url).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_get_modules' => [
        'classname'     => 'local_coursepilot\external\get_modules',
        'description'   => 'Returns all activities in a course or section with their cmids.',
        'type'          => 'read',
        'ajax'          => false,
    ],

    'local_coursepilot_get_course_catalog' => [
        'classname'     => 'local_coursepilot\external\get_course_catalog',
        'description'   => 'Returns a compact read-only Moodle catalog with planning-relevant content and settings.',
        'type'          => 'read',
        'ajax'          => false,
    ],

    'local_coursepilot_update_page' => [
        'classname'     => 'local_coursepilot\external\update_page',
        'description'   => 'Updates title and/or content of an existing page (mod_page).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_assign' => [
        'classname'     => 'local_coursepilot\external\update_assign',
        'description'   => 'Updates title, description and/or due date of an existing assignment.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_create_url' => [
        'classname'     => 'local_coursepilot\external\create_url',
        'description'   => 'Creates a URL/Link (mod_url) activity in a course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_create_label' => [
        'classname'     => 'local_coursepilot\external\create_label',
        'description'   => 'Creates a Label (mod_label / Text- und Medienfeld) in a course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Create a Page (Textseite) in a course section
    // ----------------------------------------------------------------
    'local_coursepilot_create_page' => [
        'classname'     => 'local_coursepilot\external\create_page',
        'description'   => 'Creates a Page (mod_page) activity in a given course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Create / update a Resource (Datei) in a course section
    // ----------------------------------------------------------------
    'local_coursepilot_create_resource' => [
        'classname'     => 'local_coursepilot\external\create_resource',
        'description'   => 'Creates a File resource (mod_resource) in a given course section and stores a Base64 file as the main file.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_resource' => [
        'classname'     => 'local_coursepilot\external\update_resource',
        'description'   => 'Updates the name and/or replaces the main file of an existing File resource (mod_resource).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Create / update a Folder (Verzeichnis) and upload files into it
    // ----------------------------------------------------------------
    'local_coursepilot_create_folder' => [
        'classname'     => 'local_coursepilot\external\create_folder',
        'description'   => 'Creates a Folder (mod_folder) activity in a given course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_folder' => [
        'classname'     => 'local_coursepilot\external\update_folder',
        'description'   => 'Updates the name and/or visibility of an existing Folder (mod_folder) activity.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_upload_folder_file' => [
        'classname'     => 'local_coursepilot\external\upload_folder_file',
        'description'   => 'Uploads a file (Base64) into the content filearea of a Folder (mod_folder) activity, supporting subdirectories via filepath.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Create / update a Choice (Abstimmung) in a course section
    // ----------------------------------------------------------------
    'local_coursepilot_create_choice' => [
        'classname'     => 'local_coursepilot\external\create_choice',
        'description'   => 'Creates a Choice (mod_choice / Abstimmung) activity with 2-6 options in a given course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_choice' => [
        'classname'     => 'local_coursepilot\external\update_choice',
        'description'   => 'Updates the name, description and/or options of an existing Choice (mod_choice / Abstimmung) activity.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Create / update a Forum in a course section
    // ----------------------------------------------------------------
    'local_coursepilot_create_forum' => [
        'classname'     => 'local_coursepilot\external\create_forum',
        'description'   => 'Creates a Forum (mod_forum) activity with a selectable type (general, qanda, eachuser, single) in a given course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_forum' => [
        'classname'     => 'local_coursepilot\external\update_forum',
        'description'   => 'Updates the name, description and/or type of an existing Forum (mod_forum) activity.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Create an Assignment (Aufgabe) in a course section
    // ----------------------------------------------------------------
    'local_coursepilot_create_assign' => [
        'classname'     => 'local_coursepilot\external\create_assign',
        'description'   => 'Creates an Assignment (mod_assign) activity in a given course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Update section name and summary
    // ----------------------------------------------------------------
    'local_coursepilot_update_section' => [
        'classname'     => 'local_coursepilot\external\update_section',
        'description'   => 'Updates the name and summary of a course section.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:update',
    ],

    // ----------------------------------------------------------------
    // Ensure a section exists and optionally update it
    // ----------------------------------------------------------------
    'local_coursepilot_ensure_section' => [
        'classname'     => 'local_coursepilot\external\ensure_section',
        'description'   => 'Creates a missing course section if needed and optionally updates name, summary and visibility.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:update',
    ],

    'local_coursepilot_move_section' => [
        'classname'     => 'local_coursepilot\external\move_section',
        'description'   => 'Moves an existing course section to a new position without changing its content.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:update',
    ],

    'local_coursepilot_move_module' => [
        'classname'     => 'local_coursepilot\external\move_module',
        'description'   => 'Moves an existing course module before/after another activity or to a section end without changing its content.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // Clones a single activity into another course (Spec 0013 KP-010,
    // cross-course path of moodle_clone_activity): no core webservice
    // covers this case, so this local adapter drives backup_controller/
    // restore_controller directly. Capabilities are checked explicitly in
    // the handler (source-course backup + target-course restore/manage),
    // the 'capabilities' entry below only documents that.
    'local_coursepilot_clone_activity_to_course' => [
        'classname'     => 'local_coursepilot\external\clone_activity_to_course',
        'description'   => 'Clones a single activity into another course via backup/restore (Spec 0013 KP-010, cross-course path). Requires moodle/backup:backuptargetimport in the source course and moodle/restore:restoretargetimport plus moodle/course:manageactivities in the target course.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/backup:backuptargetimport, moodle/restore:restoretargetimport, moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Get course sections (read existing structure)
    // ----------------------------------------------------------------
    'local_coursepilot_get_sections' => [
        'classname'     => 'local_coursepilot\external\get_sections',
        'description'   => 'Returns all sections of a course with their ids and names.',
        'type'          => 'read',
        'ajax'          => false,
    ],

    // ----------------------------------------------------------------
    // Create a Quiz (mode: mini-check | lernstandscheck | abschlusstest) in a course section
    // ----------------------------------------------------------------
    'local_coursepilot_create_quiz' => [
        'classname'     => 'local_coursepilot\external\create_quiz',
        'description'   => 'Creates a Quiz (mod_quiz) activity with a Kurspilot mode-specific settings combination: mini-check, lernstandscheck (default) or abschlusstest.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_update_quiz_settings' => [
        'classname'     => 'local_coursepilot\external\update_quiz_settings',
        'description'   => 'Updates an existing Quiz (mod_quiz) activity to a Kurspilot mode-specific settings combination.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    // ----------------------------------------------------------------
    // Named question banks + question bank categories
    // ----------------------------------------------------------------
    'local_coursepilot_ensure_question_bank' => [
        'classname'     => 'local_coursepilot\external\ensure_question_bank',
        'description'   => 'Creates or reuses a named standard question bank activity in the course (idempotent: returns the existing bank if the same course/name pair already exists).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_create_question_category' => [
        'classname'     => 'local_coursepilot\external\create_question_category',
        'description'   => 'Creates a question bank category in the selected named question bank (idempotent: returns existing id if a category with the same name already exists under the same parent).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:managecategory',
    ],

    'local_coursepilot_update_question_category' => [
        'classname'     => 'local_coursepilot\external\update_question_category',
        'description'   => 'Renames a question category and/or moves it to a new parent in the selected named question bank without deleting the category, its child categories or its questions.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:managecategory',
    ],

    'local_coursepilot_get_question_categories' => [
        'classname'     => 'local_coursepilot\external\get_question_categories',
        'description'   => 'Returns all question bank categories of the selected named question bank, including the top category.',
        'type'          => 'read',
        'ajax'          => false,
    ],

    // ----------------------------------------------------------------
    // Multiple-Choice-Fragen mit nativer Moodle-Versionierung (#9, ADR-0001)
    // ----------------------------------------------------------------
    'local_coursepilot_create_mc_question' => [
        'classname'     => 'local_coursepilot\external\create_mc_question',
        'description'   => 'Creates a Multiple-Choice question (qtype_multichoice) in a question bank category. V1 rules: single correct answer, variable options, shuffleanswers, no partial credit.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:add',
    ],

    'local_coursepilot_update_mc_question' => [
        'classname'     => 'local_coursepilot\external\update_mc_question',
        'description'   => 'Updates a Multiple-Choice question as a NEW Moodle question version (same questionbankentryid). Old version stays valid for existing quiz attempts (ADR-0001).',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:add',
    ],

    'local_coursepilot_move_question' => [
        'classname'     => 'local_coursepilot\external\move_question',
        'description'   => 'Moves a question-bank entry including every version to a target question category through Moodle bulk move handling.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:add',
    ],

    'local_coursepilot_get_question' => [
        'classname'     => 'local_coursepilot\external\get_question',
        'description'   => 'Returns the latest version of a question in a category, identified by name or questionid. Used to look up a question before editing.',
        'type'          => 'read',
        'ajax'          => false,
    ],

    'local_coursepilot_upload_question_image' => [
        'classname'     => 'local_coursepilot\external\upload_question_image',
        'description'   => 'Uploads an image (Base64) into the questiontext or answerfeedback filearea of a question and returns an @@PLUGINFILE@@ snippet.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:add',
    ],

    // ----------------------------------------------------------------
    // Versionstreuer XML-Frageimport (Issue #327, KP-011, ADR-0001-Nachtrag)
    // ----------------------------------------------------------------
    'local_coursepilot_import_questions_xml' => [
        'classname'     => 'local_coursepilot\external\import_questions_xml',
        'description'   => 'Imports Moodle-XML question content via qformat_xml + question_type::save_question(). ' .
            'Reimport of a question matched by idnumber creates a new version of the same question bank entry ' .
            'instead of a duplicate (ADR-0001). Ambiguous matches are reported without writing until allownew=true.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/question:add',
    ],

    // ----------------------------------------------------------------
    // Add question bank questions to a quiz (#13, ADR-0001: latest version)
    // ----------------------------------------------------------------
    'local_coursepilot_add_questions_to_quiz' => [
        'classname'     => 'local_coursepilot\external\add_questions_to_quiz',
        'description'   => 'Adds question bank questions to a quiz as references to the latest version (version=null), preserving the given order. Duplicates (same questionbankentryid) are skipped.',
        'type'          => 'write',
        'ajax'          => false,
        'capabilities'  => 'moodle/course:manageactivities',
    ],

    'local_coursepilot_get_quiz_cleanup_plan' => [
        'classname'     => 'local_coursepilot\external\get_quiz_cleanup_plan',
        'description'   => 'Returns a non-destructive manual cleanup plan for obsolete quiz slots, including a direct quiz edit URL.',
        'type'          => 'read',
        'ajax'          => false,
    ],

    'local_coursepilot_get_question_category_cleanup_plan' => [
        'classname'     => 'local_coursepilot\external\get_question_category_cleanup_plan',
        'description'   => 'Returns a non-destructive manual cleanup plan for empty, leaf question categories, including a direct question bank edit URL.',
        'type'          => 'read',
        'ajax'          => false,
    ],
];

$services = [
    'Coursepilot' => [
        'functions'       => [
            'local_coursepilot_upload_assignfile',
            'local_coursepilot_upload_assign_intro_image',
            'local_coursepilot_set_completion',
            'local_coursepilot_set_restriction',
            'local_coursepilot_update_label',
            'local_coursepilot_update_url',
            'local_coursepilot_get_modules',
            'local_coursepilot_get_course_catalog',
            'local_coursepilot_update_page',
            'local_coursepilot_update_assign',
            'local_coursepilot_create_url',
            'local_coursepilot_create_label',
            'local_coursepilot_create_page',
            'local_coursepilot_create_resource',
            'local_coursepilot_update_resource',
            'local_coursepilot_create_folder',
            'local_coursepilot_update_folder',
            'local_coursepilot_upload_folder_file',
            'local_coursepilot_create_choice',
            'local_coursepilot_update_choice',
            'local_coursepilot_create_forum',
            'local_coursepilot_update_forum',
            'local_coursepilot_create_assign',
            'local_coursepilot_update_section',
            'local_coursepilot_ensure_section',
            'local_coursepilot_move_section',
            'local_coursepilot_move_module',
            'local_coursepilot_get_sections',
            'local_coursepilot_create_quiz',
            'local_coursepilot_update_quiz_settings',
            'local_coursepilot_ensure_question_bank',
            'local_coursepilot_create_question_category',
            'local_coursepilot_update_question_category',
            'local_coursepilot_get_question_categories',
            'local_coursepilot_create_mc_question',
            'local_coursepilot_update_mc_question',
            'local_coursepilot_move_question',
            'local_coursepilot_get_question',
            'local_coursepilot_upload_question_image',
            'local_coursepilot_import_questions_xml',
            'local_coursepilot_add_questions_to_quiz',
            'local_coursepilot_get_quiz_cleanup_plan',
            'local_coursepilot_get_question_category_cleanup_plan',
            // Moodle-Core-Kursseitenaktionen (Sichtbarkeit, Gruppenmodus):
            // beim Plugin-Upgrade direkt dem bestehenden Coursepilot-Dienst
            // zugeordnet; das vorhandene Token bleibt damit gültig.
            'core_courseformat_update_course',
            // Core-Funktion (lesend): von Integrationstests genutzt, um die
            // tatsaechlich gespeicherten Quiz-Settings nach create_quiz zu
            // verifizieren (#11, quiz-modes.integration.test.js).
            'mod_quiz_get_quizzes_by_courses',
            // Core-Funktionen fuer moodle_clone_activity (#328, Spec 0013
            // KP-010): cmid -> Kurs/Abschnitt/Completion/Voraussetzungen
            // lesen (get_course_module) bzw. Titel generisch setzen
            // (update_inplace_editable, itemtype=activityname) fuer
            // Aktivitaetstypen ohne eigenes Kurspilot-Update-Tool.
            'core_course_get_course_module',
            'core_update_inplace_editable',
            // Lokaler Adapter fuer moodle_clone_activity (#329, Spec 0013
            // KP-010, kursuebergreifender Pfad): kein Core-Webservice deckt
            // das Klonen einer Aktivitaet in einen anderen Kurs ab.
            'local_coursepilot_clone_activity_to_course',
        ],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'coursepilot',
    ],
];
