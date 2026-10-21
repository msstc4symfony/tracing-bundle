<?php

declare(strict_types=1);

// Answers NTLM Type-1 with a 401 and a Type-2 challenge and Type-3 with a 200, on one keep-alive connection:
// PHP's built-in server closes the connection after the challenge, which ends any real handshake.
$type2 = 'TlRMTVNTUAACAAAADAAMADAAAAA1goriESIzRFVmd4gAAAAAAAAAAGIAYgA8AAAARABPAE0AQQBJAE4AAgAMAEQATwBNAEEASQBOAAEADABTAEUAUgBWAEUAUgAEABQAZABvAG0AYQBpAG4ALgBjAG8AbQADACIAcwBlAHIAdgBlAHIALgBkAG8AbQBhAGkAbgAuAGMAbwBtAAAAAAA=';
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
if ($server === false) {
    exit(1);
}

echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";

while ($connection = @stream_socket_accept($server, 30)) {
    $baggageSeen = [];
    while (!feof($connection)) {
        $head = '';
        while (($line = fgets($connection)) !== false) {
            $head .= $line;
            if ($line === "\r\n") {
                break;
            }
        }

        if ($head === '') {
            break;
        }

        $authorization = preg_match('/^Authorization: NTLM (\S+)/mi', $head, $match) === 1 ? $match[1] : '';
        [$status, $challenge, $body] = match (true) {
            str_starts_with($authorization, 'TlRMTVNTUAAD') => ['200 OK', '', 'ok'],
            str_starts_with($authorization, 'TlRMTVNTUAAB') => ['401 Unauthorized', 'WWW-Authenticate: NTLM ' . $type2 . "\r\n", 'challenge'],
            default => ['401 Unauthorized', "WWW-Authenticate: NTLM\r\n", 'login'],
        };
        if (preg_match('/^baggage: (.*)\r$/mi', $head, $match) === 1) {
            $baggageSeen[] = $match[1];
        }

        $seen = implode(',', $baggageSeen);
        @fwrite($connection, "HTTP/1.1 {$status}\r\n{$challenge}X-Baggage-Seen: {$seen}\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
    }

    fclose($connection);
}
