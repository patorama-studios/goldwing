<?php
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ActivityLogger;
use App\Services\FileIntegrityService;
use App\Services\SecurityAlertService;
use App\Services\SecuritySettingsService;

$settings = SecuritySettingsService::get();
if (!$settings['fim_enabled']) {
    echo "FIM disabled.\n";
    exit;
}

$root = dirname(__DIR__);
try {
    $changes = FileIntegrityService::scan($root, $settings['fim_paths'], $settings['fim_exclude_paths']);
    $hasChanges = !empty($changes['added']) || !empty($changes['modified']) || !empty($changes['deleted']);
    if ($hasChanges) {
        FileIntegrityService::recordScanResult('CHANGES_DETECTED', $changes);
        $lines = [];
        foreach (['added', 'modified', 'deleted'] as $type) {
            foreach ($changes[$type] as $path) {
                $lines[] = strtoupper($type) . ': ' . $path;
            }
        }
        $body = '<p>File integrity changes detected:</p><pre>' . e(implode("\n", $lines)) . '</pre>';
        SecurityAlertService::send('fim_changes', 'Security alert: file integrity changes', $body);
        ActivityLogger::log('system', null, null, 'security.fim_changes_detected', ['changes' => $changes]);
    } else {
        FileIntegrityService::recordScanResult('OK');
    }
    echo "FIM scan complete.\n";
} catch (Throwable $e) {
    FileIntegrityService::recordScanResult('ERROR', ['error' => $e->getMessage()]);
    SecurityAlertService::send('fim_changes', 'Security alert: file integrity scan error', '<p>Scan error: ' . e($e->getMessage()) . '</p>');
    ActivityLogger::log('system', null, null, 'security.fim_scan_error', ['error' => $e->getMessage()]);
    echo "FIM scan error.\n";
}
