<?php
// PROTOTYP zu #474 — Wegwerfcode, kein Produktionscode, keine Tests, keine Fehlerbehandlung.
// Schreibprobe gegen zwei echte WebDAV-Server mit dem Core-Client \webdav_client.
//
// Aufruf im Moodle-Container der Spike-Instanz:
//   php prototype_schreibprobe.php matrix   <instanzid> <basispfad> [pause]
//   php prototype_schreibprobe.php drossel  <instanzid> <basispfad>
//   php prototype_schreibprobe.php wurzel   <instanzid>
//   php prototype_schreibprobe.php roh      <instanzid> <basispfad>
//
// Zugangsdaten kommen aus mdl_repository_instance_config der Spike-Instanz
// (Nutzerinstanzen von repository_webdav, siehe #470) — nichts davon im Repo.

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/webdavlib.php');

global $DB;
$modus = $argv[1] ?? 'matrix';
$instanceid = (int)($argv[2] ?? 0);
$base = rtrim($argv[3] ?? '', '/');
$pause = (float)($argv[4] ?? 0.4);

$cfg = [];
foreach ($DB->get_records('repository_instance_config', ['instanceid' => $instanceid]) as $c) {
    $cfg[$c->name] = $c->value;
}
if (empty($cfg['webdav_server'])) {
    die("Instanz $instanceid hat keine WebDAV-Konfiguration.\n");
}

/** Frischer Client auf die Instanz. */
function mkclient(array $cfg, ?string $pass = null, ?int $port = null): webdav_client {
    $c = new webdav_client($cfg['webdav_server'], $cfg['webdav_user'],
        $pass ?? $cfg['webdav_password'], $cfg['webdav_auth'], 'ssl://');
    $c->port = $port ?? 443;
    $c->debug = false;
    return $c;
}

/** Eine Operation messen, Fremdausgaben des Core-Clients einfangen. */
function step(string $name, callable $fn) {
    global $pause;
    ob_start();
    $t = microtime(true);
    try {
        $r = $fn();
    } catch (\Throwable $e) {
        $r = 'EXCEPTION: ' . $e->getMessage();
    }
    $ms = round((microtime(true) - $t) * 1000);
    $stray = trim(ob_get_clean());
    $val = is_scalar($r) || $r === null
        ? var_export($r, true)
        : json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($val) > 300) {
        $val = substr($val, 0, 300) . '…';
    }
    printf("%-46s %6d ms  %s%s\n", $name, $ms, $val, $stray === '' ? '' : '   STRAY: ' . substr($stray, 0, 100));
    if ($pause) {
        usleep((int)($pause * 1e6));
    }
    return $r;
}

/** Roher HTTP/1.1-Request auf einem offenen Socket, um Rahmenwerk-Header zu sehen. */
function rawreq($sock, string $host, string $auth, string $method, string $path, string $body = ''): void {
    $h = "$method $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: prototyp474\r\n"
       . "Authorization: Basic $auth\r\nContent-Length: " . strlen($body) . "\r\n\r\n";
    fwrite($sock, $h . $body);
    $hdr = '';
    while (!feof($sock)) {
        $hdr .= fgets($sock, 8192);
        if (str_ends_with($hdr, "\r\n\r\n")) {
            break;
        }
    }
    if (preg_match('/Transfer-Encoding:\s*chunked/i', $hdr)) {
        $enc = 'chunked';
        while (($line = fgets($sock, 8192)) !== false) {
            $len = (int)hexdec(trim($line));
            if ($len === 0) { fgets($sock, 8192); break; }
            $read = 0;
            while ($read < $len) { $read += strlen(fread($sock, $len - $read)); }
            fgets($sock, 8192);
        }
    } else if (preg_match('/Content-Length:\s*(\d+)/i', $hdr, $m)) {
        $n = (int)$m[1];
        $enc = "content-length $n";
        $got = 0;
        while ($got < $n) { $got += strlen(fread($sock, $n - $got)); }
    } else {
        $enc = 'WEDER Content-Length NOCH chunked';
    }
    printf("%-8s %-52s %-24s [%s]\n", $method, $path, strtok($hdr, "\r\n"), $enc);
}

/** Curl-Salve, um Drosselung sichtbar zu machen. */
function burst(string $url, string $auth, int $n, float $delay): array {
    $codes = [];
    for ($i = 0; $i < $n; $i++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_HTTPHEADER => ['Depth: 0', 'Authorization: Basic ' . $auth],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($delay) {
            usleep((int)($delay * 1e6));
        }
    }
    return $codes;
}

$auth = base64_encode($cfg['webdav_user'] . ':' . $cfg['webdav_password']);
echo "=== {$cfg['webdav_server']}, Basis {$base}, Modus {$modus} ===\n";

if ($modus === 'matrix') {
    $c = mkclient($cfg);
    step('open()', fn() => $c->open());
    $root = $base . '/kurspilot-probe-474';
    step('mkcol Basisordner', fn() => $c->mkcol($root . '/'));

    // Mehrere Ebenen auf einmal — traegt mkcol das?
    step('mkcol drei Ebenen auf einmal (a/b/c)', fn() => $c->mkcol($root . '/a/b/c/'));
    step('mkcol Ebene a', fn() => $c->mkcol($root . '/a/'));
    step('mkcol Ebene a/b', fn() => $c->mkcol($root . '/a/b/'));
    step('mkcol Ebene a/b/c', fn() => $c->mkcol($root . '/a/b/c/'));

    $ordner = $root . '/Lernsituation Wärmelehre';
    step('mkcol Ordner mit Umlaut und Leerzeichen', fn() => $c->mkcol($ordner . '/'));

    // Schreiben, lesen, ueberschreiben.
    $datei = $ordner . '/Notizen über Wärme.md';
    $eins = "# Wärmelehre\nStand 1\n";
    $zwei = "# Wärmelehre\nStand 2 — überschrieben\n";
    step('put Textdatei (Umlaut+Leerzeichen)', fn() => $c->put($datei, $eins));
    step('get zurücklesen', function () use ($c, $datei, $eins) {
        $buf = ''; $c->get($datei, $buf);
        return $buf === $eins ? 'identisch' : 'ABWEICHUNG (' . strlen($buf) . ' Bytes)';
    });
    step('put überschreiben', fn() => $c->put($datei, $zwei));
    step('get nach Überschreiben', function () use ($c, $datei, $zwei) {
        $buf = ''; $c->get($datei, $buf);
        return $buf === $zwei ? 'identisch' : 'ABWEICHUNG (' . strlen($buf) . ' Bytes)';
    });

    // Zwischendatei-Choreografie: unter Temporaernamen schreiben, dann verschieben.
    $tmp = $ordner . '/.kurspilot-tmp-' . uniqid();
    $drei = "# Wärmelehre\nStand 3 — über Zwischendatei\n";
    step('put Zwischendatei', fn() => $c->put($tmp, $drei));
    step('move Zwischendatei -> Zieldatei (overwrite=T)', fn() => $c->move($tmp, $datei, true));
    step('get nach move', function () use ($c, $datei, $drei) {
        $buf = ''; $c->get($datei, $buf);
        return $buf === $drei ? 'identisch' : 'ABWEICHUNG (' . strlen($buf) . ' Bytes)';
    });
    step('is_file Zwischendatei noch da?', fn() => $c->is_file($tmp));

    // Anhaengen als Read-modify-write an einer gewachsenen Journaldatei.
    foreach ([200 * 1024, 1024 * 1024] as $size) {
        $kb = round($size / 1024);
        $journal = $root . "/journal-$kb.jsonl";
        $zeile = str_repeat('x', 200) . "\n";
        $inhalt = str_repeat($zeile, (int)($size / strlen($zeile)));
        step("Journal $kb KB anlegen (put)", fn() => $c->put($journal, $inhalt));
        step("Journal $kb KB RMW: get", function () use ($c, $journal) {
            $buf = ''; $c->get($journal, $buf); return strlen($buf);
        });
        step("Journal $kb KB RMW: put (+2 KB)", fn() => $c->put($journal, $inhalt . str_repeat('y', 2048) . "\n"));
    }

    step('ls Basisordner (Name/Größe/Zeit)', function () use ($c, $root) {
        $list = $c->ls($root . '/');
        if (!is_array($list)) {
            return 'FEHLER: ' . var_export($list, true);
        }
        return array_map(fn($e) => [
            'href' => urldecode($e['href'] ?? ''),
            'size' => $e['getcontentlength'] ?? null,
            'mtime' => $e['lastmodified'] ?? null,
            'type' => $e['resourcetype'] ?? null,
        ], $list);
    });

    // Groessere Materialdatei hoch und wieder herunter.
    $bin = '/tmp/probe474-material.bin';
    if (!file_exists($bin)) {
        $fh = fopen($bin, 'w');
        for ($i = 0; $i < 3 * 1024; $i++) {
            fwrite($fh, random_bytes(1024));
        }
        fclose($fh);
    }
    $hash = hash_file('sha256', $bin);
    $ziel = $ordner . '/Arbeitsblatt Wärme.pdf';
    step('put_file 3 MB Materialdatei', fn() => $c->put_file($ziel, $bin));
    step('get_file 3 MB zurück + Hashvergleich', function () use ($c, $ziel, $hash) {
        $lokal = '/tmp/probe474-back.bin';
        @unlink($lokal);
        $ok = $c->get_file($ziel, $lokal);
        $h2 = file_exists($lokal) ? hash_file('sha256', $lokal) : 'keine Datei';
        return $h2 === $hash ? 'identisch (3 MB)' : 'ABWEICHUNG ok=' . var_export($ok, true);
    });

    // Fehlerbilder.
    step('ls auf nicht vorhandenen Pfad', fn() => $c->ls($root . '/gibtsnicht/'));
    step('get auf nicht vorhandene Datei', function () use ($c, $root) {
        $buf = ''; $r = $c->get($root . '/gibtsnicht.md', $buf);
        return ['ret' => $r, 'bufLen' => strlen($buf)];
    });
    step('is_dir auf nicht vorhandenen Pfad', fn() => $c->is_dir($root . '/gibtsnicht/'));

    step('delete Datei', fn() => $c->delete($datei));
    step('delete Ordner mit Inhalt (rekursiv?)', fn() => $c->delete($root . '/'));
    step('is_dir nach delete', fn() => $c->is_dir($root . '/'));
    $c->close();

    $falsch = mkclient($cfg, 'falsches-passwort-474');
    step('[falsches Passwort] open()', fn() => $falsch->open());
    step('[falsches Passwort] ls Basis', fn() => $falsch->ls($base . '/'));
    step('[falsches Passwort] put', fn() => $falsch->put($base . '/darf-nicht-474.txt', 'x'));
    $falsch->close();

    $tot = mkclient($cfg, null, 4443);
    step('[toter Port 4443] open()', fn() => $tot->open());
    step('[toter Port 4443] ls nach fehlgeschlagenem open', fn() => $tot->ls($base . '/'));

    $kaputt = new webdav_client('webdav.gibtsnicht-474.invalid', 'u', 'p', 'basic', 'ssl://');
    $kaputt->port = 443;
    step('[unbekannter Host] open()', fn() => $kaputt->open());
} else if ($modus === 'drossel') {
    // Nextcloud hinter Cloudflare wirft bei dichten Anfragen eine HTML-404 statt einer DAV-Antwort.
    $url = 'https://' . $cfg['webdav_server'] . $base . '/';
    foreach ([0.0, 0.1, 0.2, 0.4] as $d) {
        sleep(20);
        $codes = burst($url, $auth, 12, $d);
        $gut = count(array_filter($codes, fn($x) => $x == 207));
        echo "Abstand {$d}s: " . implode(' ', $codes) . "  ($gut/12 gut)\n";
    }
    sleep(20);
    echo "\nUnterscheidbarkeit der beiden 404:\n";
    foreach ([['echter Fehlgriff', $base . '/gibtsnicht-474/', false],
              ['gedrosselt', $base . '/', true]] as [$label, $pfad, $vorherSalve]) {
        if ($vorherSalve) {
            burst('https://' . $cfg['webdav_server'] . $base . '/', $auth, 6, 0);
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://' . $cfg['webdav_server'] . $pfad,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_HTTPHEADER => ['Depth: 0', 'Authorization: Basic ' . $auth],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        printf("%-18s HTTP %s  %s  %d Bytes  %s\n", $label,
            curl_getinfo($ch, CURLINFO_HTTP_CODE), curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            strlen($body), substr(preg_replace('/\s+/', ' ', $body), 0, 90));
        curl_close($ch);
        sleep(15);
    }
} else if ($modus === 'wurzel') {
    // Ist die Wurzel schreibbar, und bleibt Geschriebenes ueber eine neue Verbindung sichtbar?
    $c = mkclient($cfg);
    $c->open();
    echo "ls /:\n";
    foreach ((array)$c->ls('/') as $e) {
        echo '   ' . urldecode($e['href'] ?? '?') . '  type=' . ($e['resourcetype'] ?? '-') . "\n";
    }
    step('mkcol in der Wurzel', fn() => $c->mkcol('/kurspilot-wurzelprobe-474/'));
    step('put direkt in die Wurzel', fn() => $c->put('/kurspilot-wurzelprobe-474.txt', 'bleibt das hier?'));
    $c->close();
    sleep(3);
    $neu = mkclient($cfg);
    $neu->open();
    step('neue Verbindung: get der Wurzeldatei', function () use ($neu) {
        $buf = ''; $r = $neu->get('/kurspilot-wurzelprobe-474.txt', $buf);
        return ['ret' => $r, 'inhalt' => $buf];
    });
    echo "neue Verbindung, ls /:\n";
    foreach ((array)$neu->ls('/') as $e) {
        echo '   ' . urldecode($e['href'] ?? '?') . "\n";
    }
    step('aufräumen Datei', fn() => $neu->delete('/kurspilot-wurzelprobe-474.txt'));
    step('aufräumen Ordner', fn() => $neu->delete('/kurspilot-wurzelprobe-474/'));
} else if ($modus === 'roh') {
    // Rohe HTTP/1.1-Sicht: wie rahmt der Server seine Antworten?
    $sock = fsockopen('ssl://' . $cfg['webdav_server'], 443, $errno, $errstr, 10);
    stream_set_timeout($sock, 10);
    $p = $base . '/probe474roh';
    rawreq($sock, $cfg['webdav_server'], $auth, 'MKCOL', $p . '/');
    rawreq($sock, $cfg['webdav_server'], $auth, 'PUT', $p . '/a.txt', 'eins');
    rawreq($sock, $cfg['webdav_server'], $auth, 'PUT', $p . '/a.txt', 'zwei-laenger');
    rawreq($sock, $cfg['webdav_server'], $auth, 'GET', $p . '/a.txt');
    rawreq($sock, $cfg['webdav_server'], $auth, 'DELETE', $p . '/');
    fclose($sock);
} else {
    die("Unbekannter Modus: $modus\n");
}
