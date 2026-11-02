<?php

/**
 * Surge 0.3.0 — tests for the extension points (surge_event, surge_tabs, surge_page).
 *   php surge_v3_test.php [iq|g12]
 * Test data is named "S3·…" and removed at the end. Listeners are registered under the key "surge" (an active plugin).
 */
$env = $argv[1] ?? 'iq';
require __DIR__ . ($env === 'g12' ? '/g12_boot.php' : '/iq_boot.php');
iq_login(2);
Session::changeActiveEntities('all');

use GlpiPlugin\Surge\Events;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;

/** @var DBmysql $DB */
global $DB, $PLUGIN_HOOKS;
$n = 0;
$bad = 0;
$ok = static function (bool $c, string $m, $show = null) use (&$n, &$bad): void {
    $n++;
    $bad += $c ? 0 : 1;
    echo ($c ? '  ok  ' : 'SORUN ') . $m . "\n";
    if (!$c && $show !== null) {
        echo '      ' . (is_string($show) ? $show : json_encode($show, JSON_UNESCAPED_UNICODE)) . "\n";
    }
};
$seen = [];
$PLUGIN_HOOKS[Events::EVENT]['surge'] = static function (array $p) use (&$seen): void {
    $seen[] = $p;
};
$events = static function () use (&$seen): array {
    return array_column($seen, 'event');
};
$others = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Rule::TABLE, 'WHERE' => ['is_active' => 1]])), 'id');
$made = ['tickets' => [], 'surges' => [], 'rules' => [], 'cats' => []];
try {
    if ($others) {
        $DB->update(Rule::TABLE, ['is_active' => 0], ['id' => $others]);
    }
    $cat = (int) (new ITILCategory())->add(['name' => 'S3 Mail', 'entities_id' => 0, 'is_recursive' => 1]);
    $made['cats'][] = $cat;
    $made['rules'][] = $rid = Rule::save(0, ['name' => 'S3 rule', 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'window_minutes' => 60,
        'min_tickets' => 3, 'similarity' => 0.3, 'match_category' => 1, 'match_title' => 1, 'categories' => []]);
    foreach (["Outlook won't open", 'Cannot open Outlook', 'Outlook not opening', 'outlook does not open'] as $t) {
        $made['tickets'][] = (int) (new Ticket())->add(['name' => 'S3· ' . $t, 'content' => $t, 'entities_id' => 0, 'itilcategories_id' => $cat, '_users_id_requester' => 2]);
    }
    $sid = (int) ($DB->request(['SELECT' => 'id', 'FROM' => Surge::TABLE, 'WHERE' => ['rules_id' => $rid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current()['id'] ?? 0);
    $made['surges'][] = $sid;
    $ok($sid > 0 && in_array('detected', $events(), true), 'detected event', $events());
    $det = array_values(array_filter($seen, static fn($p) => $p['event'] === 'detected'))[0] ?? [];
    $ok(($det['surges_id'] ?? 0) === $sid && isset($det['users_id']), 'event carries surges_id and users_id', $det);
    $ok(in_array('ticket_joined', $events(), true), 'ticket_joined event when the 4th ticket joins the live surge', $events());

    $seen = [];
    Surge::acknowledge($sid);
    $ok($events() === ['acknowledged'], 'acknowledged event', $events());
    $seen = [];
    $master = Surge::createMaster($sid, 'S3· [Major incident] mail');
    $ok(in_array('master', $events(), true) && (array_values(array_filter($seen, static fn($p) => $p['event'] === 'master'))[0]['tickets_id'] ?? 0) === $master, 'master event with the master ticket id', $seen);
    $seen = [];
    Surge::broadcast($sid, '<p>S3· we know</p>');
    $ok($events() === ['broadcast'] && $seen[0]['done'] >= 3, 'broadcast event with the number of follow-ups', $seen);
    $seen = [];
    Surge::publishNotice($sid, 'S3· notice');
    Surge::unpublishNotice($sid);
    $ok($events() === ['notice_published', 'notice_withdrawn'], 'notice events', $events());
    $seen = [];
    Surge::setStatus($sid, Surge::DISMISSED);
    Surge::setStatus($sid, Surge::ACTIVE);
    $ok($events() === ['dismissed', 'reopened'], 'dismissed / reopened events', $events());
    $seen = [];
    Surge::removeTicket($sid, $made['tickets'][0]);
    $ok($events() === ['ticket_removed'] && $seen[0]['tickets_id'] === $made['tickets'][0], 'ticket_removed event', $seen);
    $seen = [];
    (new ITILSolution())->add(['itemtype' => 'Ticket', 'items_id' => $master, 'content' => '<p>S3· fixed</p>']);
    $ok(in_array('resolved', $events(), true) && !empty(array_values(array_filter($seen, static fn($p) => $p['event'] === 'resolved'))[0]['via_master']), 'resolved event from the master cascade', $seen);

    // a failing listener is logged, never fatal
    $PLUGIN_HOOKS[Events::EVENT]['surge'] = static function (): void {
        throw new RuntimeException('S3· boom');
    };
    $threw = false;
    try {
        Surge::acknowledge($sid);
        Events::fire('custom', $sid);
    } catch (\Throwable $e) {
        $threw = true;
    }
    $ok(!$threw, 'a failing listener does not break Surge');

    // tabs and page slots
    $PLUGIN_HOOKS[Events::TABS]['surge'] = static function (array $tabs): array {
        $tabs['s3'] = ['S3 tab', 'ti ti-star', '/plugins/other/front/page.php'];
        return $tabs;
    };
    $tabs = Events::tabs(['surge' => ['Surges', 'ti ti-bolt']]);
    $ok(isset($tabs['surge'], $tabs['s3']) && $tabs['s3'][2] === '/plugins/other/front/page.php', 'surge_tabs filter adds a tab with its own URL', $tabs);
    $PLUGIN_HOOKS[Events::TABS]['surge'] = static fn($t) => 'not an array';
    $ok(Events::tabs(['a' => ['A', 'x']]) === ['a' => ['A', 'x']], 'a broken tabs filter keeps the original tabs');
    $PLUGIN_HOOKS[Events::PAGE]['surge'] = static function (array $p): void {
        echo '[S3 ' . $p['slot'] . ' #' . $p['surge']['id'] . ']';
    };
    ob_start();
    Events::page('side', Surge::get($sid));
    $out = ob_get_clean();
    $ok($out === "[S3 side #$sid]", 'surge_page slot renders listener output', $out);
    $made['tickets'][] = $master;
} finally {
    unset($PLUGIN_HOOKS[Events::EVENT]['surge'], $PLUGIN_HOOKS[Events::TABS]['surge'], $PLUGIN_HOOKS[Events::PAGE]['surge']);
    foreach ($made['tickets'] as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    $sids = array_filter($made['surges']);
    if ($sids) {
        $DB->delete(Surge::ITEMS, ['surges_id' => $sids]);
        $DB->delete(Surge::TABLE, ['id' => $sids]);
    }
    foreach ($made['rules'] as $r) {
        Rule::delete($r);
    }
    foreach ($made['cats'] as $c) {
        (new ITILCategory())->delete(['id' => $c], true);
    }
    if ($others) {
        $DB->update(Rule::TABLE, ['is_active' => 1], ['id' => $others]);
    }
}
echo "\n$n test, $bad sorun\n";
exit($bad ? 1 : 0);
