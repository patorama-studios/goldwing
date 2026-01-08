<?php
require_once __DIR__ . '/utils.php';

function calendar_parse_rrule(?string $rule): array
{
    $parsed = [];
    if (!$rule) {
        return $parsed;
    }
    $parts = explode(';', $rule);
    foreach ($parts as $part) {
        if (strpos($part, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $part, 2);
        $parsed[strtoupper(trim($key))] = strtoupper(trim($value));
    }
    return $parsed;
}

function calendar_expand_occurrences(array $event, DateTime $rangeStartUtc, DateTime $rangeEndUtc): array
{
    $occurrences = [];
    if (($event['status'] ?? '') !== 'published' && ($event['status'] ?? '') !== 'cancelled') {
        return $occurrences;
    }

    $tz = new DateTimeZone($event['timezone'] ?? calendar_config('timezone_default', 'UTC'));
    $startLocal = new DateTime($event['start_at'], $tz);
    $endLocal = new DateTime($event['end_at'], $tz);
    $duration = $endLocal->getTimestamp() - $startLocal->getTimestamp();

    $rule = calendar_parse_rrule($event['recurrence_rule'] ?? '');
    if (empty($rule)) {
        $occStartUtc = clone $startLocal;
        $occStartUtc->setTimezone(new DateTimeZone('UTC'));
        if ($occStartUtc >= $rangeStartUtc && $occStartUtc <= $rangeEndUtc) {
            $occurrences[] = [
                'start' => clone $startLocal,
                'end' => (clone $startLocal)->modify('+' . $duration . ' seconds'),
            ];
        }
        return $occurrences;
    }

    $freq = $rule['FREQ'] ?? '';
    $interval = isset($rule['INTERVAL']) ? max(1, (int) $rule['INTERVAL']) : 1;
    $byday = isset($rule['BYDAY']) ? explode(',', $rule['BYDAY']) : [];

    $until = null;
    if (!empty($rule['UNTIL'])) {
        $untilRaw = $rule['UNTIL'];
        if (strpos($untilRaw, 'T') !== false && substr($untilRaw, -1) === 'Z') {
            $until = DateTime::createFromFormat('Ymd\THis\Z', $untilRaw, new DateTimeZone('UTC'));
            if ($until) {
                $until->setTimezone($tz);
            }
        } else {
            $until = DateTime::createFromFormat('Ymd', $untilRaw, $tz);
            if ($until) {
                $until->setTime(23, 59, 59);
            }
        }
    }

    $rangeStartLocal = clone $rangeStartUtc;
    $rangeStartLocal->setTimezone($tz);
    $rangeEndLocal = clone $rangeEndUtc;
    $rangeEndLocal->setTimezone($tz);

    $startDay = (int) $startLocal->format('d');
    $startTime = $startLocal->format('H:i:s');
    $baseDate = new DateTime($startLocal->format('Y-m-d'), $tz);

    $cursor = new DateTime($rangeStartLocal->format('Y-m-d'), $tz);
    $endCursor = new DateTime($rangeEndLocal->format('Y-m-d'), $tz);

    $dayMap = [
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
        'SU' => 7,
    ];
    $bydayNums = [];
    foreach ($byday as $day) {
        if (isset($dayMap[$day])) {
            $bydayNums[] = $dayMap[$day];
        }
    }
    if (empty($bydayNums)) {
        $bydayNums[] = (int) $startLocal->format('N');
    }

    while ($cursor <= $endCursor) {
        if ($cursor < $baseDate) {
            $cursor->modify('+1 day');
            continue;
        }
        $daysSinceStart = (int) $baseDate->diff($cursor)->format('%r%a');
        $occurs = false;

        if ($freq === 'DAILY') {
            $occurs = ($daysSinceStart % $interval === 0);
        } elseif ($freq === 'WEEKLY') {
            $weeksSinceStart = (int) floor($daysSinceStart / 7);
            $occurs = ($weeksSinceStart % $interval === 0) && in_array((int) $cursor->format('N'), $bydayNums, true);
        } elseif ($freq === 'MONTHLY') {
            $monthDiff = ((int) $cursor->format('Y') - (int) $baseDate->format('Y')) * 12;
            $monthDiff += (int) $cursor->format('n') - (int) $baseDate->format('n');
            $occurs = ($monthDiff % $interval === 0) && ((int) $cursor->format('d') === $startDay);
        }

        if ($occurs) {
            $occStart = new DateTime($cursor->format('Y-m-d') . ' ' . $startTime, $tz);
            if ($occStart < $startLocal) {
                $cursor->modify('+1 day');
                continue;
            }
            if ($until && $occStart > $until) {
                break;
            }
            $occEnd = (clone $occStart)->modify('+' . $duration . ' seconds');
            $occStartUtc = clone $occStart;
            $occStartUtc->setTimezone(new DateTimeZone('UTC'));
            if ($occStartUtc >= $rangeStartUtc && $occStartUtc <= $rangeEndUtc) {
                $occurrences[] = [
                    'start' => $occStart,
                    'end' => $occEnd,
                ];
            }
        }
        $cursor->modify('+1 day');
    }

    return $occurrences;
}
