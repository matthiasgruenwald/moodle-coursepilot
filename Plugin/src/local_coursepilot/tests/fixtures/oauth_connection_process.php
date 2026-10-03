<?php
// Synthetic process for native public OAuth boundary concurrency regressions.
require_once(__DIR__ . '/phpunit_process_bootstrap.php');
$result = $argv[1] !== 'revoke'
    ? \local_coursepilot\oauth_lib::rotate_refresh_token($argv[2], 'client-a')
    : \local_coursepilot\oauth_lib::revoke_token((int) $argv[3]);
echo json_encode($result);
