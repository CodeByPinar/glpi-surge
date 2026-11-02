<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * Notifier — optional webhook (Slack, Microsoft Teams workflow, or any HTTP endpoint) when a surge is detected, gets a
 * master ticket, or is resolved. Uses GLPI's proxy settings. Failures are logged (files/_log/surge.log), never fatal.
 *
 * Generic payload:
 *   {"event":"detected","surge":{"id":12,"name":"Outlook won't open","status":"new","tickets":9,
 *    "first":"2026-11-02 09:14:00","last":"2026-11-02 09:27:00","category":"Mail","location":"","url":"https://…"}}
 * Slack / Teams payload: {"text": "…"}.
 */
final class Notifier
{
    /** @return array<string,mixed>|null payload (for tests), null when nothing was sent */
    public static function send(string $event, int $sid, bool $dryRun = false): ?array
    {
        $url = Config::get('webhook_url');
        $s = Surge::get($sid);
        if (($url === '' && !$dryRun) || !$s) {
            return null;
        }
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $link = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/surge/front/surge.form.php?id=' . $sid;
        $cat = (int) $s['itilcategories_id'] > 0 ? (string) \Dropdown::getDropdownName('glpi_itilcategories', (int) $s['itilcategories_id']) : '';
        $loc = (int) $s['locations_id'] > 0 ? (string) \Dropdown::getDropdownName('glpi_locations', (int) $s['locations_id']) : '';
        $verb = [
            'detected' => __('Surge detected', 'surge'),
            'master'   => __('Major incident opened', 'surge'),
            'resolved' => __('Surge resolved', 'surge'),
        ][$event] ?? $event;
        $text = sprintf('⚡ %1$s: "%2$s" — %3$d tickets', $verb, $s['name'], $s['ticket_count'])
            . ($cat !== '' ? ' · ' . $cat : '') . ($loc !== '' ? ' · ' . $loc : '')
            . ((int) $s['tickets_id_master'] > 0 ? ' · #' . $s['tickets_id_master'] : '') . ' — ' . $link;
        $payload = match (Config::get('webhook_format')) {
            'slack', 'teams' => ['text' => $text],
            default          => ['event' => $event, 'surge' => [
                'id' => $sid, 'name' => $s['name'], 'status' => $s['status'], 'tickets' => (int) $s['ticket_count'],
                'first' => $s['date_first'], 'last' => $s['date_last'], 'category' => $cat, 'location' => $loc,
                'master_ticket' => (int) $s['tickets_id_master'] ?: null, 'problem' => (int) $s['problems_id'] ?: null, 'url' => $link,
            ], 'text' => $text],
        };
        if ($dryRun) {
            return $payload;
        }
        $ch = curl_init($url);
        $opt = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ];
        if (!empty($CFG_GLPI['proxy_name'])) {
            $opt[CURLOPT_PROXY] = $CFG_GLPI['proxy_name'] . ':' . (int) ($CFG_GLPI['proxy_port'] ?? 0);
            if (!empty($CFG_GLPI['proxy_user'])) {
                $opt[CURLOPT_PROXYUSERPWD] = $CFG_GLPI['proxy_user'] . ':' . (string) (new \GLPIKey())->decrypt((string) ($CFG_GLPI['proxy_passwd'] ?? ''));
            }
        }
        curl_setopt_array($ch, $opt);
        $out = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($out === false || $code >= 400) {
            \Toolbox::logInFile('surge', sprintf("Webhook %s for surge #%d failed: HTTP %d %s\n", $event, $sid, $code, $err));
        }
        return $payload;
    }
}
