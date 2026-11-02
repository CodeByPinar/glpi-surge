<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * OnCall — a small on-call rota: "Monday–Friday 18:00–08:00 → Ali, weekends → Ayşe". When a surge is detected, the
 * people on call right now are e-mailed (together with the fixed recipients of the settings). Overnight slots
 * (start > end) are supported; days are a bit mask, Monday = 1 … Sunday = 64.
 */
final class OnCall
{
    public const TABLE = 'glpi_plugin_surge_oncall';

    /** @return array<int,string> bit => short day name */
    public static function days(): array
    {
        return [1 => __('Mon', 'surge'), 2 => __('Tue', 'surge'), 4 => __('Wed', 'surge'), 8 => __('Thu', 'surge'), 16 => __('Fri', 'surge'), 32 => __('Sat', 'surge'), 64 => __('Sun', 'surge')];
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        return array_values(iterator_to_array($DB->request(['FROM' => self::TABLE, 'ORDER' => ['is_active DESC', 'time_start', 'id']])));
    }

    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm . ':0'));
        return max(0, min(1440, $h * 60 + $m));
    }

    /** Is a slot active at the given time? Overnight slots belong to the day they start. */
    public static function covers(array $slot, int $ts): bool
    {
        if (!(int) $slot['is_active']) {
            return false;
        }
        $bit = static fn(int $t): int => 1 << ((int) date('N', $t) - 1);
        $now = (int) date('G', $ts) * 60 + (int) date('i', $ts);
        $a = self::minutes((string) $slot['time_start']);
        $b = self::minutes((string) $slot['time_end']);
        if ($a <= $b) {
            return ((int) $slot['days'] & $bit($ts)) && $now >= $a && $now < $b;
        }
        // overnight: today after start, or tomorrow-morning part of yesterday's slot
        return (((int) $slot['days'] & $bit($ts)) && $now >= $a) || (((int) $slot['days'] & $bit($ts - 86400)) && $now < $b);
    }

    /** Users on call at a time. @return list<int> */
    public static function current(?int $ts = null): array
    {
        $ts ??= time();
        $out = [];
        foreach (self::all() as $s) {
            if ((int) $s['users_id'] > 0 && self::covers($s, $ts)) {
                $out[] = (int) $s['users_id'];
            }
        }
        return array_values(array_unique($out));
    }

    /** @param array<string,mixed> $in */
    public static function save(int $id, array $in): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $time = static fn($v, string $def): string => preg_match('~^([01]?\d|2[0-4]):([0-5]\d)$~', trim((string) $v), $m)
            ? sprintf('%02d:%02d', min(24, (int) $m[1]), (int) $m[1] === 24 ? 0 : (int) $m[2]) : $def;
        $days = 0;
        foreach ((array) ($in['days'] ?? []) as $d) {
            $days |= ((int) $d) & 127;
        }
        $v = [
            'users_id'   => max(0, (int) ($in['users_id'] ?? 0)),
            'days'       => $days ?: 127,
            'time_start' => $time($in['time_start'] ?? '', '00:00'),
            'time_end'   => $time($in['time_end'] ?? '', '24:00'),
            'is_active'  => empty($in['is_active']) ? 0 : 1,
            'comment'    => mb_substr(trim((string) ($in['comment'] ?? '')), 0, 255),
        ];
        if ($id > 0) {
            $DB->update(self::TABLE, $v, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::TABLE, $v);
        return (int) $DB->insertId();
    }

    public static function delete(int $id): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE, ['id' => $id]);
    }

    public static function describe(array $s): string
    {
        $d = [];
        foreach (self::days() as $bit => $name) {
            if ((int) $s['days'] & $bit) {
                $d[] = $name;
            }
        }
        $days = (int) $s['days'] === 127 ? __('every day', 'surge') : ((int) $s['days'] === 31 ? __('weekdays', 'surge') : ((int) $s['days'] === 96 ? __('weekends', 'surge') : implode(' ', $d)));
        return $days . ' · ' . $s['time_start'] . '–' . $s['time_end'];
    }
}
