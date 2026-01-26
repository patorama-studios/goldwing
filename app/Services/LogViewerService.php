<?php
namespace App\Services;

class LogViewerService
{
    private const LOG_DIR = __DIR__ . '/../storage/logs';
    private const LOG_FILE = 'system.log';

    public static function configurePhpLogging(): void
    {
        ini_set('log_errors', '1');
        $path = self::logPath();
        if ($path !== '') {
            ini_set('error_log', $path);
        }
    }

    public static function logPath(): string
    {
        if (!self::ensureLogFile()) {
            return '';
        }
        return self::LOG_DIR . '/' . self::LOG_FILE;
    }

    public static function readTail(int $maxLines = 200, int $maxBytes = 200000): array
    {
        $path = self::logPath();
        if ($path === '') {
            return ['path' => '', 'content' => '', 'size' => 0, 'error' => 'Log file is not writable.'];
        }
        if (!file_exists($path)) {
            return ['path' => $path, 'content' => '', 'size' => 0, 'error' => 'Log file does not exist.'];
        }
        if (!is_readable($path)) {
            return ['path' => $path, 'content' => '', 'size' => 0, 'error' => 'Log file is not readable.'];
        }

        $size = filesize($path);
        $start = 0;
        if ($size > $maxBytes) {
            $start = $size - $maxBytes;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            return ['path' => $path, 'content' => '', 'size' => $size ?: 0, 'error' => 'Unable to open log file.'];
        }

        if ($start > 0) {
            fseek($handle, $start);
            // Drop partial line.
            fgets($handle);
        }

        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            $lines = [];
        }
        $lines = array_slice($lines, -$maxLines);
        $content = implode("\n", $lines);

        return ['path' => $path, 'content' => $content, 'size' => $size ?: 0, 'error' => null];
    }

    public static function clear(): bool
    {
        $path = self::logPath();
        if ($path === '') {
            return false;
        }
        $result = @file_put_contents($path, '');
        return $result !== false;
    }

    public static function write(string $message): void
    {
        $path = self::logPath();
        if ($path === '') {
            return;
        }
        $timestamp = date('d-M-Y H:i:s');
        $line = '[' . $timestamp . ' ' . date_default_timezone_get() . '] ' . $message . "\n";
        @file_put_contents($path, $line, FILE_APPEND);
    }

    private static function ensureLogFile(): bool
    {
        if (!is_dir(self::LOG_DIR)) {
            if (!@mkdir(self::LOG_DIR, 0755, true) && !is_dir(self::LOG_DIR)) {
                return false;
            }
        }
        $path = self::LOG_DIR . '/' . self::LOG_FILE;
        if (!file_exists($path)) {
            if (@file_put_contents($path, '') === false) {
                return false;
            }
        }
        return is_writable($path);
    }
}
