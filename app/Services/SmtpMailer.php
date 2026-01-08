<?php
namespace App\Services;

class SmtpMailer
{
    public static function send(
        array $config,
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $html,
        string $replyTo = ''
    ): bool
    {
        $host = $config['host'] ?? '';
        $port = (int) ($config['port'] ?? 587);
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $encryption = $config['encryption'] ?? 'tls';
        if ($host === '') {
            return false;
        }

        $targetHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = fsockopen($targetHost, $port, $errno, $errstr, 15);
        if (!$socket) {
            return false;
        }
        $line = self::read($socket);
        if (!self::isOk($line)) {
            fclose($socket);
            return false;
        }
        self::sendLine($socket, 'EHLO ' . ($config['helo'] ?? 'localhost'));
        $lines = self::readMultiline($socket);
        if ($encryption === 'tls') {
            self::sendLine($socket, 'STARTTLS');
            if (!self::isOk(self::read($socket))) {
                fclose($socket);
                return false;
            }
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            self::sendLine($socket, 'EHLO ' . ($config['helo'] ?? 'localhost'));
            self::readMultiline($socket);
        }
        if ($user !== '') {
            self::sendLine($socket, 'AUTH LOGIN');
            if (!self::isOk(self::read($socket))) {
                fclose($socket);
                return false;
            }
            self::sendLine($socket, base64_encode($user));
            if (!self::isOk(self::read($socket))) {
                fclose($socket);
                return false;
            }
            self::sendLine($socket, base64_encode($pass));
            if (!self::isOk(self::read($socket))) {
                fclose($socket);
                return false;
            }
        }

        $fromHeader = $fromName !== '' ? $fromName . ' <' . $from . '>' : $from;
        $headers = [];
        $headers[] = 'From: ' . $fromHeader;
        $headers[] = 'To: ' . $to;
        $headers[] = 'Subject: ' . $subject;
        if ($replyTo !== '') {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        self::sendLine($socket, 'MAIL FROM:<' . $from . '>');
        if (!self::isOk(self::read($socket))) {
            fclose($socket);
            return false;
        }
        self::sendLine($socket, 'RCPT TO:<' . $to . '>');
        if (!self::isOk(self::read($socket))) {
            fclose($socket);
            return false;
        }
        self::sendLine($socket, 'DATA');
        if (!self::isOk(self::read($socket))) {
            fclose($socket);
            return false;
        }
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $html . "\r\n.";
        self::sendLine($socket, $message);
        if (!self::isOk(self::read($socket))) {
            fclose($socket);
            return false;
        }
        self::sendLine($socket, 'QUIT');
        fclose($socket);
        return true;
    }

    private static function sendLine($socket, string $line): void
    {
        fwrite($socket, $line . "\r\n");
    }

    private static function read($socket): string
    {
        return fgets($socket, 512) ?: '';
    }

    private static function readMultiline($socket): array
    {
        $lines = [];
        while ($line = self::read($socket)) {
            $lines[] = $line;
            if (preg_match('/^\\d{3} /', $line)) {
                break;
            }
        }
        return $lines;
    }

    private static function isOk(string $line): bool
    {
        return preg_match('/^(2|3)\\d{2}/', $line) === 1;
    }
}
