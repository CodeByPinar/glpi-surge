<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use CommonGLPI;
use DbUtils;
use Html;
use ProfileRight;
use Session;

/**
 * Profile — rights (Administration → Profiles → Surge).
 *
 *   READ   see surges and the banner on tickets
 *   MANAGE acknowledge, create master ticket / problem, broadcast, resolve, dismiss
 *   CONFIG detection rules and settings
 *
 * Fresh install: profiles with configuration rights get everything; other central-interface profiles get READ | MANAGE.
 * Upgrades never touch existing rights.
 */
class Profile extends CommonGLPI
{
    public const RIGHT  = 'plugin_surge';
    public const READ   = 1;
    public const MANAGE = 2;
    public const CONFIG = 1024;
    public const ALL    = self::READ | self::MANAGE | self::CONFIG;

    public static function getTypeName($nb = 0)
    {
        return 'Surge';
    }

    public static function has(int $bit): bool
    {
        return (bool) Session::haveRight(self::RIGHT, $bit);
    }

    public static function canRead(): bool
    {
        return self::has(self::READ) || self::has(self::MANAGE) || self::has(self::CONFIG);
    }

    /** @return list<array{rights:array<int,string>,label:string,field:string}> */
    public static function getAllRights(): array
    {
        return [[
            'rights' => [
                self::READ   => __('See surges', 'surge'),
                self::MANAGE => __('Handle surges (master ticket, problem, broadcast, resolve)', 'surge'),
                self::CONFIG => __('Detection rules and settings', 'surge'),
            ],
            'label' => 'Surge',
            'field' => self::RIGHT,
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof \Profile && (int) $item->getID() > 0 ? self::createTabEntry('Surge', 0, null, 'ti ti-bolt') : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Profile) {
            return false;
        }
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        echo "<div class='firstbloc'>";
        if ($canedit) {
            echo "<form method='post' action='" . \Profile::getFormURL() . "'>";
        }
        $item->displayRightsChoiceMatrix(self::getAllRights(), ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Surge']);
        if ($canedit) {
            echo "<div class='center'>" . Html::hidden('id', ['value' => $item->getID()]) . Html::submit(_sx('button', 'Save'), ['name' => 'update']) . "</div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }

    /** Adds the right row if missing (never changes an existing one). */
    public static function ensure(int $profilesId, int $value): void
    {
        if ((new DbUtils())->countElementsInTable('glpi_profilerights', ['profiles_id' => $profilesId, 'name' => self::RIGHT]) > 0) {
            return;
        }
        (new ProfileRight())->add(['profiles_id' => $profilesId, 'name' => self::RIGHT, 'rights' => $value]);
        if ((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0) === $profilesId) {
            $_SESSION['glpiactiveprofile'][self::RIGHT] = $value;
        }
    }

    public static function onProfileAdd(\Profile $p): void
    {
        self::ensure((int) $p->getID(), 0);
    }

    public static function install(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $admin = [];
        foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $r) {
            if (((int) $r['rights'] & UPDATE) === UPDATE) {
                $admin[(int) $r['profiles_id']] = true;
            }
        }
        foreach ($DB->request(['SELECT' => ['id', 'interface'], 'FROM' => 'glpi_profiles']) as $p) {
            $v = isset($admin[(int) $p['id']]) ? self::ALL : ($p['interface'] === 'central' ? self::READ | self::MANAGE : 0);
            self::ensure((int) $p['id'], $v);
        }
    }

    public static function uninstall(): void
    {
        (new ProfileRight())->deleteByCriteria(['name' => self::RIGHT]);
    }
}
