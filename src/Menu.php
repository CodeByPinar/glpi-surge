<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use CommonGLPI;

/** Menu — "Assistance → Surge" entry. */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return 'Surge';
    }

    public static function getMenuName()
    {
        return 'Surge';
    }

    public static function getIcon()
    {
        return 'ti ti-bolt';
    }

    public static function canView(): bool
    {
        return Profile::canRead();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string,mixed>|false */
    public static function getMenuContent()
    {
        if (!Profile::canRead()) {
            return false;
        }
        $menu = [
            'title' => 'Surge',
            'page'  => '/plugins/surge/front/surge.php',
            'icon'  => self::getIcon(),
        ];
        return $menu;
    }
}
