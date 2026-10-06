<?php
// Synthetic filesystem boundary: another writer creates book.md after the missing
// preflight but before persistence. Keep the namespace override in this child only.
namespace local_coursepilot {
    function get_file_storage($reset = false) {
        $fs = \get_file_storage($reset);
        if (!$reset) {
            foreach (debug_backtrace() as $frame) {
                if (($frame['class'] ?? '') === private_files_storage_port::class &&
                        ($frame['function'] ?? '') === 'write' &&
                        ($frame['args'][1] ?? '') === 'activity-types/book.md') {
                    $GLOBALS['coursepilot603reads'] = ($GLOBALS['coursepilot603reads'] ?? 0) + 1;
                    if ($GLOBALS['coursepilot603reads'] === 2) {
                        $fs->create_file_from_string(context_files::filerecord(
                            context_files::own_context()->id, '/coursepilot/activity-types/', 'book.md'),
                            "Teacher's concurrent file");
                        $GLOBALS['coursepilot603created'] = true;
                    }
                    break;
                }
            }
        }
        return $fs;
    }
}
namespace {
    require_once(__DIR__ . '/phpunit_process_bootstrap.php');
    \core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[1]], '*', MUST_EXIST));
    $provided = [];
    \local_coursepilot\location_selection::apply([
        'context_area' => ['type' => 'moodle'], 'material_store' => ['type' => 'moodle'],
    ], $provided);
    echo json_encode([
        'injected' => $GLOBALS['coursepilot603created'] ?? false,
        'provided' => $provided,
        'content' => \local_coursepilot\context_area::read('activity-types/book.md')['content'],
    ]);
}
