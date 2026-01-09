<?php
require_once __DIR__ . '/utils.php';

function calendar_send_email(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $fromEmail = calendar_config('mail.from_email', 'noreply@example.com');
    $fromName = calendar_config('mail.from_name', 'Australian Goldwing Association');
    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'MIME-Version: 1.0';
    if ($textBody === '') {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $body = $htmlBody;
    } else {
        $boundary = 'GWBOUNDARY' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary=' . $boundary;
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $textBody . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n";
        $body .= "--{$boundary}--";
    }
    return mail($to, $subject, $body, implode("\r\n", $headers));
}
