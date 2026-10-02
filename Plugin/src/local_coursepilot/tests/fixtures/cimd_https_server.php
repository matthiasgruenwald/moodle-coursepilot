<?php
// Synthetic TLS peer for oauth_cimd_fetch_test; never bootstraps Moodle.
if (PHP_SAPI !== 'cli' || count($argv) !== 4) {
    exit(1);
}
$context = stream_context_create(['ssl' => ['local_cert' => $argv[1], 'local_pk' => $argv[2]]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
echo stream_socket_get_name($server, false) . "\n";
flush();
while ($socket = stream_socket_accept($server, 15)) {
    stream_set_timeout($socket, 6);
    if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        fclose($socket);
        continue;
    }
    $request = fgets($socket);
    $headers = '';
    while (($line = fgets($socket)) !== false && trim($line) !== '') {
        $headers .= $line;
    }
    file_put_contents($argv[3], $request . $headers, FILE_APPEND);
    $path = explode(' ', $request)[1];
    $body = json_encode(['client_name' => 'Synthetic TLS client', 'redirect_uris' => ['https://client.example/callback']]);
    if ($path === '/redirect') {
        fwrite($socket, "HTTP/1.1 302 Found\r\nLocation: /valid\r\nContent-Length: 0\r\n\r\n");
    } else if ($path === '/slow') {
        sleep(6);
    } else if ($path === '/chunked' || $path === '/unframed') {
        $chunked = $path === '/chunked';
        fwrite($socket, "HTTP/1.1 200 OK\r\n" . ($chunked ? "Transfer-Encoding: chunked\r\n" : '') . "Connection: close\r\n\r\n");
        // Valid JSON once complete, exceeding the limit only in trailing whitespace.
        $chunks = [$body, str_repeat(' ', 8192)];
        for ($i = 0; $i < 160; $i++) {
            $chunk = $chunks[min($i, 1)];
            if (@fwrite($socket, $chunked ? dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n" : $chunk) === false) {
                break;
            }
        }
        // Keep the response unfinished; the receiver must abort on size, not timeout.
        sleep(6);
    } else {
        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }
    fclose($socket);
}
