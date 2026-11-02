<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Settings: handling, notifications (e-mail + on-call), public notices, webhook, optional semantic similarity.
 */

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Embeddings;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::CONFIG);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = $_POST;
    // multi-selects send nothing when emptied
    $in['notify_users'] = $_POST['notify_users'] ?? [];
    $in['notify_groups'] = $_POST['notify_groups'] ?? [];
    Config::save($in);
    if (isset($_POST['test_embed'])) {
        $t = microtime(true);
        $v = Embeddings::fetch(['Outlook does not open', 'Cannot start the mail client']);
        if ($v && count($v) === 2) {
            $a = Embeddings::norm($v[0]);
            $b = Embeddings::norm($v[1]);
            Session::addMessageAfterRedirect(sprintf(__('Embedding server OK: %1$d dimensions, %2$.1f s. Similarity of two paraphrases: %3$d %%.', 'surge'),
                count($a), microtime(true) - $t, round(Embeddings::cosine($a, $b) * 100)), false, INFO);
        } else {
            Session::addMessageAfterRedirect(sprintf(__('Embedding server error: %s', 'surge'), Embeddings::$error ?? '?'), false, ERROR);
        }
    } else {
        Session::addMessageAfterRedirect(__('Settings saved.', 'surge'), false, INFO);
    }
    Html::redirect(Ui::url('config'));
}

Ui::header('config', __('Settings', 'surge'));
// checkbox + hidden "0" so an unticked box is saved as off
$chk = static fn(string $n, string $label, string $help): string => "<input type='hidden' name='{$n}' value='0'><label class='form-check mb-3'><input class='form-check-input' type='checkbox' name='{$n}' value='1'" . (Config::get($n) === '1' ? ' checked' : '') . ">"
    . "<span class='form-check-label'>" . Ui::e($label) . "</span><span class='form-check-description small text-muted d-block'>" . Ui::e($help) . "</span></label>";
$sel = static function (string $n, array $opts): string {
    $h = "<select class='form-select' name='{$n}'>";
    foreach ($opts as $k => $l) {
        $h .= "<option value='" . Ui::e($k) . "'" . ((string) Config::get($n) === (string) $k ? ' selected' : '') . ">" . Ui::e($l) . "</option>";
    }
    return $h . "</select>";
};
$card = static fn(string $icon, string $title): string => "<div class='card h-100'><div class='card-header'><h3 class='card-title'><i class='{$icon} me-1'></i>" . Ui::e($title) . "</h3></div><div class='card-body'>";
$levels = [5 => __('Very high', 'surge'), 4 => __('High', 'surge'), 3 => __('Medium', 'surge'), 2 => __('Low', 'surge'), 1 => __('Very low', 'surge')];

echo "<form method='post' action='" . Ui::e(Ui::url('config')) . "'>" . Ui::csrf() . "<div class='row g-3'>";

// handling
echo "<div class='col-lg-6'>" . $card('ti ti-sitemap', __('Handling', 'surge'));
echo "<div class='row g-2 mb-3'><div class='col-6'><label class='form-label'>" . Ui::e(__('Master ticket urgency', 'surge')) . "</label>" . $sel('master_urgency', $levels) . "</div>"
    . "<div class='col-6'><label class='form-label'>" . Ui::e(__('Master ticket impact', 'surge')) . "</label>" . $sel('master_impact', $levels) . "</div></div>";
echo $chk('cascade_solution', __('Solving the master ticket solves every linked ticket', 'surge'), __('The same solution text is added to each open ticket of the surge; GLPI notifies the requesters as usual.', 'surge'));
echo $chk('link_new', __('Link late tickets to the master automatically', 'surge'), __('Tickets that join a surge after its master ticket was created become its children too.', 'surge'));
echo "<label class='form-label'>" . Ui::e(__('A surge keeps collecting tickets for (hours after its last ticket)', 'surge')) . "</label>"
    . "<input class='form-control' type='number' min='1' max='720' name='active_hours' value='" . Config::int('active_hours') . "'>";
echo "</div></div></div>";

// notifications
echo "<div class='col-lg-6'>" . $card('ti ti-mail', __('E-mail when a surge is detected', 'surge'));
echo "<label class='form-label'>" . Ui::e(__('Users', 'surge')) . "</label>";
User::dropdown(['name' => 'notify_users[]', 'value' => Config::ids('notify_users'), 'multiple' => true, 'right' => 'all', 'width' => '100%']);
echo "<label class='form-label mt-3'>" . Ui::e(__('Groups (all members)', 'surge')) . "</label>";
Group::dropdown(['name' => 'notify_groups[]', 'values' => Config::ids('notify_groups'), 'multiple' => true, 'width' => '100%', 'entity' => $_SESSION['glpiactiveentities'] ?? 0]);
echo "<div class='mt-3'>" . $chk('notify_oncall', __('Also e-mail whoever is on call', 'surge'), __('See the On-call tab. E-mails go through GLPI\'s notification queue and mail settings.', 'surge')) . "</div>";
echo "</div></div></div>";

// public notices
echo "<div class='col-lg-6'>" . $card('ti ti-world', __('Notices for end users', 'surge'));
echo "<p class='small text-muted'>" . Ui::e(__('A notice is published from a surge page ("Notice for end users"). It appears at the top of the self-service portal for users of the surge\'s entities.', 'surge')) . "</p>";
echo $chk('notice_central', __('Show notices to central-interface users too', 'surge'), __('By default only self-service users see them.', 'surge'));
echo "<label class='form-label'>" . Ui::e(__('After resolution, keep showing the notice as "resolved" for (hours)', 'surge')) . "</label>"
    . "<input class='form-control' type='number' min='0' max='72' name='notice_resolved_hours' value='" . Config::int('notice_resolved_hours') . "'>";
echo "</div></div></div>";

// webhook
echo "<div class='col-lg-6'>" . $card('ti ti-webhook', __('Webhook', 'surge'));
echo "<label class='form-label'>" . Ui::e(__('URL', 'surge')) . "</label><input class='form-control mb-3' name='webhook_url' placeholder='https://hooks.slack.com/services/…' value='" . Ui::e(Config::get('webhook_url')) . "'>";
echo "<label class='form-label'>" . Ui::e(__('Format', 'surge')) . "</label>" . $sel('webhook_format', ['generic' => __('Generic JSON', 'surge'), 'slack' => 'Slack', 'teams' => 'Microsoft Teams (workflow)']);
echo "<div class='form-text mt-2'>" . Ui::e(__('Sent when a surge is detected, gets a master ticket and is resolved. GLPI proxy settings are used. Leave empty to disable.', 'surge')) . "</div>";
echo "<details class='mt-3'><summary class='small'>" . Ui::e(__('Example payload', 'surge')) . "</summary><pre class='small mt-2 mb-0'>" . Ui::e(json_encode([
    'event' => 'detected',
    'surge' => ['id' => 12, 'name' => "Outlook won't open", 'status' => 'new', 'tickets' => 9, 'first' => '2026-11-02 09:14:00', 'last' => '2026-11-02 09:27:00', 'category' => 'Mail', 'location' => '', 'master_ticket' => null, 'problem' => null, 'url' => '…/plugins/surge/front/surge.form.php?id=12'],
    'text' => '⚡ Surge detected: "Outlook won\'t open" — 9 tickets · Mail — …',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . "</pre></details>";
echo "</div></div></div>";

// semantic similarity
echo "<div class='col-12'>" . $card('ti ti-brain', __('Semantic similarity (optional)', 'surge'));
echo "<p class='small text-muted'>" . Ui::e(__('Adds meaning-based matching on top of word matching, so "cannot log in to the VPN" and "remote access authentication failed" can end up together. Needs an OpenAI-compatible /v1/embeddings endpoint that you run yourself (Ollama, llama.cpp with --embeddings, LocalAI…). Ticket titles and the beginning of descriptions are sent to it; if it is unreachable, detection falls back to word matching.', 'surge')) . "</p>";
echo "<div class='row g-3'><div class='col-lg-3'>" . $chk('embed_enabled', __('Enabled', 'surge'), __('Off by default.', 'surge')) . "</div>"
    . "<div class='col-lg-4'><label class='form-label'>" . Ui::e(__('Endpoint', 'surge')) . "</label><input class='form-control' name='embed_url' placeholder='http://localhost:11434/v1' value='" . Ui::e(Config::get('embed_url')) . "'></div>"
    . "<div class='col-lg-2'><label class='form-label'>" . Ui::e(__('Model', 'surge')) . "</label><input class='form-control' name='embed_model' placeholder='nomic-embed-text' value='" . Ui::e(Config::get('embed_model')) . "'></div>"
    . "<div class='col-lg-2'><label class='form-label'>" . Ui::e(__('API key', 'surge')) . "</label><input class='form-control' type='password' name='embed_key' autocomplete='new-password' placeholder='"
    . Ui::e(Config::get('embed_key') !== '' ? __('stored — type to change', 'surge') : __('none', 'surge')) . "'>"
    . (Config::get('embed_key') !== '' ? "<label class='form-check small mt-1'><input class='form-check-input' type='checkbox' name='embed_key_clear' value='1'><span class='form-check-label'>" . Ui::e(__('Remove the stored key', 'surge')) . "</span></label>" : '') . "</div>"
    . "<div class='col-lg-1'><label class='form-label'>" . Ui::e(__('Weight', 'surge')) . "</label><input class='form-control' name='embed_weight' value='" . Ui::e(Config::get('embed_weight')) . "'></div></div>";
echo "<div class='form-text'>" . Ui::e(__('Weight: share of the semantic score in the final similarity (0 = words only, 1 = meaning only; 0.5 recommended).', 'surge')) . "</div>";
echo "<button class='btn btn-outline-primary btn-sm mt-3' name='test_embed' value='1'><i class='ti ti-plug-connected'></i> " . Ui::e(__('Save and test the connection', 'surge')) . "</button>";
echo "</div></div></div>";

echo "</div><div class='mt-3'><button class='btn btn-primary'><i class='ti ti-device-floppy'></i> " . Ui::e(__('Save', 'surge')) . "</button></div></form>";
Ui::footer();
