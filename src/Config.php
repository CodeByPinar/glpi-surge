<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/** Config — plugin settings stored in GLPI's configuration table (context "plugin:surge"). */
final class Config
{
    public const CONTEXT = 'plugin:surge';

    public const DEFAULTS = [
        'webhook_url'      => '',        // POST a JSON message when a surge is detected / handled
        'webhook_format'   => 'generic', // generic | slack | teams
        'master_urgency'   => '4',       // urgency / impact of the master ticket and the problem
        'master_impact'    => '4',
        'cascade_solution' => '1',       // solving the master ticket solves every linked child
        'link_new'         => '1',       // tickets joining an acknowledged surge are linked to its master automatically
        'active_hours'     => '24',      // a surge keeps collecting new tickets this long after its last ticket
        // 0.2.0
        'notify_users'     => '[]',      // e-mailed when a surge is detected (JSON list of user ids)
        'notify_groups'    => '[]',      // … members of these groups (JSON list of group ids)
        'notify_oncall'    => '1',       // … and whoever is on call right now (OnCall rota)
        'notice_central'   => '0',       // show public notices to central-interface users too (not only self-service)
        'notice_resolved_hours' => '2',  // a resolved surge's notice stays visible (as "resolved") this long
        'embed_enabled'    => '0',       // optional semantic similarity (OpenAI-compatible /v1/embeddings)
        'embed_url'        => '',
        'embed_model'      => '',
        'embed_key'        => '',        // encrypted with GLPIKey
        'embed_weight'     => '0.5',     // share of the embedding similarity in the final score
    ];

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(string $key): string
    {
        if (self::$cache === null) {
            self::$cache = \Config::getConfigurationValues(self::CONTEXT);
        }
        return (string) (self::$cache[$key] ?? (self::DEFAULTS[$key] ?? ''));
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    /** Decrypted secret setting. */
    public static function secret(string $key): string
    {
        $v = self::get($key);
        if ($v === '') {
            return '';
        }
        try {
            return (string) (new \GLPIKey())->decrypt($v);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @return list<int> */
    public static function ids(string $key): array
    {
        return array_values(array_filter(array_map('intval', (array) json_decode(self::get($key), true))));
    }

    /**
     * Saves the keys present in $in (others are kept). Secrets: an empty value keeps the stored one.
     * @param array<string,mixed> $in
     */
    public static function save(array $in): void
    {
        $v = [];
        foreach (self::DEFAULTS as $k => $def) {
            if (!array_key_exists($k, $in)) {
                continue;
            }
            $raw = $in[$k];
            $x = is_array($raw) ? '' : trim((string) $raw);
            $v[$k] = match ($k) {
                'webhook_url', 'embed_url' => preg_match('~^https?://~i', $x) ? mb_substr($x, 0, 500) : '',
                'webhook_format'   => in_array($x, ['generic', 'slack', 'teams'], true) ? $x : 'generic',
                'master_urgency', 'master_impact' => (string) max(1, min(5, (int) $x ?: (int) $def)),
                'active_hours'     => (string) max(1, min(720, (int) $x ?: (int) $def)),
                'notice_resolved_hours' => (string) max(0, min(72, (int) $x)),
                'notify_users', 'notify_groups' => json_encode(array_values(array_filter(array_map('intval', (array) $raw)))),
                'embed_model'      => mb_substr($x, 0, 100),
                'embed_weight'     => (string) round(max(0.0, min(1.0, (float) str_replace(',', '.', $x))), 2),
                'embed_key'        => $x === '' ? self::get('embed_key') : (string) (new \GLPIKey())->encrypt($x),
                default            => $x === '1' ? '1' : '0',
            };
        }
        if (!empty($in['embed_key_clear'])) {
            $v['embed_key'] = '';
        }
        if ($v) {
            \Config::setConfigurationValues(self::CONTEXT, $v);
        }
        self::$cache = null;
    }

    /** Adds missing keys with their defaults; never overwrites. */
    public static function install(): void
    {
        $have = \Config::getConfigurationValues(self::CONTEXT);
        $add = array_diff_key(self::DEFAULTS, $have);
        if ($add) {
            \Config::setConfigurationValues(self::CONTEXT, $add);
        }
        self::$cache = null;
    }

    public static function uninstall(): void
    {
        \Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::DEFAULTS));
    }
}
