<?php

function format_date_input($value): ?\DateTimeImmutable
{
    if ($value instanceof \DateTimeInterface) {
        return \DateTimeImmutable::createFromInterface($value);
    }
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return \DateTimeImmutable::createFromFormat('U', (int) $value);
    }
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return null;
    }
    if (str_contains($normalized, '0000-00-00')) {
        return null;
    }
    try {
        return new \DateTimeImmutable($normalized);
    } catch (\Throwable $e) {
        return null;
    }
}

function format_date_au($value): string
{
    $date = format_date_input($value);
    if (!$date) {
        return '—';
    }
    return $date->format('d/m/Y');
}

function format_datetime_au($value): string
{
    $date = format_date_input($value);
    if (!$date) {
        return '—';
    }
    return $date->format('d/m/Y H:i');
}

if (!function_exists('format_date')) {
    function format_date($value): string
    {
        return format_date_au($value);
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime($value): string
    {
        return format_datetime_au($value);
    }
}

if (!function_exists('formatDate')) {
    function formatDate($value): string
    {
        return format_date_au($value);
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($value): string
    {
        return format_datetime_au($value);
    }
}
