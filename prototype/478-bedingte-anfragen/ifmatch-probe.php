<?php
// Probe for #478: conditional requests (If-Match / If-None-Match) via Moodle \curl
// against the repository_webdav user instances. Prints no credentials.
define('CLI_SCRIPT', true);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'spike.gruenwald.fun';
require('/var/www/html/config.php');
require_once($CFG->libdir . '/filelib.php');

$instances = $DB->get_records_sql(
    "SELECT ri.id, ri.name FROM {repository_instances} ri
       JOIN {repository} r ON r.id = ri.typeid
       JOIN {context} ctx ON ctx.id = ri.contextid
      WHERE r.type = 'webdav' AND ctx.contextlevel = ?", [CONTEXT_USER]);

function opt(int $id, string $name): string {
    global $DB;
    return (string) $DB->get_field('repository_instance_config', 'value', ['instanceid' => $id, 'name' => $name]);
}

function req(string $method, string $url, array $o, array $headers = [], string $body = '', int $auth = CURLAUTH_BASIC): array {
    $c = new curl();
    $c->setHeader(array_merge($headers, ['Content-Type: application/octet-stream']));
    $options = [
        'CURLOPT_CUSTOMREQUEST' => $method,
        'CURLOPT_USERPWD' => $o['user'] . ':' . $o['pass'],
        'CURLOPT_HTTPAUTH' => $auth,
        'CURLOPT_CONNECTTIMEOUT' => 5,
        'CURLOPT_TIMEOUT' => 20,
    ];
    try {
        $resp = $c->post($url, $body, $options);
    } catch (Throwable $e) {
        sleep(1);
        return ['code' => 'EXC', 'ctype' => '', 'etag' => null, 'body' => '', 'err' => substr($e->getMessage(), 0, 90)];
    }
    $info = $c->get_info();
    $hdrs = $c->getResponse();
    $etag = null;
    foreach ($hdrs as $k => $v) {
        if (strtolower($k) === 'etag') {
            $etag = $v;
        }
    }
    sleep(1); // Nextcloud throttling (#474)
    return ['code' => $info['http_code'] ?? 0, 'ctype' => $info['content_type'] ?? '', 'etag' => $etag,
        'body' => $resp, 'err' => $c->get_errno() ? $c->error : ''];
}

function line(string $label, array $r, string $expect): void {
    $ok = in_array((string) $r['code'], explode('|', $expect), true) ? 'OK ' : 'ABW';
    printf("  %s %-44s %s (erwartet %s) %s %s\n", $ok, $label, $r['code'], $expect,
        $r['ctype'] ? "[{$r['ctype']}]" : '', $r['err']);
}

foreach ($instances as $inst) {
    $type = opt($inst->id, 'webdav_type') ? 'https' : 'http';
    $port = opt($inst->id, 'webdav_port');
    $server = opt($inst->id, 'webdav_server');
    $path = rtrim(opt($inst->id, 'webdav_path'), '/');
    $o = ['user' => opt($inst->id, 'webdav_user'), 'pass' => opt($inst->id, 'webdav_password')];
    $base = "$type://$server" . ($port ? ":$port" : '') . $path;
    if (str_contains($server, 'iserv') || str_contains($server, 'igs-roderbruch')) {
        $base .= '/Files';
    }
    $dir = $base . '/kurspilot-probe-478-' . time();
    $f = $dir . '/Journal%20W%C3%A4rme.md';
    echo "== {$inst->name} ($server)\n";
    line('Gegenprobe PROPFIND mit Auth-Aushandlung (ANY)', req('PROPFIND', $base . '/', $o, ['Depth: 0'], '', CURLAUTH_ANY), '207');
    line('PROPFIND mit fester Basic-Anmeldung', req('PROPFIND', $base . '/', $o, ['Depth: 0']), '207');
    // Remove leftovers of the aborted first run.
    $ls = req('PROPFIND', $base . '/', $o, ['Depth: 1']);
    preg_match_all('~<[a-z]*:?href>([^<]*kurspilot-probe-478-[^<]*)</~i', (string) $ls['body'], $mm);
    foreach (array_unique($mm[1]) as $href) {
        $u = parse_url($base);
        line('Altlast entfernt ' . basename(rtrim($href, '/')), req('DELETE', "{$u['scheme']}://{$u['host']}$href", $o), '204|200');
    }

    line('MKCOL Probeordner', req('MKCOL', $dir, $o), '201');
    $r = req('PUT', $f, $o, ['If-None-Match: *'], "v1\n");
    line('PUT neu mit If-None-Match: *', $r, '201|204');
    $etag1 = $r['etag'];
    line('PUT erneut mit If-None-Match: * (muss 412)', req('PUT', $f, $o, ['If-None-Match: *'], "HACK\n"), '412');
    $g = req('GET', $f, $o);
    line('GET liefert ETag: ' . ($g['etag'] ?? '—'), $g, '200');
    echo '      Inhalt nach Schutzversuch: ' . trim($g['body']) . "\n";
    $etag = $g['etag'] ?? $etag1;
    $p = req('PROPFIND', $f, $o, ['Depth: 0'],
        '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getetag/></d:prop></d:propfind>');
    preg_match('~getetag>([^<]*)<~i', (string) $p['body'], $m);
    line('PROPFIND getetag: ' . ($m[1] ?? '—'), $p, '207');
    if ($etag) {
        $r2 = req('PUT', $f, $o, ["If-Match: $etag"], "v2\n");
        line('PUT mit passendem If-Match', $r2, '204|201|200');
        line('PUT mit veraltetem If-Match (muss 412)', req('PUT', $f, $o, ["If-Match: $etag"], "HACK\n"), '412');
        $g2 = req('GET', $f, $o);
        echo '      Inhalt am Ende: ' . trim($g2['body']) . "\n";
    } else {
        echo "  ABW kein ETag erhalten — If-Match nicht pruefbar\n";
    }
    line('PUT If-Match auf fehlende Datei (muss 412)', req('PUT', $dir . '/fehlt.md', $o, ['If-Match: "x"'], "x\n"), '412');
    $n = req('GET', $dir . '/fehlt.md', $o);
    line('GET fehlende Datei', $n, '404');
    line('DELETE Probeordner', req('DELETE', $dir . '/', $o), '204|200');
}
