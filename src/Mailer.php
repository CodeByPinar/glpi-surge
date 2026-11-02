<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * Mailer — e-mails the people who must know about a new surge: the fixed recipients of the settings (users, groups) and
 * whoever is on call right now. Messages go through GLPI's own notification queue, so they use GLPI's mail settings,
 * retries and the "Notification queue" screen; nothing is sent when GLPI e-mail notifications are disabled.
 */
final class Mailer
{
    /** Recipients for a new surge. @return array<int,string> users_id => e-mail */
    public static function recipients(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ids = array_map('intval', (array) json_decode(Config::get('notify_users'), true));
        $groups = array_map('intval', (array) json_decode(Config::get('notify_groups'), true));
        if ($groups) {
            foreach ($DB->request(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groups]]) as $r) {
                $ids[] = (int) $r['users_id'];
            }
        }
        if (Config::get('notify_oncall') === '1') {
            $ids = array_merge($ids, OnCall::current());
        }
        $out = [];
        foreach (array_unique(array_filter($ids)) as $uid) {
            $u = new \User();
            if (!$u->getFromDB($uid) || !$u->fields['is_active'] || $u->fields['is_deleted']) {
                continue;
            }
            $mail = (string) \UserEmail::getDefaultForUser($uid);
            if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $out[$uid] = $mail;
            }
        }
        return $out;
    }

    /** Queues one e-mail per recipient. @return int number of queued e-mails */
    public static function surgeDetected(int $sid): int
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $s = Surge::get($sid);
        if (!$s || empty($CFG_GLPI['use_notifications']) || empty($CFG_GLPI['notifications_mailing'])) {
            return 0;
        }
        $to = self::recipients();
        if (!$to) {
            return 0;
        }
        $link = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/surge/front/surge.form.php?id=' . $sid;
        $cat = (int) $s['itilcategories_id'] > 0 ? (string) \Dropdown::getDropdownName('glpi_itilcategories', (int) $s['itilcategories_id']) : '';
        $loc = (int) $s['locations_id'] > 0 ? (string) \Dropdown::getDropdownName('glpi_locations', (int) $s['locations_id']) : '';
        $mins = max(1, (int) round((strtotime((string) $s['date_last']) - strtotime((string) $s['date_first'])) / 60));
        $subject = sprintf(__('[Surge] %1$s — %2$d similar tickets', 'surge'), $s['name'], $s['ticket_count']);
        $lines = [
            sprintf(__('A burst of similar tickets was detected: %1$d tickets in %2$d minutes.', 'surge'), $s['ticket_count'], $mins),
            $cat !== '' ? __('Category', 'surge') . ': ' . $cat : '',
            $loc !== '' ? __('Location', 'surge') . ': ' . $loc : '',
        ];
        $titles = [];
        foreach (array_slice(Surge::ticketIds($sid), 0, 15) as $tid) {
            $t = new \Ticket();
            if ($t->getFromDB($tid)) {
                $titles[] = '#' . $tid . ' ' . $t->fields['name'];
            }
        }
        $e = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#1b2236">'
            . '<p style="font-size:16px"><b>⚡ ' . $e($s['name']) . '</b></p>'
            . implode('', array_map(static fn($l) => $l !== '' ? '<p style="margin:4px 0">' . $e($l) . '</p>' : '', $lines))
            . '<ul>' . implode('', array_map(static fn($t) => '<li>' . $e($t) . '</li>', $titles)) . '</ul>'
            . '<p><a href="' . $e($link) . '" style="background:#e8590c;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none">' . $e(__('Open surge', 'surge')) . '</a></p></div>';
        $text = implode("\n", array_filter($lines)) . "\n\n" . implode("\n", $titles) . "\n\n" . $link;
        $from = \Config::getEmailSender((int) $s['entities_id'], true);
        $n = 0;
        foreach ($to as $uid => $mail) {
            $q = new \QueuedNotification();
            if ($q->add([
                'itemtype'      => 'Entity',
                'items_id'      => (int) $s['entities_id'],
                'notificationtemplates_id' => 0,
                'entities_id'   => (int) $s['entities_id'],
                'name'          => $subject,
                'sender'        => (string) ($from['email'] ?? ''),
                'sendername'    => (string) ($from['name'] ?? ''),
                'recipient'     => $mail,
                'recipientname' => (string) getUserName($uid),
                'body_html'     => $html,
                'body_text'     => $text,
                'mode'          => 'mailing',
                'event'         => 'surge_detected',
            ])) {
                $n++;
            }
        }
        return $n;
    }
}
