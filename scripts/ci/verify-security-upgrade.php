<?php
// Synthetic baseline/upgrade acceptance for Spec 0028, #648. Never run on a live site.
// Usage: php verify-security-upgrade.php /path/to/moodle/config.php prepare|verify
define('CLI_SCRIPT', true);
require_once($argv[1]);
if ($CFG->dbname !== 'upgrade648_20261003' || $CFG->dbhost !== 'coursepilot-afk-db') {
    throw new Exception('Only the dedicated isolated AFK upgrade database is allowed.');
}

function check648(bool $condition, string $message): void {
    if (!$condition) {
        throw new Exception($message);
    }
    echo "PASS $message\n";
}

$fixturepath = $CFG->dataroot . '/security-upgrade-fixture.json';
if ($argv[2] === 'prepare') {
    check648((int) get_config('local_coursepilot', 'version') === 2026100201, 'Actual baseline plugin version');
    require_once($CFG->libdir . '/testing/generator/lib.php');
    require_once($CFG->dirroot . '/cohort/lib.php');
    $generator = new testing_data_generator();
    $suffix = bin2hex(random_bytes(6));
    $teacher = $generator->create_user(['username' => 'synthetic648-' . $suffix]);
    $course = $generator->create_course(['shortname' => 'synthetic648-' . $suffix]);
    $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
    $cohortid = cohort_add_cohort((object) ['contextid' => context_system::instance()->id,
        'name' => 'Synthetic remote grant', 'idnumber' => 'afk648-' . $suffix]);
    cohort_add_member($cohortid, $teacher->id);
    set_config('remoteaccesscohorts', (string) $cohortid, 'local_coursepilot');
    core\session\manager::set_user($teacher);
    $page = $generator->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
    $availability = json_encode(['op' => '&', 'showc' => [true], 'c' => [
        ['type' => 'profile', 'sf' => 'email', 'op' => 'isequalto', 'v' => 'synthetic-private@example.invalid'],
    ]]);
    $DB->set_field('course_modules', 'availability', $availability, ['id' => $page->cmid]);
    foreach ([['mod_page', 'intro', 'design.png'], ['assignsubmission_file', 'submission_files', 'student-secret.pdf']]
            as [$component, $area, $name]) {
        get_file_storage()->create_file_from_string([
            'contextid' => context_module::instance($page->cmid)->id, 'component' => $component,
            'filearea' => $area, 'itemid' => 0, 'filepath' => '/', 'filename' => $name,
        ], 'synthetic bytes');
    }
    local_coursepilot\history\version_writer::capture((int) $page->cmid, (int) $teacher->id);
    check648($DB->record_exists('local_coursepilot_cm_file', ['filename' => 'student-secret.pdf']),
        'Baseline really captured the historical disallowed metadata');
    $clientid = local_coursepilot\oauth_lib::handle_registration('POST', [
        'redirect_uris' => ['https://client.example/callback'],
    ])['body']['client_id'];
    $pairs = [];
    for ($i = 0; $i < 2; $i++) {
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = local_coursepilot\oauth_lib::issue_code($clientid, $teacher->id, 'https://client.example/callback', $challenge);
        $pairs[] = local_coursepilot\oauth_lib::exchange_code($code, $clientid, 'https://client.example/callback', $verifier);
    }
    [$directory, $filename] = local_coursepilot\material_files::resolve_file('synthetic.txt');
    get_file_storage()->create_file_from_string([
        'contextid' => local_coursepilot\material_files::own_context()->id,
        'component' => local_coursepilot\material_files::COMPONENT,
        'filearea' => local_coursepilot\material_files::FILEAREA,
        'itemid' => local_coursepilot\material_files::ITEMID, 'filepath' => $directory, 'filename' => $filename,
    ], 'synthetic download bytes');
    local_coursepilot\oauth_lib::authenticate_access_token($pairs[0]['access_token']);
    $tickets = [];
    for ($i = 0; $i < 2; $i++) {
        parse_str(parse_url(local_coursepilot\workbench_ticket::issue('synthetic.txt')['url'], PHP_URL_QUERY), $query);
        $tickets[] = $query['ticket'];
    }
    file_put_contents($fixturepath, json_encode(['userid' => (int) $teacher->id, 'cmid' => (int) $page->cmid,
        'cohortid' => $cohortid, 'clientid' => $clientid, 'pairs' => $pairs, 'tickets' => $tickets,
        'tokenrows' => $DB->get_records('local_coursepilot_oauth_token', ['userid' => $teacher->id], 'id'),
        'ticketrows' => $DB->get_records('local_coursepilot_workbench_ticket', ['userid' => $teacher->id], 'id'),
        'availability' => $availability]));
    echo "Prepared real baseline token pairs, tickets and protected history.\n";
    exit(0);
}

check648($argv[2] === 'verify', 'Known verification mode');
$fixture = json_decode(file_get_contents($fixturepath), true, 512, JSON_THROW_ON_ERROR);
$userid = $fixture['userid'];
core\session\manager::set_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
check648((int) get_config('local_coursepilot', 'version') === 2026100344, 'Final native upgrade savepoint');
$grants = [];
foreach ($fixture['tokenrows'] as $old) {
    $row = $DB->get_record('local_coursepilot_oauth_token', ['id' => $old['id']], '*', MUST_EXIST);
    check648((int) $row->connectionid > 0 && (int) $row->expires === (int) $old['expires'] &&
        (int) $row->refreshexpires === (int) $old['refreshexpires'], 'Token migrated without extending deadlines');
    $grants[] = (int) $row->connectionid;
}
check648(count(array_unique($grants)) === 2, 'Independent same-user/client baseline connections');
foreach ($fixture['ticketrows'] as $old) {
    $row = $DB->get_record('local_coursepilot_workbench_ticket', ['id' => $old['id']], '*', MUST_EXIST);
    check648((int) $row->expires === (int) $old['expires'] && (int) $row->oauthconnectionid > 0,
        'Ticket migrated without extending its deadline');
}
$original = $fixture['pairs'][0];
check648(local_coursepilot\oauth_lib::authenticate_access_token($original['access_token']) === $userid,
    'Migrated access token works');
$rotated = local_coursepilot\oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
check648($rotated !== null, 'Migrated refresh token rotates');
check648(local_coursepilot\workbench_ticket::redeem($fixture['tickets'][0])['content'] === 'synthetic download bytes',
    'Migrated ticket survives normal rotation');
require_once($CFG->dirroot . '/cohort/lib.php');
cohort_remove_member($fixture['cohortid'], $userid);
check648(!local_coursepilot\remote_access::is_granted($userid), 'Remote grant withdrawal after migration');
check648(local_coursepilot\oauth_lib::authenticate_access_token($rotated['access_token']) === $userid,
    'Token remains active while remote grant is withdrawn');
try {
    local_coursepilot\workbench_ticket::redeem($fixture['tickets'][1]);
    throw new Exception('Withdrawal must prevent ticket bytes');
} catch (local_coursepilot\workbench_ticket_redemption_failed $e) {
    check648($e->errorcode === 'remoteaccessnotgranted', 'Migrated ticket denies bytes after withdrawal');
}
cohort_add_member($fixture['cohortid'], $userid);
local_coursepilot\oauth_cleanup::run(time());
check648(local_coursepilot\oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']) === null,
    'Cleanup preserves migrated consumed-refresh replay evidence');
check648(local_coursepilot\oauth_lib::authenticate_access_token($rotated['access_token']) === null,
    'Replay blocks migrated successor');
check648(local_coursepilot\oauth_lib::authenticate_access_token($fixture['pairs'][1]['access_token']) === $userid,
    'Replay leaves other migrated connection active');
$comparison = local_coursepilot\external\compare_activity_versions::execute($fixture['cmid'], 1, 2);
$encoded = json_encode($comparison);
check648(!str_contains($encoded, 'student-secret.pdf') && !str_contains($encoded, 'synthetic-private@example.invalid') &&
    str_contains($encoded, 'design.png'), 'Protected history comparison after real upgrade');
check648(local_coursepilot\history\version_history::state_at($fixture['cmid'], 2)['availabilityconditionsjson'] ===
    $fixture['availability'], 'Native history retains raw restoration conditions');
$contexts = local_coursepilot\privacy\provider::get_contexts_for_userid($userid)->get_contextids();
$context = context_module::instance($fixture['cmid']);
check648(in_array($context->id, $contexts), 'Privacy discovers upgraded history author');
local_coursepilot\privacy\provider::delete_data_for_users(new core_privacy\local\request\approved_userlist(
    $context, 'local_coursepilot', [$userid]));
check648(!$DB->record_exists('local_coursepilot_cm_version', ['cmid' => $fixture['cmid'], 'userid' => $userid]) &&
    $DB->record_exists('course_modules', ['id' => $fixture['cmid']]), 'Privacy removes upgraded history, retains activity');
$dbman = $DB->get_manager();
$errors = $dbman->check_database_schema($dbman->get_install_xml_schema());
check648(array_filter($errors, static fn($name) => str_starts_with($name, 'local_coursepilot'), ARRAY_FILTER_USE_KEY) === [],
    'Upgraded plugin schema matches fresh installation');
foreach (['purge_history', 'oauth_cleanup'] as $task) {
    check648((bool) core\task\manager::get_scheduled_task('local_coursepilot\\task\\' . $task), 'Registered task ' . $task);
}
echo "PASS integrated real baseline upgrade acceptance\n";
