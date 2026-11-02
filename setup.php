<?php

/**
 * -------------------------------------------------------------------------
 * Surge — major incident detection for GLPI
 * -------------------------------------------------------------------------
 *
 * When an outage hits, the service desk receives the same complaint many times in a few minutes ("Outlook won't open",
 * "no internet on floor 3"). Surge spots these bursts while they happen, groups the tickets into one *surge*, and lets
 * L1 handle them as a single major incident: create one master ticket or problem, link every child, broadcast one
 * update, and resolve them all at once.
 *
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/CodeByPinar/glpi-surge
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Surge\Menu;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\TicketHooks;

define('PLUGIN_SURGE_VERSION', '0.3.1');
define('PLUGIN_SURGE_MIN_GLPI', '11.0.0');
define('PLUGIN_SURGE_MAX_GLPI', '12.99.99');

function plugin_init_surge(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    if (defined(Hooks::class . '::CSRF_COMPLIANT')) {
        $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['surge'] = true; // GLPI 11 (GLPI 12 has no CSRF tokens)
    }
    if (!(new Plugin())->isActivated('surge')) {
        return;
    }

    Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']);
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['surge'] = [
        'Profile'      => [Profile::class, 'onProfileAdd'],
        'Ticket'       => [TicketHooks::class, 'onTicketAdd'],
        'ITILSolution' => [TicketHooks::class, 'onSolutionAdd'],
    ];
    // banner on top of the ticket's right panel when the ticket belongs to a surge
    $PLUGIN_HOOKS[Hooks::PRE_ITIL_INFO_SECTION]['surge'] = [TicketHooks::class, 'banner'];

    if (Session::getLoginUserID() !== false && Profile::canRead()) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['surge'] = ['helpdesk' => Menu::class];
    }
    if (Profile::has(Profile::CONFIG)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['surge'] = 'front/config.php';
    }
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['surge'] = 'css/surge.css';

    // public notices ("We are aware of the mail outage…") on the self-service portal (and optionally for everyone)
    if (Session::getLoginUserID() !== false) {
        try {
            if (Session::getCurrentInterface() === 'helpdesk' || \GlpiPlugin\Surge\Config::get('notice_central') === '1') {
                $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['surge'] = 'js/notice.js';
            }
        } catch (\Throwable $e) {
            // settings not readable yet (install / upgrade in progress)
        }
    }
}

function plugin_version_surge(): array
{
    return [
        'name'         => 'Surge',
        'version'      => PLUGIN_SURGE_VERSION,
        'author'       => 'Pınar Topuz',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/CodeByPinar/glpi-surge',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_SURGE_MIN_GLPI, 'max' => PLUGIN_SURGE_MAX_GLPI],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_surge_check_prerequisites(): bool
{
    return true;
}

function plugin_surge_check_config($verbose = false): bool
{
    return true;
}
