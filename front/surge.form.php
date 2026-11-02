<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * One surge: summary, arrival chart, member tickets and the actions (acknowledge, master ticket, problem, broadcast,
 * resolve all, dismiss / reopen, remove a ticket).
 */

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::READ, Profile::MANAGE, Profile::CONFIG);
$id = (int) ($_REQUEST['id'] ?? 0);
$s = Surge::get($id);
if (!$s || !Session::haveAccessToEntity((int) $s['entities_id'])) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$self = Ui::url('surge.form', ['id' => $id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Ui::need(Profile::MANAGE);
    $text = trim((string) ($_POST['text'] ?? ''));
    $html = $text !== '' ? '<p>' . nl2br(htmlspecialchars($text)) . '</p>' : '';
    try {
        switch ((string) ($_POST['do'] ?? '')) {
            case 'ack':
                Surge::acknowledge($id);
                break;
            case 'master':
                $tid = Surge::createMaster($id, trim((string) ($_POST['title'] ?? '')));
                Session::addMessageAfterRedirect(sprintf(__('Master ticket #%d created and linked.', 'surge'), $tid), false, INFO);
                break;
            case 'problem':
                $pid = Surge::createProblem($id);
                Session::addMessageAfterRedirect(sprintf(__('Problem %d created and linked.', 'surge'), $pid), false, INFO);
                break;
            case 'broadcast':
                if ($html === '') {
                    throw new RuntimeException(__('Write the message first.', 'surge'));
                }
                [$n, $skip] = Surge::broadcast($id, $html);
                Session::addMessageAfterRedirect(sprintf(__('Follow-up added to %d ticket(s).', 'surge'), $n) . ($skip ? ' ' . sprintf(__('%d ticket(s) skipped (no right).', 'surge'), $skip) : ''), false, INFO);
                break;
            case 'resolve':
                if ($html === '') {
                    throw new RuntimeException(__('Write the solution first.', 'surge'));
                }
                [$n, $skip] = Surge::resolveAll($id, $html);
                Session::addMessageAfterRedirect(sprintf(__('%d ticket(s) solved.', 'surge'), $n) . ($skip ? ' ' . sprintf(__('%d ticket(s) skipped (no right).', 'surge'), $skip) : ''), false, INFO);
                break;
            case 'publish':
                Surge::publishNotice($id, (string) ($_POST['text'] ?? ''));
                Session::addMessageAfterRedirect(__('Notice published on the self-service portal.', 'surge'), false, INFO);
                break;
            case 'unpublish':
                Surge::unpublishNotice($id);
                break;
            case 'dismiss':
                Surge::setStatus($id, Surge::DISMISSED);
                break;
            case 'reopen':
                Surge::setStatus($id, Surge::ACTIVE);
                break;
            case 'remove':
                Surge::removeTicket($id, (int) ($_POST['tickets_id'] ?? 0));
                break;
        }
    } catch (RuntimeException $e) {
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            throw $e;
        }
        Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
    }
    Html::redirect($self);
}

$s = Surge::get($id);
$manage = Profile::has(Profile::MANAGE);
$open = in_array($s['status'], [Surge::NEW, Surge::ACTIVE], true);
$rule = Rule::get((int) $s['rules_id']);
$form = static fn(string $do, string $inner, string $cls = ''): string => "<form method='post' action='" . Ui::e(Ui::url('surge.form')) . "' class='{$cls}'>"
    . Ui::csrf() . Html::hidden('id', ['value' => $id]) . Html::hidden('do', ['value' => $do]) . $inner . "</form>";

Ui::header('surge', '#' . $id . ' ' . $s['name']);

echo "<div class='d-flex flex-wrap align-items-center gap-2 mb-3'><h3 class='m-0'>#" . $id . " " . Ui::e($s['name']) . "</h3>" . Ui::status((string) $s['status']);
if ($manage) {
    echo "<div class='ms-auto d-flex gap-2'>";
    if ($s['status'] === Surge::NEW) {
        echo $form('ack', "<button class='btn btn-sm btn-outline-primary'><i class='ti ti-eye-check'></i> " . Ui::e(__('Acknowledge', 'surge')) . "</button>");
    }
    echo $open
        ? $form('dismiss', "<button class='btn btn-sm btn-outline-secondary' onclick=\"return confirm(" . Ui::e(json_encode(__('Not a real incident? The tickets stay as they are.', 'surge'))) . ")\"><i class='ti ti-x'></i> " . Ui::e(__('Dismiss', 'surge')) . "</button>")
        : $form('reopen', "<button class='btn btn-sm btn-outline-secondary'><i class='ti ti-refresh'></i> " . Ui::e(__('Reopen', 'surge')) . "</button>");
    echo "</div>";
}
echo "</div>";

// summary cards
$mins = max(1, (int) round((strtotime((string) $s['date_last']) - strtotime((string) $s['date_first'])) / 60));
$kw = (array) json_decode((string) $s['keywords'], true);
$card = static fn(string $label, string $value, string $icon): string => "<div class='col-6 col-lg-3'><div class='card card-sm h-100'><div class='card-body d-flex gap-3 align-items-center'>"
    . "<span class='avatar bg-red-lt'><i class='{$icon}'></i></span><div><div class='h3 m-0'>{$value}</div><div class='text-muted small'>" . Ui::e($label) . "</div></div></div></div></div>";
echo "<div class='row g-3 mb-3'>"
    . $card(__('Tickets', 'surge'), (string) (int) $s['ticket_count'], 'ti ti-ticket')
    . $card(__('Time span', 'surge'), Ui::e(sprintf(__('%d min', 'surge'), $mins)), 'ti ti-clock')
    . $card(__('Category / location', 'surge'), Ui::e(implode(' · ', array_filter([Ui::name('glpi_itilcategories', (int) $s['itilcategories_id']), Ui::name('glpi_locations', (int) $s['locations_id'])])) ?: '—'), 'ti ti-tags')
    . $card(__('Detected by', 'surge'), Ui::e($rule['name'] ?? '—'), 'ti ti-filter')
    . "</div>";

echo "<div class='row g-3'><div class='col-xl-8'>";
// arrival chart + keywords
echo "<div class='card mb-3'><div class='card-body'><div class='d-flex flex-wrap gap-4 align-items-end'><div><div class='text-muted small mb-1'>" . Ui::e(__('Arrivals', 'surge')) . " — "
    . Ui::e(Ui::dt($s['date_first'])) . " → " . Ui::e(Ui::dt($s['date_last'])) . "</div>" . Ui::spark(Surge::histogram($id, 24), 360, 56) . "</div>";
if ($kw) {
    echo "<div><div class='text-muted small mb-1'>" . Ui::e(__('Common words', 'surge')) . "</div>" . implode(' ', array_map(static fn($k) => "<span class='badge bg-azure-lt'>" . Ui::e($k) . "</span>", array_slice($kw, 0, 8))) . "</div>";
}
echo "</div></div></div>";

// member tickets
/** @var DBmysql $DB */
global $DB;
$items = $DB->request([
    'SELECT' => ['i.tickets_id', 'i.score', 'i.is_removed', 't.name', 't.date', 't.status', 't.locations_id'],
    'FROM'   => Surge::ITEMS . ' AS i',
    'LEFT JOIN' => ['glpi_tickets AS t' => ['ON' => ['i' => 'tickets_id', 't' => 'id']]],
    'WHERE'  => ['i.surges_id' => $id],
    'ORDER'  => ['i.is_removed', 't.date'],
]);
echo "<div class='card'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Tickets', 'surge')) . "</h3></div><div class='table-responsive'><table class='table table-sm table-vcenter card-table'><thead><tr>"
    . "<th>" . Ui::e(__('Ticket', 'surge')) . "</th><th>" . Ui::e(__('Requester', 'surge')) . "</th><th>" . Ui::e(__('Opened', 'surge')) . "</th><th>" . Ui::e(__('Status', 'surge')) . "</th>"
    . "<th class='text-end' title='" . Ui::e(__('Text similarity to the surge', 'surge')) . "'>" . Ui::e(__('Match', 'surge')) . "</th><th></th></tr></thead><tbody>";
foreach ($items as $r) {
    $t = new Ticket();
    $t->getFromDB((int) $r['tickets_id']);
    $req = $t->isNewItem() ? [] : array_map(static fn($u) => getUserName((int) $u['users_id']), $t->getUsers(CommonITILActor::REQUESTER));
    echo "<tr" . ($r['is_removed'] ? " class='text-muted' style='text-decoration:line-through'" : '') . "><td><a href='" . Ui::e(Ticket::getFormURLWithID((int) $r['tickets_id'])) . "'>#" . (int) $r['tickets_id'] . " " . Ui::e($r['name']) . "</a></td>"
        . "<td class='small'>" . Ui::e(implode(', ', $req)) . "</td><td class='small text-nowrap'>" . Ui::e(Ui::dt($r['date'])) . "</td>"
        . "<td class='small'>" . ($r['status'] !== null ? Ticket::getStatusIcon((int) $r['status']) . ' ' . Ui::e(Ticket::getStatus((int) $r['status'])) : '') . "</td>"
        . "<td class='text-end small'>" . round((float) $r['score'] * 100) . " %</td><td class='text-end'>";
    if ($manage && !$r['is_removed'] && $open) {
        echo $form('remove', Html::hidden('tickets_id', ['value' => (int) $r['tickets_id']]) . "<button class='btn btn-sm btn-ghost-secondary' title='" . Ui::e(__('Not part of this incident', 'surge')) . "'><i class='ti ti-unlink'></i></button>", 'd-inline');
    }
    echo "</td></tr>";
}
echo "</tbody></table></div></div></div>";

// actions
echo "<div class='col-xl-4'>";
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-sitemap me-1'></i>" . Ui::e(__('Major incident', 'surge')) . "</h3></div><div class='card-body'>";
if ((int) $s['tickets_id_master'] > 0) {
    echo "<p class='mb-2'><i class='ti ti-ticket'></i> " . Ui::e(__('Master ticket', 'surge')) . ": <a class='fw-bold' href='" . Ui::e(Ticket::getFormURLWithID((int) $s['tickets_id_master'])) . "'>#" . (int) $s['tickets_id_master'] . "</a></p>";
} elseif ($manage && $open) {
    echo $form('master', "<label class='form-label'>" . Ui::e(__('Master ticket title', 'surge')) . "</label><input class='form-control mb-2' name='title' value='" . Ui::e(sprintf(__('[Major incident] %s', 'surge'), $s['name'])) . "'>"
        . "<button class='btn btn-primary w-100'><i class='ti ti-plus'></i> " . Ui::e(__('Create master ticket and link all', 'surge')) . "</button>"
        . "<div class='form-text'>" . Ui::e(__('Every ticket of the surge becomes a child of the master. New tickets joining the surge are linked automatically.', 'surge')) . "</div>", 'mb-3');
}
if ((int) $s['problems_id'] > 0) {
    echo "<p class='mb-0'><i class='ti ti-bug'></i> " . Ui::e(Problem::getTypeName(1)) . ": <a class='fw-bold' href='" . Ui::e(Problem::getFormURLWithID((int) $s['problems_id'])) . "'>" . (int) $s['problems_id'] . "</a></p>";
} elseif ($manage && $open) {
    echo $form('problem', "<button class='btn btn-outline-primary w-100'><i class='ti ti-bug'></i> " . Ui::e(__('Create a problem and link all', 'surge')) . "</button>");
}
if (!$manage && !(int) $s['tickets_id_master'] && !(int) $s['problems_id']) {
    echo "<p class='text-muted mb-0'>—</p>";
}
echo "</div></div>";
// public notice for end users
if ($manage && ($open || $s['is_public'])) {
    $pub = (int) $s['is_public'] === 1;
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-world me-1'></i>" . Ui::e(__('Notice for end users', 'surge')) . "</h3>"
        . ($pub ? "<span class='badge bg-green-lt ms-auto'>" . Ui::e(__('Published', 'surge')) . "</span>" : '') . "</div><div class='card-body'>"
        . "<p class='small text-muted'>" . Ui::e(__('Shown at the top of the self-service portal for users of the same entities, so they do not open the same ticket again. Ticket details are never shown.', 'surge')) . "</p>";
    if ($open) {
        echo $form('publish', "<textarea class='form-control mb-2' name='text' rows='3'>" . Ui::e($pub ? $s['public_message'] : Surge::defaultNotice($s)) . "</textarea>"
            . "<button class='btn btn-outline-primary w-100'><i class='ti ti-world-upload'></i> " . Ui::e($pub ? __('Update notice', 'surge') : __('Publish notice', 'surge')) . "</button>", 'mb-2');
    }
    if ($pub) {
        echo $form('unpublish', "<button class='btn btn-ghost-secondary w-100'><i class='ti ti-world-off'></i> " . Ui::e(__('Withdraw notice', 'surge')) . "</button>");
        if (!$open) {
            echo "<div class='form-text'>" . Ui::e(sprintf(__('Shown as "resolved" for %d hour(s) after resolution.', 'surge'), Config::int('notice_resolved_hours'))) . "</div>";
        }
    }
    echo "</div></div>";
}
if ($manage && $open) {
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-speakerphone me-1'></i>" . Ui::e(__('Tell everyone', 'surge')) . "</h3></div><div class='card-body'>"
        . $form('broadcast', "<textarea class='form-control mb-2' name='text' rows='3' placeholder='" . Ui::e(__('e.g. We are aware of the mail outage and working on it. Next update at 10:30.', 'surge')) . "'></textarea>"
            . "<button class='btn btn-outline-primary w-100'><i class='ti ti-send'></i> " . Ui::e(__('Add as public follow-up to all open tickets', 'surge')) . "</button>")
        . "</div></div>";
    echo "<div class='card'><div class='card-header'><h3 class='card-title'><i class='ti ti-circle-check me-1'></i>" . Ui::e(__('Resolve', 'surge')) . "</h3></div><div class='card-body'>"
        . $form('resolve', "<textarea class='form-control mb-2' name='text' rows='3' placeholder='" . Ui::e(__('e.g. The mail server was restarted at 10:12; mail works again.', 'surge')) . "'></textarea>"
            . "<button class='btn btn-success w-100' onclick=\"return confirm(" . Ui::e(json_encode(__('Solve every open ticket of this surge with this solution?', 'surge'))) . ")\"><i class='ti ti-check'></i> " . Ui::e(__('Solve all tickets', 'surge')) . "</button>"
            . "<div class='form-text'>" . Ui::e(__('Solving the master ticket in GLPI does the same automatically.', 'surge')) . "</div>")
        . "</div></div>";
}
if ($s['users_id_ack']) {
    echo "<p class='text-muted small mt-3'>" . Ui::e(sprintf(__('Acknowledged by %1$s on %2$s', 'surge'), getUserName((int) $s['users_id_ack']), Ui::dt($s['date_ack']))) . "</p>";
}
echo "</div></div>";
Ui::footer();
