<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * Rule — what counts as a surge: "at least N open tickets within M minutes that share the category / location and
 * have similar titles". Several rules may run side by side (e.g. a sensitive one for network outages).
 */
final class Rule
{
    public const TABLE = 'glpi_plugin_surge_rules';

    public const FIELDS = [
        'name'           => '',
        'is_active'      => 1,
        'entities_id'    => 0,
        'is_recursive'   => 1,
        'window_minutes' => 30,
        'min_tickets'    => 5,
        'similarity'     => 0.35,
        'match_category' => 1,
        'match_location' => 0,
        'match_title'    => 1,
        'ticket_type'    => 0,  // 0 any, 1 incident, 2 request
        'categories'     => '', // JSON list of ITIL category ids; empty = all
        'comment'        => '',
    ];

    /** @return list<array<string,mixed>> */
    public static function all(bool $activeOnly = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $w = $activeOnly ? ['is_active' => 1] : [];
        return array_values(iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => $w, 'ORDER' => ['is_active DESC', 'name']])));
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $r = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]])->current();
        return $r ?: null;
    }

    /** @return list<int> */
    public static function categoryIds(array $rule): array
    {
        return array_values(array_filter(array_map('intval', (array) json_decode((string) $rule['categories'], true))));
    }

    /** Cleans form input. @param array<string,mixed> $in @return array<string,mixed> */
    public static function clean(array $in): array
    {
        return [
            'name'           => mb_substr(trim((string) ($in['name'] ?? '')), 0, 255) ?: __('Unnamed rule', 'surge'),
            'is_active'      => empty($in['is_active']) ? 0 : 1,
            'entities_id'    => max(0, (int) ($in['entities_id'] ?? 0)),
            'is_recursive'   => empty($in['is_recursive']) ? 0 : 1,
            'window_minutes' => max(1, min(1440, (int) ($in['window_minutes'] ?? 30))),
            'min_tickets'    => max(2, min(500, (int) ($in['min_tickets'] ?? 5))),
            'similarity'     => round(max(0.05, min(1.0, (float) str_replace(',', '.', (string) ($in['similarity'] ?? 0.35)))), 2),
            'match_category' => empty($in['match_category']) ? 0 : 1,
            'match_location' => empty($in['match_location']) ? 0 : 1,
            'match_title'    => empty($in['match_title']) ? 0 : 1,
            'ticket_type'    => in_array((int) ($in['ticket_type'] ?? 0), [0, 1, 2], true) ? (int) $in['ticket_type'] : 0,
            'categories'     => json_encode(array_values(array_filter(array_map('intval', (array) ($in['categories'] ?? []))))),
            'comment'        => mb_substr(trim((string) ($in['comment'] ?? '')), 0, 2000),
        ];
    }

    public static function save(int $id, array $in): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $v = self::clean($in) + ['date_mod' => date('Y-m-d H:i:s')];
        if ($id > 0) {
            $DB->update(self::TABLE, $v, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::TABLE, $v + ['date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    public static function delete(int $id): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE, ['id' => $id]);
    }

    /** Human summary: "≥ 5 tickets in 30 min · same category · similar titles (35 %)". */
    public static function summary(array $r): string
    {
        $p = [sprintf(__('≥ %1$d tickets in %2$d min', 'surge'), $r['min_tickets'], $r['window_minutes'])];
        if ($r['match_category']) {
            $p[] = __('same category', 'surge');
        }
        if ($r['match_location']) {
            $p[] = __('same location', 'surge');
        }
        if ($r['match_title']) {
            $p[] = sprintf(__('similar text (%d %%)', 'surge'), round((float) $r['similarity'] * 100));
        }
        if ((int) $r['ticket_type'] === 1) {
            $p[] = __('incidents only', 'surge');
        } elseif ((int) $r['ticket_type'] === 2) {
            $p[] = __('requests only', 'surge');
        }
        return implode(' · ', $p);
    }
}
