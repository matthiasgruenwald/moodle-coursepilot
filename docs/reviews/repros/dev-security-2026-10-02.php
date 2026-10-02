<?php
/**
 * Offline review probes for dev e69f246. Executes the actual plugin methods with
 * minimal Moodle/DB doubles. No Moodle bootstrap, credentials, network or real DB.
 * A successful probe confirms the reported defect; this is NOT a regression suite.
 * Run: php docs/reviews/repros/dev-security-2026-10-02.php
 */
namespace core_external {
    class external_api {
        public static function validate_parameters($schema, array $params): array { return $params; }
        public static function validate_context($context): void {}
    }
    class external_value { public function __construct(...$args) {} }
    class external_function_parameters {
        public function __construct(public array $keys) {}
    }
}
namespace local_coursepilot {
    class course_module_placement {
        public static array $discarded = [];
        public static function discard_failed(int $id): void { self::$discarded[] = $id; }
    }
    class remote_access {
        public static int $checks = 0;
        public static function is_granted(?int $userid = null): bool { self::$checks++; return false; }
    }
    class xml_activity_creator {
        public static array $writes = [];
        public static function create(...$args): array {
            self::$writes[] = $args;
            return ['cmid' => 123, 'presets' => [], 'references' => [], 'successor_cmid' => 0, 'hidden_predecessors' => 0];
        }
    }
}
namespace local_coursepilot\external {
    class update_module_settings {
        public static function material_reference_specs(string $modname): array { return []; }
    }
}
namespace {
    class context_course {
        public static function instance(int $id): object { return (object) ['id' => $id]; }
    }
    class context_module extends context_course {}
    function require_capability(...$args): void {}
    define('MOODLE_INTERNAL', true);
    foreach (['PARAM_INT', 'PARAM_ALPHANUMEXT', 'PARAM_RAW', 'PARAM_BOOL', 'VALUE_DEFAULT'] as $i => $name) {
        define($name, $i);
    }
    function get_config(...$args) { return '1'; }
    function rebuild_course_cache(...$args): void {}
    function random_string(int $length): string { return substr(bin2hex(random_bytes($length)), 0, $length); }
    function check(bool $condition, string $label): void {
        if (!$condition) { throw new \RuntimeException('Probe no longer reproduces: ' . $label); }
        echo "CONFIRMED: $label\n";
    }
    function invoke(string $class, string $method, ...$args) {
        return (new \ReflectionMethod($class, $method))->invoke(null, ...$args);
    }
    final class ReviewDB {
        public array $rows = [];
        public array $courseids = [1, 2, 3]; // Existing, own newly-created, foreign concurrent activity.
        public array $filequeries = [];
        public function insert_record(string $table, object $row, ...$args): int {
            $id = count($this->rows[$table] ?? []) + 1;
            $this->rows[$table][$id] = clone $row;
            $this->rows[$table][$id]->id = $id;
            return $id;
        }
        public function get_record(string $table, array $conditions, ...$args) {
            if ($table === 'user') { return (object) ['id' => $conditions['id'], 'deleted' => 0, 'suspended' => 0]; }
            foreach ($this->rows[$table] ?? [] as $row) {
                $matches = true;
                foreach ($conditions as $key => $value) { $matches = $matches && $row->$key === $value; }
                if ($matches) { return clone $row; }
            }
            return false;
        }
        public function record_exists(string $table, array $conditions): bool {
            return (bool) $this->get_record($table, $conditions);
        }
        public function start_delegated_transaction(): object {
            return new class {
                public function allow_commit(): void {}
                public function rollback(\Throwable $error): void { throw $error; }
            };
        }
        public function execute(string $sql, array $params): void {
            // Exact CAS shape used by rotate_refresh_token; this double does not simulate concurrency.
            foreach ($this->rows['local_coursepilot_oauth_token'] ?? [] as $row) {
                if ($row->refreshtokenhash === $params['value'] && $row->revoked === 0) {
                    $row->refreshtokenhash = $params['claim'];
                    $row->revoked = 1;
                }
            }
        }
        public function get_fieldset_select(...$args): array { return $this->courseids; }
        public function get_records_select(string $table, string $where, array $params): array {
            $this->filequeries[] = [$table, $where, $params];
            return [(object) [
                'pathnamehash' => 'fixture-path', 'contenthash' => 'fixture-content',
                'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
                'itemid' => 99, 'filepath' => '/', 'filename' => 'Student-Example-medical-note.pdf',
                'filesize' => 123, 'mimetype' => 'application/pdf', 'timemodified' => 1,
            ]];
        }
        public function get_records_sql_menu(string $sql, array $params): array {
            return $params[0] === 1 ? [] : [1 => 'Student-Example-medical-note.pdf'];
        }
    }

    $root = dirname(__DIR__, 3) . '/Plugin/src/local_coursepilot';
    $CFG = (object) ['dirroot' => sys_get_temp_dir() . '/coursepilot-review-moodle-stubs'];
    foreach (['course/lib.php', 'backup/util/includes/backup_includes.php', 'backup/util/includes/restore_includes.php'] as $path) {
        @mkdir(dirname($CFG->dirroot . '/' . $path), 0700, true);
        file_put_contents($CFG->dirroot . '/' . $path, '<?php');
    }
    foreach (['activity_backup', 'oauth_lib', 'workbench_ticket', 'history/version_history', 'history/version_writer',
              'external/create_activity_from_xml'] as $classfile) {
        require $root . '/classes/' . $classfile . '.php';
    }

    // F1: raw profile restriction values are exposed by the actual history diff implementation.
    $private = '{"op":"&","c":[{"type":"profile","sf":"email","op":"isequalto","v":"student@example.invalid"}]}';
    $diff = invoke(\local_coursepilot\history\version_history::class, 'diff_fields',
        ['availability' => ''], ['availability' => $private]);
    check(str_contains(json_encode($diff), 'student@example.invalid'), 'F1 history diff exposes raw profile value');
    $DB = new ReviewDB();
    invoke(\local_coursepilot\history\version_writer::class, 'capture_files', 1, 42, 'assign');
    $stored = array_values($DB->rows['local_coursepilot_cm_file'])[0];
    check($stored->component === 'assignsubmission_file', 'F1 history captures submission filename');
    $filediff = invoke(\local_coursepilot\history\version_history::class, 'diff_files', 1, 2);
    check($filediff[0]['filename'] === $stored->filename, 'F1 history diff outputs submission filename');

    // F2: deterministic interleaving: id 3 belongs to another request, absent from the old snapshot.
    invoke(\local_coursepilot\activity_backup::class, 'remove_new_modules', 7, [1]);
    check(\local_coursepilot\course_module_placement::$discarded === [2, 3], 'F2 restore cleanup deletes foreign concurrent activity');

    // F3: Moodle external_api validates schema order, then array_values + positional call_user_func_array.
    $class = \local_coursepilot\external\create_activity_from_xml::class;
    $declared = array_keys($class::execute_parameters()->keys);
    $actual = array_map(static fn($p) => $p->getName(), (new \ReflectionMethod($class, 'execute'))->getParameters());
    check($declared[5] === 'dry_run' && $actual[5] === 'replacescmid' && $declared[6] === 'replaces_cmid'
        && $actual[6] === 'dryrun', 'F3 external parameter order swaps dry_run and replaces_cmid');
    call_user_func_array([$class, 'execute'], [7, 'book', 1, '<activity/>', false, true, 0]);
    check(count(\local_coursepilot\xml_activity_creator::$writes) === 1
        && \local_coursepilot\xml_activity_creator::$writes[0][5] === 1,
        'F3 dry_run=true without replaces_cmid reaches the write path with predecessor 1');

    // F5: attacker rotates first; legitimate reuse does not revoke the attacker-owned successor.
    $DB = new ReviewDB();
    $DB->insert_record('local_coursepilot_oauth_token', (object) [
        'refreshtokenhash' => hash('sha256', 'stolen-refresh'), 'accesstokenhash' => hash('sha256', 'old-access'),
        'clientid' => 'public-client', 'userid' => 7, 'expires' => time() + 3600,
        'refreshexpires' => time() + 86400, 'revoked' => 0,
    ]);
    $successor = \local_coursepilot\oauth_lib::rotate_refresh_token('stolen-refresh', 'public-client');
    $replay = \local_coursepilot\oauth_lib::rotate_refresh_token('stolen-refresh', 'public-client');
    check($successor !== null && $replay === null
        && \local_coursepilot\oauth_lib::authenticate_access_token($successor['access_token']) === 7,
        'F5 successor remains usable after old refresh token replay');

    // F6: actual download validity check accepts an active account/connection despite denied grant.
    $ticket = (object) ['userid' => 7, 'path' => 'worksheet.pdf', 'expires' => time() + 900, 'oauthtokenid' => 2];
    invoke(\local_coursepilot\workbench_ticket::class, 'assert_still_valid', $ticket);
    check(\local_coursepilot\remote_access::$checks === 0, 'F6 ticket validity never checks remote access grant');

    echo "8 observations reproduced. Doubles prove code paths, not live Moodle/DB/network behavior.\n";
}
