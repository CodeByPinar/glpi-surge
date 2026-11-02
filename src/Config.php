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

    /** @param array<string,mixed> $in */
    public static function save(array $in): void
    {
        $v = [];
        foreach (self::DEFAULTS as $k => $def) {
            $x = trim((string) ($in[$k] ?? ''));
            $v[$k] = match ($k) {
                'webhook_url'      => preg_match('~^https?://~i', $x) ? mb_substr($x, 0, 500) : '',
                'webhook_format'   => in_array($x, ['generic', 'slack', 'teams'], true) ? $x : 'generic',
                'master_urgency', 'master_impact' => (string) max(1, min(5, (int) $x ?: (int) $def)),
                'active_hours'     => (string) max(1, min(720, (int) $x ?: (int) $def)),
                default            => $x === '1' ? '1' : '0',
            };
        }
        \Config::setConfigurationValues(self::CONTEXT, $v);
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
