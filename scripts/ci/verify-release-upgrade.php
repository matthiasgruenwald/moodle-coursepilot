<?php
// Synthetic baseline/upgrade acceptance for published 2.0.0-beta to 2.1.0-beta. Never run on a live site.
// Usage: php verify-release-upgrade.php /path/to/moodle/config.php prepare|http|verify
define('CLI_SCRIPT', true);
require_once($argv[1]);
if (!in_array($CFG->dbname, ['release627', 'release627_final', 'release627_final2', 'release627_final3', 'release627_final4'], true) || $CFG->dbhost !== 'coursepilot-627-db') {
    throw new Exception('Only the dedicated isolated release upgrade database is allowed.');
}

function check_release_upgrade(bool $condition, string $message): void {
    if (!$condition) {
        throw new Exception($message);
    }
    echo "PASS $message\n";
}

$fixturepath = $CFG->dataroot . '/release-upgrade-fixture.json';
if ($argv[2] === 'prepare') {
    check_release_upgrade((int) get_config('local_coursepilot', 'version') === 2026100102, 'Actual baseline plugin version');
    require_once($CFG->libdir . '/testing/generator/lib.php');
    require_once($CFG->dirroot . '/cohort/lib.php');
    $generator = new testing_data_generator();
    $suffix = bin2hex(random_bytes(6));
    $teacher = $generator->create_user(['username' => 'release627-' . $suffix,
        'password' => 'SyntheticTeacher627Password']);
    $course = $generator->create_course(['shortname' => 'release627-' . $suffix]);
    $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
    $cohortid = cohort_add_cohort((object) ['contextid' => context_system::instance()->id,
        'name' => 'Synthetic remote grant', 'idnumber' => 'release627-' . $suffix]);
    cohort_add_member($cohortid, $teacher->id);
    set_config('remoteaccesscohorts', (string) $cohortid, 'local_coursepilot');
    core\session\manager::set_user($teacher);
    $fs = get_file_storage();
    $filebase = ['contextid' => context_user::instance($teacher->id)->id, 'component' => 'user',
        'filearea' => 'private', 'itemid' => 0, 'userid' => $teacher->id];
    $pointer = ['kontextbereich' => ['ort' => 'moodle', 'pfad' => 'retained/context'],
        'materialbestand' => ['ort' => 'moodle', 'pfad' => 'retained/material'],
        'ortsverlauf' => [['datum' => '2026-10-01', 'ziel' => 'kontextbereich',
            'von' => 'coursepilot', 'nach' => 'retained/context']]];
    $pending = ['synthetic' => ['zeitpunkt' => time(), 'pfad' => 'retained.md', 'vorgang' => 'anlegen',
        'fehlerklasse' => 'nicht erreichbar', 'kursid' => $course->id]];
    foreach (['.coursepilot-ort.json' => $pointer, '.coursepilot-ausstand.json' => $pending] as $name => $data) {
        $fs->create_file_from_string($filebase + ['filepath' => '/coursepilot/', 'filename' => $name], json_encode($data));
    }
    $fs->create_file_from_string($filebase + ['filepath' => '/retained/context/', 'filename' => 'retained.md'],
        'Synthetic retained teacher context');
    check_release_upgrade(local_coursepilot\context_files::read_content_pointer_aware('retained.md')['content'] ===
        'Synthetic retained teacher context', 'Baseline uses the legacy selected context location');
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
    local_coursepilot\history\version_writer::capture((int) $page->cmid, (int) $teacher->id, 'geklont', (int) $page->cmid);
    local_coursepilot\history\version_writer::capture((int) $page->cmid, (int) $teacher->id, 'vorgefunden');
    check_release_upgrade($DB->record_exists('local_coursepilot_cm_file', ['filename' => 'student-secret.pdf']),
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
    $ticketurls = [];
    for ($i = 0; $i < 3; $i++) {
        $ticket = local_coursepilot\werkbank_ticket::issue('synthetic.txt');
        parse_str(parse_url($ticket['url'], PHP_URL_QUERY), $query);
        $tickets[] = $query['ticket'];
        $ticketurls[] = $ticket['url'];
    }
    file_put_contents($fixturepath, json_encode(['userid' => (int) $teacher->id, 'username' => $teacher->username,
        'cmid' => (int) $page->cmid,
        'cohortid' => $cohortid, 'clientid' => $clientid, 'pairs' => $pairs, 'tickets' => $tickets, 'ticketurls' => $ticketurls,
        'tokenrows' => $DB->get_records('local_coursepilot_oauth_token', ['userid' => $teacher->id], 'id'),
        'ticketrows' => $DB->get_records('local_coursepilot_werkbank_ticket', ['userid' => $teacher->id], 'id'),
        'availability' => $availability]));
    chmod($fixturepath, 0600);
    echo "Prepared real baseline token pairs, tickets and protected history.\n";
    exit(0);
}

$fixture = json_decode(file_get_contents($fixturepath), true, 512, JSON_THROW_ON_ERROR);
if ($argv[2] === 'http') {
    $cookies = tempnam($CFG->tempdir, 'release627-cookies');
    $curl = curl_init();
    $request = static function(string $path, ?array $post = null) use ($CFG, $cookies, $curl): array {
        curl_setopt($curl, CURLOPT_URL, $CFG->wwwroot . $path);
        curl_setopt($curl, CURLOPT_HTTPGET, true);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_TIMEOUT => 15]);
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post, '', '&'));
        }
        $body = curl_exec($curl);
        if ($body === false) {
            throw new Exception('Isolated HTTP request failed: ' . curl_error($curl));
        }
        $result = ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'url' => curl_getinfo($curl, CURLINFO_EFFECTIVE_URL), 'body' => $body];
        return $result;
    };
    try {
        $url = parse_url($fixture['ticketurls'][2], PHP_URL_PATH) . '?' .
            parse_url($fixture['ticketurls'][2], PHP_URL_QUERY);
        $download = $request($url);
        check_release_upgrade($download['status'] === 200 && $download['body'] === 'synthetic download bytes',
            'Published download URL delivers migrated bytes over HTTP');
        $replay = $request($url);
        check_release_upgrade($replay['status'] === 403 && $replay['body'] !== 'synthetic download bytes',
            'Published download URL enforces single use over HTTP');
        $login = $request('/login/index.php');
        check_release_upgrade(preg_match('/name="logintoken" value="([^"]+)"/', $login['body'], $token) === 1,
            'Real login form supplies a login token');
        $request('/login/index.php', ['username' => $fixture['username'],
            'password' => 'SyntheticTeacher627Password', 'logintoken' => $token[1]]);
        $page = $request('/local/coursepilot/ortswahl.php');
        check_release_upgrade($page['status'] === 200 && str_contains($page['url'], '/location_selection.php') &&
            !str_contains($page['url'], '/login/'), 'Published location URL reaches the current form after login');
        $usercontextid = context_user::instance($fixture['userid'])->id;
        $pointer = get_file_storage()->get_file($usercontextid, 'user', 'private', 0,
            '/coursepilot/', '.coursepilot-location.json');
        $before = $pointer->get_content();
        $oldpost = $request('/local/coursepilot/ortswahl.php', ['kontextbereich_type' => 'moodle',
            'kontextbereich_path' => 'wrong', 'materialbestand_type' => 'moodle',
            'materialbestand_path' => 'wrong-material']);
        check_release_upgrade($oldpost['status'] === 200 && str_contains($oldpost['url'], '/location_selection.php') &&
            get_file_storage()->get_file($usercontextid, 'user', 'private', 0,
                '/coursepilot/', '.coursepilot-location.json')->get_content() === $before, 'Old location-form POST changes no selected storage location');
        $browse = $request('/local/coursepilot/ortswahl_browse.php?instanceid=1&path=&sesskey=invalid');
        check_release_upgrade($browse['status'] === 404 && str_contains($browse['body'], get_string('invalidsesskey', 'error')),
            'Published browse URL retains the current sesskey guard');
    } finally {
        curl_close($curl);
        unlink($cookies);
    }
    exit(0);
}
check_release_upgrade($argv[2] === 'verify', 'Known verification mode');
$userid = $fixture['userid'];
core\session\manager::set_user($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST));
check_release_upgrade((int) get_config('local_coursepilot', 'version') === 2026100700, 'Final native upgrade savepoint');
$fs = get_file_storage();
$usercontextid = context_user::instance($userid)->id;
$pointerfile = $fs->get_file($usercontextid, 'user', 'private', 0, '/coursepilot/', '.coursepilot-location.json');
check_release_upgrade($pointerfile !== false && !str_contains($pointerfile->get_content(), 'kontextbereich'),
    'Legacy pointer filename and keys migrated to English');
check_release_upgrade(!$fs->file_exists($usercontextid, 'user', 'private', 0, '/coursepilot/', '.coursepilot-ort.json'),
    'Old pointer replaced after successful translation');
check_release_upgrade(local_coursepilot\storage_anchor::port(local_coursepilot\context_files::area())->read(local_coursepilot\context_files::area(), 'retained.md')['content'] ===
    'Synthetic retained teacher context', 'Selected context location and teacher contents retained');
[$directory, $filename] = local_coursepilot\material_files::resolve_file('synthetic.txt');
check_release_upgrade($directory === '/retained/material/' &&
    local_coursepilot\material_files::read_content($directory, $filename)['content'] === 'synthetic download bytes',
    'Selected material location and bytes retained');
$pendingfile = $fs->get_file($usercontextid, 'user', 'private', 0, '/coursepilot/', '.coursepilot-pending.json');
$pending = json_decode($pendingfile->get_content(), true, 512, JSON_THROW_ON_ERROR);
check_release_upgrade($pending['synthetic']['operation'] === 'create' &&
    $pending['synthetic']['error_class'] === 'unreachable' && $pending['synthetic']['path'] === 'retained.md',
    'Legacy pending note retains path and translates operation/error');
check_release_upgrade($DB->record_exists('local_coursepilot_cm_version', ['cmid' => $fixture['cmid'], 'source' => 'cloned']) &&
    $DB->record_exists('local_coursepilot_cm_version', ['cmid' => $fixture['cmid'], 'source' => 'discovered']) &&
    !$DB->record_exists('local_coursepilot_cm_version', ['cmid' => $fixture['cmid'], 'source' => 'geklont']),
    'Legacy history sources migrated without losing versions');
check_release_upgrade(is_file($CFG->dirroot . '/local/coursepilot/ortswahl.php') &&
    is_file($CFG->dirroot . '/local/coursepilot/ortswahl_browse.php') &&
    is_file($CFG->dirroot . '/local/coursepilot/werkbank/download.php') &&
    str_contains($fixture['ticketurls'][0], '/werkbank/download.php?ticket='), 'Published location and ticket URLs retained');
$grants = [];
foreach ($fixture['tokenrows'] as $old) {
    $row = $DB->get_record('local_coursepilot_oauth_token', ['id' => $old['id']], '*', MUST_EXIST);
    check_release_upgrade((int) $row->connectionid > 0 && (int) $row->expires === (int) $old['expires'] &&
        (int) $row->refreshexpires === (int) $old['refreshexpires'], 'Token migrated without extending deadlines');
    $grants[] = (int) $row->connectionid;
}
check_release_upgrade(count(array_unique($grants)) === 2, 'Independent same-user/client baseline connections');
foreach (array_slice($fixture['ticketrows'], 0, 2) as $old) {
    $row = $DB->get_record('local_coursepilot_workbench_ticket', ['id' => $old['id']], '*', MUST_EXIST);
    check_release_upgrade((int) $row->expires === (int) $old['expires'] && (int) $row->oauthconnectionid > 0,
        'Ticket migrated without extending its deadline');
}
$original = $fixture['pairs'][0];
check_release_upgrade(local_coursepilot\oauth_lib::authenticate_access_token($original['access_token']) === $userid,
    'Migrated access token works');
$rotated = local_coursepilot\oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
check_release_upgrade($rotated !== null, 'Migrated refresh token rotates');
check_release_upgrade(local_coursepilot\workbench_ticket::redeem($fixture['tickets'][0])['content'] === 'synthetic download bytes',
    'Migrated ticket survives normal rotation');
require_once($CFG->dirroot . '/cohort/lib.php');
cohort_remove_member($fixture['cohortid'], $userid);
check_release_upgrade(!local_coursepilot\remote_access::is_granted($userid), 'Remote grant withdrawal after migration');
check_release_upgrade(local_coursepilot\oauth_lib::authenticate_access_token($rotated['access_token']) === $userid,
    'Token remains active while remote grant is withdrawn');
try {
    local_coursepilot\workbench_ticket::redeem($fixture['tickets'][1]);
    throw new Exception('Withdrawal must prevent ticket bytes');
} catch (local_coursepilot\workbench_ticket_redemption_failed $e) {
    check_release_upgrade($e->errorcode === 'remoteaccessnotgranted', 'Migrated ticket denies bytes after withdrawal');
}
cohort_add_member($fixture['cohortid'], $userid);
local_coursepilot\oauth_cleanup::run(time());
check_release_upgrade(local_coursepilot\oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']) === null,
    'Cleanup preserves migrated consumed-refresh replay evidence');
check_release_upgrade(local_coursepilot\oauth_lib::authenticate_access_token($rotated['access_token']) === null,
    'Replay blocks migrated successor');
check_release_upgrade(local_coursepilot\oauth_lib::authenticate_access_token($fixture['pairs'][1]['access_token']) === $userid,
    'Replay leaves other migrated connection active');
$other = $DB->get_record('local_coursepilot_oauth_token', [
    'accesstokenhash' => hash('sha256', $fixture['pairs'][1]['access_token']),
], '*', MUST_EXIST);
check_release_upgrade(local_coursepilot\oauth_lib::revoke_token((int) $other->id, $userid) &&
    local_coursepilot\oauth_lib::authenticate_access_token($fixture['pairs'][1]['access_token']) === null,
    'Explicit revocation works for the other migrated connection');
$comparison = local_coursepilot\external\compare_activity_versions::execute($fixture['cmid'], 1, 2);
$encoded = json_encode($comparison);
check_release_upgrade(!str_contains($encoded, 'student-secret.pdf') && !str_contains($encoded, 'synthetic-private@example.invalid') &&
    str_contains($encoded, 'design.png'), 'Protected history comparison after real upgrade');
check_release_upgrade(local_coursepilot\history\version_history::state_at($fixture['cmid'], 2)['availabilityconditionsjson'] ===
    $fixture['availability'], 'Native history retains raw restoration conditions');
$contexts = local_coursepilot\privacy\provider::get_contexts_for_userid($userid)->get_contextids();
$context = context_module::instance($fixture['cmid']);
check_release_upgrade(in_array($context->id, $contexts), 'Privacy discovers upgraded history author');
local_coursepilot\privacy\provider::delete_data_for_users(new core_privacy\local\request\approved_userlist(
    $context, 'local_coursepilot', [$userid]));
check_release_upgrade(!$DB->record_exists('local_coursepilot_cm_version', ['cmid' => $fixture['cmid'], 'userid' => $userid]) &&
    $DB->record_exists('course_modules', ['id' => $fixture['cmid']]), 'Privacy removes upgraded history, retains activity');
$dbman = $DB->get_manager();
$errors = $dbman->check_database_schema($dbman->get_install_xml_schema());
check_release_upgrade(array_filter($errors, static fn($name) => str_starts_with($name, 'local_coursepilot'), ARRAY_FILTER_USE_KEY) === [],
    'Upgraded plugin schema matches fresh installation');
foreach (['purge_history', 'oauth_cleanup'] as $task) {
    check_release_upgrade((bool) core\task\manager::get_scheduled_task('local_coursepilot\\task\\' . $task), 'Registered task ' . $task);
}
echo "PASS integrated real baseline upgrade acceptance\n";
