<?php
// PROTOTYPE — throwaway. Answers: can AI-built activity backup XML be restored? (see README.md)
// Run inside the spike container: php proto.php <cmd> [...]
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

\core\session\manager::set_user(get_admin());
$cmd = $argv[1] ?? '';
const COURSE_SHORT = 'PROTOTYPE-MBZ';

function proto_course() {
    global $DB;
    if ($c = $DB->get_record('course', ['shortname' => COURSE_SHORT])) {
        return $c;
    }
    return create_course((object)['fullname' => 'PROTOTYPE MBZ (wipe me)', 'shortname' => COURSE_SHORT,
        'category' => 1, 'numsections' => 3]);
}

// Q2: create any module type from its form defaults only.
function proto_create_default($course, $modname, $section) {
    global $CFG;
    [$module, $context, $cw, $cm, $data] = prepare_new_moduleinfo_data($course, $modname, $section);
    require_once($CFG->dirroot . "/mod/$modname/mod_form.php");
    $class = "mod_{$modname}_mod_form";
    $mform = new $class($data, $cw->section, $cm, $course);
    $values = (object) (fn() => $this->_form->_defaultValues)->call($mform);
    foreach ((array)$data as $k => $v) {
        if (!isset($values->$k)) { $values->$k = $v; }
    }
    $values->name = "PROTO default $modname";
    $values->modulename = $modname;
    $values->module = $module->id;
    $values->section = $section;
    $values->course = $course->id;
    $values->coursemodule = 0;
    $values->instance = 0;
    $values->add = $modname;
    $values->visible = 1;
    if (!isset($values->introeditor)) {
        $values->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
    }
    // Editors without a default are absent from _defaultValues; fill empty generically.
    foreach ((fn() => $this->_form->_elements)->call($mform) as $el) {
        $n = $el->getName();
        if ($el->getType() === 'editor' && !isset($values->$n)) {
            $values->$n = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        }
    }
    $mform->data_postprocessing($values);
    $info = add_moduleinfo($values, $course, $mform);
    return $info->coursemodule;
}

function proto_export($cmid, $outdir) {
    global $CFG;
    $bc = new backup_controller(backup::TYPE_1ACTIVITY, $cmid, backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO, backup::MODE_IMPORT, get_admin()->id);
    // MODE_IMPORT locks users=0 itself.
    $bc->execute_plan();
    $id = $bc->get_backupid();
    $bc->destroy();
    $src = "$CFG->tempdir/backup/$id";
    @mkdir($outdir, 0777, true);
    exec('cp -r ' . escapeshellarg($src) . '/. ' . escapeshellarg($outdir));
    fulldelete($src);
    echo "exported cm $cmid -> $outdir\n";
}

function proto_restore($dir, $courseid) {
    global $CFG, $DB;
    $id = 'proto' . random_string(10);
    $dst = "$CFG->tempdir/backup/$id";
    mkdir($dst, 0777, true);
    exec('cp -r ' . escapeshellarg($dir) . '/. ' . escapeshellarg($dst));
    $before = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]);
    $rc = new restore_controller($id, $courseid, backup::INTERACTIVE_NO, backup::MODE_IMPORT,
        get_admin()->id, backup::TARGET_CURRENT_ADDING);
    if (!$rc->execute_precheck()) {
        echo "PRECHECK FAILED\n";
        print_r($rc->get_precheck_results());
        $rc->destroy();
        return;
    }
    $prec = $rc->get_precheck_results();
    if ($prec) { echo "precheck warnings: " . json_encode($prec) . "\n"; }
    $rc->execute_plan();
    $rc->destroy();
    $after = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]);
    $new = array_values(array_diff($after, $before));
    foreach ($new as $cmid) {
        $cm = get_coursemodule_from_id('', $cmid);
        echo "RESTORED cm $cmid modname=$cm->modname name=\"$cm->name\" section=$cm->sectionnum\n";
    }
    if (!$new) { echo "NO NEW CM\n"; }
}

// Build a full backup dir around ONE AI-authored <mod>.xml; everything else is generated skeleton.
function proto_synth($modname, $activityxml, $outdir, $sectionnumber = 1) {
    $cmid = 900001;
    $dir = "activities/{$modname}_{$cmid}";
    $hdr = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $files = [
        'files.xml' => '<files></files>', 'roles.xml' => '<roles_definition></roles_definition>',
        'completion.xml' => '<course_completion></course_completion>', 'scales.xml' => '<scales_definition></scales_definition>',
        'outcomes.xml' => '<outcomes_definition></outcomes_definition>', 'questions.xml' => '<question_categories></question_categories>',
        'groups.xml' => '<groups><groupcustomfields></groupcustomfields><groupings><groupingcustomfields></groupingcustomfields></groupings></groups>',
        "$dir/calendar.xml" => '<events></events>', "$dir/inforef.xml" => '<inforef></inforef>',
        "$dir/filters.xml" => '<filters><filter_actives></filter_actives><filter_configs></filter_configs></filters>',
        "$dir/roles.xml" => '<roles><role_overrides></role_overrides><role_assignments></role_assignments></roles>',
        "$dir/grades.xml" => '<activity_gradebook><grade_items></grade_items><grade_letters></grade_letters></activity_gradebook>',
        "$dir/grade_history.xml" => '<grade_history><grade_grades></grade_grades></grade_history>',
        "$dir/competencies.xml" => '<course_module_competencies><competencies></competencies></course_module_competencies>',
        "$dir/module.xml" => "<module id=\"$cmid\" version=\"2025041400\"><modulename>$modname</modulename>"
            . "<sectionid>1</sectionid><sectionnumber>$sectionnumber</sectionnumber><idnumber>\$@NULL@\$</idnumber>"
            . "<added>" . time() . "</added><score>0</score><indent>0</indent><visible>1</visible>"
            . "<visibleoncoursepage>1</visibleoncoursepage><visibleold>1</visibleold><groupmode>0</groupmode>"
            . "<groupingid>0</groupingid><completion>0</completion><completiongradeitemnumber>\$@NULL@\$</completiongradeitemnumber>"
            . "<completionpassgrade>0</completionpassgrade><completionview>0</completionview><completionexpected>0</completionexpected>"
            . "<availability>\$@NULL@\$</availability><showdescription>0</showdescription><downloadcontent>1</downloadcontent>"
            . "<lang>\$@NULL@\$</lang><tags></tags></module>",
    ];
    $settings = '';
    foreach (['users' => 0, 'activities' => 1, 'files' => 1, 'filters' => 1, 'calendarevents' => 1, 'groups' => 1,
              'competencies' => 1, 'customfield' => 1, 'contentbankcontent' => 1] as $n => $v) {
        $settings .= "<setting><level>root</level><name>$n</name><value>$v</value></setting>";
    }
    $settings .= "<setting><level>activity</level><activity>{$modname}_{$cmid}</activity><name>{$modname}_{$cmid}_included</name><value>1</value></setting>"
        . "<setting><level>activity</level><activity>{$modname}_{$cmid}</activity><name>{$modname}_{$cmid}_userinfo</name><value>0</value></setting>";
    $files['moodle_backup.xml'] = '<moodle_backup><information><name>synth.mbz</name><moodle_version>2025041408</moodle_version>'
        . '<moodle_release>5.0</moodle_release><backup_version>2025041400</backup_version><backup_release>5.0</backup_release>'
        . '<backup_date>' . time() . '</backup_date><mnet_remoteusers>0</mnet_remoteusers><include_files>0</include_files>'
        . '<include_file_references_to_external_content>0</include_file_references_to_external_content>'
        . '<original_wwwroot>https://synthetic.invalid</original_wwwroot><original_site_identifier_hash>synthetic</original_site_identifier_hash>'
        . '<original_course_id>1</original_course_id><original_course_format>topics</original_course_format>'
        . '<original_course_fullname>s</original_course_fullname><original_course_shortname>s</original_course_shortname>'
        . '<original_course_startdate>0</original_course_startdate><original_course_enddate>0</original_course_enddate>'
        . '<original_course_contextid>1</original_course_contextid><original_system_contextid>1</original_system_contextid>'
        . '<details><detail backup_id="synthetic"><type>activity</type><format>moodle2</format><interactive></interactive>'
        . '<mode>20</mode><execution>1</execution><executiontime>0</executiontime></detail></details>'
        . "<contents><activities><activity><moduleid>$cmid</moduleid><sectionid>1</sectionid><modulename>$modname</modulename>"
        . "<title>synth</title><directory>$dir</directory><insubsection></insubsection></activity></activities></contents>"
        . "<settings>$settings</settings></information></moodle_backup>";
    $files["$dir/$modname.xml"] = preg_replace('/^<\?xml[^>]*>\s*/', '', file_get_contents($activityxml));
    foreach ($files as $p => $xml) {
        @mkdir(dirname("$outdir/$p"), 0777, true);
        file_put_contents("$outdir/$p", $hdr . $xml);
    }
    echo "synth $modname -> $outdir\n";
}

switch ($cmd) {
    case 'synth':
        proto_synth($argv[2], $argv[3], $argv[4]);
        break;
    case 'setup':
        global $DB;
        $c = proto_course();
        echo "course $c->id\n";
        foreach (array_slice($argv, 2) as $m) {
            try {
                echo "$m -> cm " . proto_create_default($c, $m, 1) . "\n";
            } catch (Throwable $e) {
                $DB->force_transaction_rollback();
                echo $e->getTraceAsString(), "\n"; echo "$m FAILED: " . get_class($e) . ' ' . $e->getMessage() . ' ' . ($e->debuginfo ?? '') . "\n";
            }
        }
        break;
    case 'export':
        proto_export((int)$argv[2], $argv[3]);
        break;
    case 'restore':
        try {
            proto_restore($argv[2], (int)($argv[3] ?? proto_course()->id));
        } catch (Throwable $e) {
            echo $e->getTraceAsString(), "\n"; echo "RESTORE FAILED: " . get_class($e) . ' ' . $e->getMessage() . ' ' . ($e->debuginfo ?? '') . "\n";
        }
        break;
    default:
        echo "usage: setup <modname...> | export <cmid> <dir> | restore <dir> [courseid]\n";
}
