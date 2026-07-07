<?php
// Minimal SMTP client — no external dependencies, so it fits a project with no
// composer/vendor setup. Configure via SMTP_* constants in config.local.php.
//
// Supports STARTTLS (port 587, SMTP_SECURE='tls') and implicit TLS (port 465,
// SMTP_SECURE='ssl'). Returns true on success, false on any failure (details
// go to the PHP error log, never to the caller — never leak SMTP internals
// to a user-facing page).

function sendMail($to, $toName, $subject, $bodyHtml) {
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        error_log('sendMail: SMTP_HOST/SMTP_USER/SMTP_PASS not configured');
        return false;
    }

    $host     = SMTP_HOST;
    $port     = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $secure   = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
    $user     = SMTP_USER;
    $pass     = SMTP_PASS;
    $fromMail = defined('SMTP_FROM') ? SMTP_FROM : $user;
    $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Z-Series POS';

    $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log("sendMail: connection failed to $host:$port — $errstr ($errno)");
        return false;
    }
    stream_set_timeout($sock, 15);

    $readResponse = function () use ($sock) {
        $data = '';
        while (($line = fgets($sock, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $writeCommand = function ($cmd) use ($sock) {
        fwrite($sock, $cmd . "\r\n");
    };
    $expectCode = function ($response, $code) {
        return (bool)preg_match('/(^|\n)' . $code . '[ -]/', $response);
    };

    $banner = $readResponse();
    if (!$expectCode($banner, 220)) {
        fclose($sock);
        error_log("sendMail: unexpected banner: $banner");
        return false;
    }

    $localHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $writeCommand('EHLO ' . $localHost);
    $readResponse();

    if ($secure === 'tls') {
        $writeCommand('STARTTLS');
        $tlsResp = $readResponse();
        if (!$expectCode($tlsResp, 220)) {
            fclose($sock);
            error_log("sendMail: STARTTLS rejected: $tlsResp");
            return false;
        }
        if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($sock);
            error_log('sendMail: TLS handshake failed');
            return false;
        }
        $writeCommand('EHLO ' . $localHost);
        $readResponse();
    }

    $writeCommand('AUTH LOGIN');
    $readResponse();
    $writeCommand(base64_encode($user));
    $readResponse();
    $writeCommand(base64_encode($pass));
    $authResp = $readResponse();
    if (!$expectCode($authResp, 235)) {
        fclose($sock);
        error_log("sendMail: authentication failed: $authResp");
        return false;
    }

    $writeCommand('MAIL FROM:<' . $fromMail . '>');
    $mailResp = $readResponse();
    if (!$expectCode($mailResp, 250)) {
        fclose($sock);
        error_log("sendMail: MAIL FROM rejected: $mailResp");
        return false;
    }

    $writeCommand('RCPT TO:<' . $to . '>');
    $rcptResp = $readResponse();
    if (!$expectCode($rcptResp, 250) && !$expectCode($rcptResp, 251)) {
        fclose($sock);
        error_log("sendMail: RCPT TO rejected: $rcptResp");
        return false;
    }

    $writeCommand('DATA');
    $dataResp = $readResponse();
    if (!$expectCode($dataResp, 354)) {
        fclose($sock);
        error_log("sendMail: DATA rejected: $dataResp");
        return false;
    }

    $headers = [
        'From: ' . $fromName . ' <' . $fromMail . '>',
        'To: ' . ($toName !== '' ? $toName . ' <' . $to . '>' : $to),
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Date: ' . date('r'),
    ];
    $headerBlock = implode("\r\n", $headers) . "\r\n\r\n";
    // SMTP dot-stuffing: escape any line that starts with a literal '.'
    $stuffedBody = preg_replace('/(^|\r\n)\./', '$1..', $bodyHtml);

    fwrite($sock, $headerBlock . $stuffedBody . "\r\n.\r\n");
    $sendResp = $readResponse();
    if (!$expectCode($sendResp, 250)) {
        fclose($sock);
        error_log("sendMail: message rejected: $sendResp");
        return false;
    }

    $writeCommand('QUIT');
    fclose($sock);
    return true;
}
