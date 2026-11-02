<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use Plugin;
use Session;

/**
 * Events — extension points for other plugins (see docs/EXTENDING.md).
 *
 *  - `surge_event`  (action) fired after something happens to a surge: detected, ticket_joined, acknowledged, master,
 *                    problem, broadcast, resolved, dismissed, reopened, notice_published, notice_withdrawn,
 *                    ticket_removed. Parameter: ['event' => …, 'surges_id' => …, 'users_id' => …] + event data.
 *  - `surge_tabs`   (filter) tabs of the Surge pages: key => [label, icon class, url (optional)].
 *  - `surge_page`   (action) extra content on a surge page: ['slot' => 'main'|'side', 'surge' => row]; echo HTML.
 *
 * A failing listener is logged in files/_log/surge.log and never breaks Surge itself.
 */
final class Events
{
    public const EVENT = 'surge_event';
    public const TABS  = 'surge_tabs';
    public const PAGE  = 'surge_page';

    public static function fire(string $event, int $sid, array $data = []): void
    {
        try {
            Plugin::doHook(self::EVENT, ['event' => $event, 'surges_id' => $sid, 'users_id' => (int) (Session::getLoginUserID() ?: 0)] + $data);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('surge', sprintf("Listener of %s (surge #%d): %s\n", $event, $sid, $e->getMessage()));
        }
    }

    /** @param array<string,array{0:string,1:string,2?:string}> $tabs */
    public static function tabs(array $tabs): array
    {
        try {
            $out = Plugin::doHookFunction(self::TABS, $tabs);
            return is_array($out) ? $out : $tabs;
        } catch (\Throwable $e) {
            \Toolbox::logInFile('surge', 'Listener of surge_tabs: ' . $e->getMessage() . "\n");
            return $tabs;
        }
    }

    public static function page(string $slot, array $surge): void
    {
        try {
            Plugin::doHook(self::PAGE, ['slot' => $slot, 'surge' => $surge]);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('surge', 'Listener of surge_page: ' . $e->getMessage() . "\n");
        }
    }
}
