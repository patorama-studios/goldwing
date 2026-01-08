<?php
function calendar_config(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/config.php';
    }
    $parts = explode('.', $key);
    $value = $config;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function calendar_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function calendar_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function calendar_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

function calendar_now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function calendar_base_url(string $path = ''): string
{
    $base = rtrim((string) calendar_config('base_url', ''), '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function calendar_format_dt(string $dt, string $tz): string
{
    $date = new DateTime($dt, new DateTimeZone($tz));
    return $date->format('M j, Y g:i A');
}

function calendar_format_date(string $dt, string $tz): string
{
    $date = new DateTime($dt, new DateTimeZone($tz));
    return $date->format('M j, Y');
}

function calendar_random_code(int $length = 16): string
{
    return bin2hex(random_bytes((int) ceil($length / 2)));
}

function calendar_is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function calendar_render_badge(string $label, string $class = 'badge'): string
{
    return '<span class="' . calendar_e($class) . '">' . calendar_e($label) . '</span>';
}

function calendar_human_scope(?string $scope): string
{
    return $scope === 'NATIONAL' ? 'National' : 'Chapter';
}

function calendar_human_type(?string $type): string
{
    $map = [
        'in_person' => 'In-person',
        'online' => 'Online',
        'hybrid' => 'Hybrid',
    ];
    return $map[$type] ?? 'Other';
}

function calendar_human_paid(int $isPaid): string
{
    return $isPaid ? 'Paid' : 'Free';
}

function calendar_build_query(array $params): string
{
    $filtered = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $filtered[$key] = $value;
    }
    return http_build_query($filtered);
}

function calendar_generate_ticket_pdf(string $filePath, array $ticket): bool
{
    $lines = [
        'Goldwing Association Ticket',
        'Event: ' . ($ticket['event_title'] ?? ''),
        'Name: ' . ($ticket['user_name'] ?? ''),
        'Email: ' . ($ticket['user_email'] ?? ''),
        'Qty: ' . ($ticket['qty'] ?? ''),
        'Code: ' . ($ticket['ticket_code'] ?? ''),
        'Start: ' . ($ticket['start_at'] ?? ''),
        'Location: ' . ($ticket['location'] ?? ''),
    ];

    $y = 740;
    $content = "BT\n/F1 16 Tf\n70 780 Td\n";
    $content .= "(Goldwing Association Ticket) Tj\nET\n";
    $content .= "BT\n/F1 12 Tf\n";
    foreach ($lines as $index => $line) {
        $line = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $content .= "70 " . $y . " Td\n(" . $line . ") Tj\n";
        $y -= 20;
    }
    $content .= "ET\n";

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n";
    $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj;
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref . "\n%%EOF";

    return file_put_contents($filePath, $pdf) !== false;
}

function calendar_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return in_array($table, $tables, true);
}

function calendar_require_tables(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        if (!calendar_table_exists($pdo, $table)) {
            http_response_code(500);
            echo 'Calendar tables are not installed. Import calendar/sql/schema.sql.';
            exit;
        }
    }
}
