<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Settings: master ticket priority, cascading solutions, auto-linking, surge lifetime, webhook.
 */

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::CONFIG);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Config::save($_POST);
    Session::addMessageAfterRedirect(__('Settings saved.', 'surge'), false, INFO);
    Html::redirect(Ui::url('config'));
}

Ui::header('config', __('Settings', 'surge'));
$chk = static fn(string $n, string $label, string $help): string => "<label class='form-check mb-3'><input class='form-check-input' type='checkbox' name='{$n}' value='1'" . (Config::get($n) === '1' ? ' checked' : '') . ">"
    . "<span class='form-check-label'>" . Ui::e($label) . "</span><span class='form-check-description small text-muted d-block'>" . Ui::e($help) . "</span></label>";
$sel = static function (string $n, array $opts): string {
    $h = "<select class='form-select' name='{$n}'>";
    foreach ($opts as $k => $l) {
        $h .= "<option value='" . Ui::e($k) . "'" . ((string) Config::get($n) === (string) $k ? ' selected' : '') . ">" . Ui::e($l) . "</option>";
    }
    return $h . "</select>";
};
$levels = [5 => __('Very high', 'surge'), 4 => __('High', 'surge'), 3 => __('Medium', 'surge'), 2 => __('Low', 'surge'), 1 => __('Very low', 'surge')];

echo "<form method='post' action='" . Ui::e(Ui::url('config')) . "'>" . Ui::csrf() . "<div class='row g-3'>";
echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Handling', 'surge')) . "</h3></div><div class='card-body'>";
echo "<div class='row g-2 mb-3'><div class='col-6'><label class='form-label'>" . Ui::e(__('Master ticket urgency', 'surge')) . "</label>" . $sel('master_urgency', $levels) . "</div>"
    . "<div class='col-6'><label class='form-label'>" . Ui::e(__('Master ticket impact', 'surge')) . "</label>" . $sel('master_impact', $levels) . "</div></div>";
echo $chk('cascade_solution', __('Solving the master ticket solves every linked ticket', 'surge'), __('The same solution text is added to each open ticket of the surge; GLPI notifies the requesters as usual.', 'surge'));
echo $chk('link_new', __('Link late tickets to the master automatically', 'surge'), __('Tickets that join a surge after its master ticket was created become its children too.', 'surge'));
echo "<label class='form-label'>" . Ui::e(__('A surge keeps collecting tickets for (hours after its last ticket)', 'surge')) . "</label>"
    . "<input class='form-control' type='number' min='1' max='720' name='active_hours' value='" . Config::int('active_hours') . "'>";
echo "</div></div></div>";

echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Webhook', 'surge')) . "</h3></div><div class='card-body'>";
echo "<label class='form-label'>" . Ui::e(__('URL', 'surge')) . "</label><input class='form-control mb-3' name='webhook_url' placeholder='https://hooks.slack.com/services/…' value='" . Ui::e(Config::get('webhook_url')) . "'>";
echo "<label class='form-label'>" . Ui::e(__('Format', 'surge')) . "</label>" . $sel('webhook_format', ['generic' => __('Generic JSON', 'surge'), 'slack' => 'Slack', 'teams' => 'Microsoft Teams (workflow)']);
echo "<div class='form-text mt-2'>" . Ui::e(__('Sent when a surge is detected, gets a master ticket and is resolved. GLPI proxy settings are used. Leave empty to disable.', 'surge')) . "</div>";
echo "<details class='mt-3'><summary class='small'>" . Ui::e(__('Example payload', 'surge')) . "</summary><pre class='small mt-2 mb-0'>" . Ui::e(json_encode([
    'event' => 'detected',
    'surge' => ['id' => 12, 'name' => "Outlook won't open", 'status' => 'new', 'tickets' => 9, 'first' => '2026-11-02 09:14:00', 'last' => '2026-11-02 09:27:00', 'category' => 'Mail', 'location' => '', 'master_ticket' => null, 'problem' => null, 'url' => '…/plugins/surge/front/surge.form.php?id=12'],
    'text' => '⚡ Surge detected: "Outlook won\'t open" — 9 tickets · Mail — …',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</pre></details>";
echo "</div></div></div></div>";
echo "<div class='mt-3'><button class='btn btn-primary'><i class='ti ti-device-floppy'></i> " . Ui::e(__('Save', 'surge')) . "</button></div></form>";
Ui::footer();
