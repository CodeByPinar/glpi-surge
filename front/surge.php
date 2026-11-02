<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Surges list: live ones first, then history (filter by status).
 */

use GlpiPlugin\Surge\Detector;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::READ, Profile::MANAGE, Profile::CONFIG);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['detect'])) {
    Ui::need(Profile::MANAGE, Profile::CONFIG);
    $r = Detector::run();
    Session::addMessageAfterRedirect(sprintf(__('Detection finished: %1$d new surge(s), %2$d ticket(s) added to existing ones.', 'surge'), count($r['created']), array_sum($r['grown'])), false, INFO);
    Html::redirect(Ui::url('surge'));
}

/** @var DBmysql $DB */
global $DB;
$filter = (string) ($_GET['status'] ?? 'open');
$where = match ($filter) {
    'all'   => [],
    'open'  => ['status' => [Surge::NEW, Surge::ACTIVE]],
    default => isset(Surge::statuses()[$filter]) ? ['status' => $filter] : ['status' => [Surge::NEW, Surge::ACTIVE]],
};
$where += getEntitiesRestrictCriteria(Surge::TABLE, '', '', false);

Ui::header('surge', __('Surges', 'surge'));

$counts = [];
foreach ($DB->request(['SELECT' => ['status', new \Glpi\DBAL\QueryExpression('COUNT(*) AS n')], 'FROM' => Surge::TABLE, 'WHERE' => getEntitiesRestrictCriteria(Surge::TABLE, '', '', false), 'GROUPBY' => 'status']) as $r) {
    $counts[$r['status']] = (int) $r['n'];
}
echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'><div class='btn-group'>";
$opts = ['open' => [__('Open', 'surge'), ($counts[Surge::NEW] ?? 0) + ($counts[Surge::ACTIVE] ?? 0)]];
foreach (Surge::statuses() as $k => [$label]) {
    $opts[$k] = [$label, $counts[$k] ?? 0];
}
$opts['all'] = [__('All', 'surge'), array_sum($counts)];
foreach ($opts as $k => [$label, $n]) {
    echo "<a class='btn btn-sm " . ($filter === $k ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . Ui::e(Ui::url('surge', ['status' => $k])) . "'>" . Ui::e($label) . " <span class='badge ms-1'>" . (int) $n . "</span></a>";
}
echo "</div>";
if (Profile::has(Profile::MANAGE) || Profile::has(Profile::CONFIG)) {
    echo "<form method='post' class='ms-auto' action='" . Ui::e(Ui::url('surge')) . "'>" . Ui::csrf()
        . "<button class='btn btn-sm btn-outline-primary' name='detect' value='1'><i class='ti ti-radar'></i> " . Ui::e(__('Run detection now', 'surge')) . "</button></form>";
}
echo "</div>";

$rows = iterator_to_array($DB->request(['FROM' => Surge::TABLE, 'WHERE' => $where, 'ORDER' => ['date_last DESC'], 'LIMIT' => 200]));
if (!$rows) {
    $rules = count(Rule::all(true));
    echo "<div class='card'><div class='card-body text-center py-5 text-muted'><i class='ti ti-bolt-off' style='font-size:2.5rem'></i><p class='mt-2 mb-1'>"
        . Ui::e(__('No surge here. All quiet.', 'surge')) . "</p><p class='small'>"
        . Ui::e(sprintf(_n('%d active detection rule.', '%d active detection rules.', $rules, 'surge'), $rules)) . "</p></div></div>";
    Ui::footer();
    return;
}
echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table surge-list'><thead><tr>"
    . "<th>" . Ui::e(__('Surge', 'surge')) . "</th><th>" . Ui::e(__('Status', 'surge')) . "</th><th class='text-end'>" . Ui::e(__('Tickets', 'surge')) . "</th>"
    . "<th>" . Ui::e(__('Arrivals', 'surge')) . "</th><th>" . Ui::e(__('First / last ticket', 'surge')) . "</th><th>" . Ui::e(__('Category / location', 'surge')) . "</th>"
    . "<th>" . Ui::e(__('Master ticket / problem', 'surge')) . "</th></tr></thead><tbody>";
foreach ($rows as $s) {
    $cl = array_filter([Ui::name('glpi_itilcategories', (int) $s['itilcategories_id']), Ui::name('glpi_locations', (int) $s['locations_id'])]);
    $links = [];
    if ((int) $s['tickets_id_master'] > 0) {
        $links[] = "<a href='" . Ui::e(Ticket::getFormURLWithID((int) $s['tickets_id_master'])) . "'>#" . (int) $s['tickets_id_master'] . "</a>";
    }
    if ((int) $s['problems_id'] > 0) {
        $links[] = "<a href='" . Ui::e(Problem::getFormURLWithID((int) $s['problems_id'])) . "'>" . Ui::e(Problem::getTypeName(1)) . " " . (int) $s['problems_id'] . "</a>";
    }
    echo "<tr class='surge-row-" . Ui::e($s['status']) . "'><td><a class='fw-bold' href='" . Ui::e(Ui::url('surge.form', ['id' => $s['id']])) . "'>#" . (int) $s['id'] . " " . Ui::e($s['name']) . "</a></td>"
        . "<td>" . Ui::status((string) $s['status']) . "</td><td class='text-end fw-bold'>" . (int) $s['ticket_count'] . "</td>"
        . "<td>" . Ui::spark(Surge::histogram((int) $s['id'])) . "</td>"
        . "<td class='small text-nowrap'>" . Ui::e(Ui::dt($s['date_first'])) . "<br>" . Ui::e(Ui::dt($s['date_last'])) . "</td>"
        . "<td class='small'>" . Ui::e(implode(' · ', $cl) ?: '—') . "</td><td>" . (implode(' · ', $links) ?: '—') . "</td></tr>";
}
echo "</tbody></table></div></div>";
Ui::footer();
