<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Statistics: how many surges, how many tickets they absorbed, time to detect / resolve, top categories and locations,
 * surges per week.
 */

use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Surge;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::READ, Profile::MANAGE, Profile::CONFIG);
$days = (int) ($_GET['days'] ?? 90);
$days = in_array($days, [30, 90, 180, 365], true) ? $days : 90;
$st = Surge::stats($days);

Ui::header('stats', __('Statistics', 'surge'));
echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'><span class='text-muted'>" . Ui::e(__('Period', 'surge')) . "</span><div class='btn-group'>";
foreach ([30, 90, 180, 365] as $d) {
    echo "<a class='btn btn-sm " . ($d === $days ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . Ui::e(Ui::url('stats', ['days' => $d])) . "'>" . Ui::e(sprintf(__('%d days', 'surge'), $d)) . "</a>";
}
echo "</div></div>";

$fmtMin = static fn(?float $m): string => $m === null ? '—' : ($m < 1 ? __('< 1 min', 'surge') : sprintf(__('%d min', 'surge'), round($m)));
$fmtH = static fn(?float $h): string => $h === null ? '—' : ($h < 1 ? sprintf(__('%d min', 'surge'), max(1, round($h * 60))) : sprintf(__('%.1f h', 'surge'), $h));
$card = static fn(string $label, string $value, string $icon, string $hint = ''): string => "<div class='col-6 col-xl-2'><div class='card card-sm h-100'><div class='card-body'>"
    . "<div class='d-flex align-items-center gap-2 text-muted small'><i class='{$icon}'></i>" . Ui::e($label) . "</div><div class='h2 mb-0 mt-1'>" . Ui::e($value) . "</div>"
    . ($hint !== '' ? "<div class='text-muted small'>" . Ui::e($hint) . "</div>" : '') . "</div></div></div>";
echo "<div class='row g-3 mb-3'>"
    . $card(__('Surges', 'surge'), (string) $st['surges'], 'ti ti-bolt')
    . $card(__('Tickets absorbed', 'surge'), (string) $st['tickets'], 'ti ti-ticket', sprintf(__('%.1f per surge', 'surge'), $st['avg_tickets']))
    . $card(__('Time to detect', 'surge'), $fmtMin($st['detect_min']), 'ti ti-radar', __('median, from the first ticket', 'surge'))
    . $card(__('Time to resolve', 'surge'), $fmtH($st['resolve_h']), 'ti ti-clock-check', __('median, from the first ticket', 'surge'))
    . $card(__('Handled as an incident', 'surge'), round($st['handled_pct']) . ' %', 'ti ti-sitemap', __('master ticket or problem', 'surge'))
    . $card(__('Dismissed', 'surge'), round($st['dismissed_pct']) . ' %', 'ti ti-x', __('false positives', 'surge'))
    . "</div>";

echo "<div class='row g-3'><div class='col-lg-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Surges per week', 'surge')) . "</h3></div><div class='card-body'>";
$max = max(1, ...array_values($st['weeks'] ?: [0]));
echo "<div class='surge-bars'>";
foreach ($st['weeks'] as $wk => $n) {
    echo "<div class='" . ($n ? '' : 'zero') . "' style='height:" . max(2, round($n / $max * 100)) . "%' title='" . Ui::e($wk . ': ' . $n) . "'></div>";
}
echo "</div><div class='d-flex justify-content-between text-muted small mt-1'><span>" . Ui::e((string) array_key_first($st['weeks'])) . "</span><span>" . Ui::e((string) array_key_last($st['weeks'])) . "</span></div>";
echo "<div class='d-flex flex-wrap gap-2 mt-3'>";
foreach ($st['status'] as $k => $n) {
    echo Ui::status($k) . " <span class='me-2'>" . (int) $n . "</span>";
}
echo "</div></div></div></div>";

echo "<div class='col-lg-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Top categories', 'surge')) . "</h3></div><div class='card-body'>";
if (!$st['categories']) {
    echo "<p class='text-muted mb-0'>" . Ui::e(__('No surge in this period.', 'surge')) . "</p>";
}
$cmax = max(1, ...array_map(static fn($c) => $c['tickets'], $st['categories'] ?: [['tickets' => 0]]));
foreach ($st['categories'] as $cid => $c) {
    $name = $cid > 0 ? Ui::name('glpi_itilcategories', (int) $cid) : __('(no category)', 'surge');
    echo "<div class='mb-2'><div class='d-flex justify-content-between small'><span>" . Ui::e($name) . "</span><span class='text-muted'>"
        . Ui::e(sprintf(__('%1$d surge(s) · %2$d tickets', 'surge'), $c['surges'], $c['tickets'])) . "</span></div>"
        . "<div class='surge-hbar' style='width:" . max(2, round($c['tickets'] / $cmax * 100)) . "%'></div></div>";
}
if ($st['locations']) {
    echo "<h4 class='mt-4 mb-2'>" . Ui::e(__('Top locations', 'surge')) . "</h4>";
    foreach ($st['locations'] as $lid => $n) {
        echo "<div class='d-flex justify-content-between small'><span>" . Ui::e(Ui::name('glpi_locations', (int) $lid)) . "</span><span class='text-muted'>" . (int) $n . "</span></div>";
    }
}
echo "</div></div></div></div>";
Ui::footer();
