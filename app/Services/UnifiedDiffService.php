<?php
namespace App\Services;

class UnifiedDiffService
{
    public static function extractSummaryAndDiff(string $response): array
    {
        $clean = trim($response);
        if (preg_match('/```(?:diff)?(.*?)```/s', $clean, $matches)) {
            $clean = trim($matches[1]);
        }

        $lines = preg_split("/\r\n|\n|\r/", $clean);
        $summary = '';
        $diffStart = null;

        foreach ($lines as $index => $line) {
            if (stripos($line, 'Summary:') === 0) {
                $summary = trim(substr($line, 8));
            }
            if (strpos($line, '--- ') === 0 || strpos($line, 'diff --git') === 0) {
                $diffStart = $index;
                break;
            }
        }

        if ($diffStart === null) {
            return ['summary' => $summary, 'diff' => ''];
        }

        $diff = implode("\n", array_slice($lines, $diffStart));
        return ['summary' => $summary, 'diff' => $diff];
    }

    public static function parse(string $diff): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $diff);
        $files = [];
        $current = null;
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];
            if (strpos($line, 'diff --git') === 0) {
                $current = null;
                $i++;
                continue;
            }
            if (strpos($line, '--- ') === 0) {
                $oldPath = trim(substr($line, 4));
                $i++;
                $newLine = $lines[$i] ?? '';
                if (strpos($newLine, '+++ ') !== 0) {
                    $i++;
                    continue;
                }
                $newPath = trim(substr($newLine, 4));
                $current = [
                    'old' => self::normalizePath($oldPath),
                    'new' => self::normalizePath($newPath),
                    'hunks' => [],
                ];
                $files[] = $current;
                $i++;
                continue;
            }
            if (strpos($line, '@@') === 0 && $current !== null) {
                $hunkHeader = $line;
                if (!preg_match('/@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $hunkHeader, $matches)) {
                    $i++;
                    continue;
                }
                $oldStart = (int) $matches[1];
                $oldCount = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 1;
                $newStart = (int) $matches[3];
                $newCount = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 1;
                $i++;
                $linesChunk = [];
                while ($i < count($lines)) {
                    $chunkLine = $lines[$i];
                    if (strpos($chunkLine, '@@') === 0 || strpos($chunkLine, '--- ') === 0 || strpos($chunkLine, 'diff --git') === 0) {
                        break;
                    }
                    if ($chunkLine === '\\ No newline at end of file') {
                        $i++;
                        continue;
                    }
                    $linesChunk[] = $chunkLine;
                    $i++;
                }
                $files[count($files) - 1]['hunks'][] = [
                    'old_start' => $oldStart,
                    'old_count' => $oldCount,
                    'new_start' => $newStart,
                    'new_count' => $newCount,
                    'lines' => $linesChunk,
                ];
                continue;
            }

            $i++;
        }

        return $files;
    }

    public static function apply(string $original, array $filePatch): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $original);
        $offset = 0;

        foreach ($filePatch['hunks'] as $hunk) {
            $index = $hunk['old_start'] - 1 + $offset;
            $pointer = $index;
            $replacement = [];

            foreach ($hunk['lines'] as $line) {
                $prefix = substr($line, 0, 1);
                $content = substr($line, 1);
                if ($prefix === ' ') {
                    if (!array_key_exists($pointer, $lines) || $lines[$pointer] !== $content) {
                        return [false, $original, 'Patch context mismatch.'];
                    }
                    $replacement[] = $content;
                    $pointer++;
                    continue;
                }
                if ($prefix === '-') {
                    if (!array_key_exists($pointer, $lines) || $lines[$pointer] !== $content) {
                        return [false, $original, 'Patch removal mismatch.'];
                    }
                    $pointer++;
                    continue;
                }
                if ($prefix === '+') {
                    $replacement[] = $content;
                    continue;
                }
            }

            $removeLength = $pointer - $index;
            array_splice($lines, $index, $removeLength, $replacement);
            $offset += count($replacement) - $removeLength;
        }

        return [true, implode("\n", $lines), ''];
    }

    private static function normalizePath(string $path): string
    {
        $path = preg_replace('/^[ab]\//', '', $path);
        return trim($path);
    }

    public static function generateFullDiff(string $before, string $after, string $path): string
    {
        $beforeLines = preg_split("/\r\n|\n|\r/", $before);
        $afterLines = preg_split("/\r\n|\n|\r/", $after);
        $beforeCount = count($beforeLines);
        $afterCount = count($afterLines);

        $diff = "--- " . $path . "\n";
        $diff .= "+++ " . $path . "\n";
        $diff .= "@@ -1," . $beforeCount . " +1," . $afterCount . " @@\n";

        foreach ($beforeLines as $line) {
            $diff .= "-" . $line . "\n";
        }
        foreach ($afterLines as $line) {
            $diff .= "+" . $line . "\n";
        }

        return $diff;
    }
}
