<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * On-call rota: who is e-mailed when a surge is detected, by day and time.
 */

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\OnCall;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::CONFIG);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['delete'])) {
        OnCall::delete($id);
    } else {
        OnCall::save($id, $_POST);
    }
    Session::addMessageAfterRedirect(__('On-call rota saved.', 'surge'), false, INFO);
    Html::redirect(Ui::url('oncall'));
}

Ui::header('oncall', __('On-call', 'surge'));
$now = OnCall::current();
echo "<div class='alert " . ($now ? 'alert-success' : 'alert-secondary') . "'><i class='ti ti-phone-call me-1'></i>"
    . Ui::e($now ? sprintf(__('On call now: %s', 'surge'), implode(', ', array_map('getUserName', $now))) : __('Nobody is on call right now.', 'surge'))
    . (Config::get('notify_oncall') !== '1' ? ' — ' . Ui::e(__('notifying the on-call person is turned off in the settings', 'surge')) : '') . "</div>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('On-call rota', 'surge')) . "</h3></div><div class='card-body'>"
    . "<p class='small text-muted'>" . Ui::e(__('When a surge is detected, the people on call at that moment receive an e-mail (with the recipients set in the settings). A slot ending before it starts runs overnight, e.g. 18:00 – 08:00.', 'surge')) . "</p>"
    . "<div class='row g-2 small text-muted fw-bold d-none d-lg-flex border-bottom pb-1'><div class='col-lg-2'>" . Ui::e(__('Technician', 'surge')) . "</div><div class='col-lg-5'>"
    . Ui::e(__('Days', 'surge')) . "</div><div class='col-lg-2'>" . Ui::e(__('Hours', 'surge')) . "</div><div class='col-lg-2'>" . Ui::e(__('Comment', 'surge')) . "</div><div class='col-lg-1'></div></div>";
foreach (array_merge(OnCall::all(), [[]]) as $s) {
    $id = (int) ($s['id'] ?? 0);
    echo "<form method='post' action='" . Ui::e(Ui::url('oncall')) . "' class='row g-2 align-items-center py-2 border-bottom" . ($id ? '' : ' bg-light-subtle') . "'>" . Ui::csrf() . Html::hidden('id', ['value' => $id]);
    echo "<div class='col-lg-2'>";
    User::dropdown(['name' => 'users_id', 'value' => (int) ($s['users_id'] ?? 0), 'right' => 'all', 'width' => '100%']);
    echo "</div><div class='col-lg-5 text-nowrap'>";
    foreach (OnCall::days() as $bit => $name) {
        echo "<label class='form-check form-check-inline me-1 mb-0'><input class='form-check-input' type='checkbox' name='days[]' value='{$bit}'"
            . (((int) ($s['days'] ?? 127)) & $bit ? ' checked' : '') . "><span class='form-check-label'>" . Ui::e($name) . "</span></label>";
    }
    echo "</div><div class='col-lg-2 d-flex align-items-center gap-1'><input class='form-control form-control-sm' name='time_start' value='" . Ui::e($s['time_start'] ?? '00:00') . "' aria-label='" . Ui::e(__('From', 'surge')) . "'>–"
        . "<input class='form-control form-control-sm' name='time_end' value='" . Ui::e($s['time_end'] ?? '24:00') . "' aria-label='" . Ui::e(__('To', 'surge')) . "'></div>"
        . "<div class='col-lg-2'><input class='form-control form-control-sm' name='comment' value='" . Ui::e($s['comment'] ?? '') . "'></div>"
        . "<div class='col-lg-1 d-flex align-items-center gap-1'><input type='hidden' name='is_active' value='0'>"
        . "<label class='form-check form-switch m-0' title='" . Ui::e(__('Active', 'surge')) . "'><input class='form-check-input' type='checkbox' name='is_active' value='1'" . (($s['is_active'] ?? 1) ? ' checked' : '') . "></label>"
        . "<button class='btn btn-sm " . ($id ? 'btn-outline-primary' : 'btn-primary') . "' name='save' value='1' title='" . Ui::e($id ? __('Save', 'surge') : __('Add', 'surge')) . "'><i class='ti " . ($id ? 'ti-device-floppy' : 'ti-plus') . "'></i></button>"
        . ($id ? "<button class='btn btn-sm btn-ghost-danger' name='delete' value='1' title='" . Ui::e(__('Delete', 'surge')) . "'><i class='ti ti-trash'></i></button>" : '')
        . "</div>";
    echo "</form>";
}
echo "</div></div>";
Ui::footer();
