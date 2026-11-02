<?php

/**
 * Surge — integration tests on a GLPI test instance (NOT production).
 *   php surge_test.php [iq|g12] [clean]
 * Creates a test category / location / rule and tickets prefixed "ST·", checks detection and every action, then cleans up.
 */
$env = $argv[1] ?? 'iq';
require __DIR__ . ($env === 'g12' ? '/g12_boot.php' : '/iq_boot.php');
iq_login(2);
Session::changeActiveEntities('all');

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Detector;
use GlpiPlugin\Surge\Notifier;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;
use GlpiPlugin\Surge\Text;

/** @var DBmysql $DB */
global $DB;
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

$clean = static function () use ($DB): void {
    $tids = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', 'ST·%']]])), 'id');
    $tids = array_merge($tids, array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', '%[ST-MAJOR]%']]])), 'id'));
    foreach ($tids as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_problems', 'WHERE' => ['name' => ['LIKE', 'ST·%']]]) as $r) {
        (new Problem())->delete(['id' => $r['id']], true);
    }
    $rid = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Rule::TABLE, 'WHERE' => ['name' => 'ST rule']])), 'id');
    if ($rid) {
        $sids = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Surge::TABLE, 'WHERE' => ['rules_id' => $rid]])), 'id');
        if ($sids) {
            $DB->delete(Surge::ITEMS, ['surges_id' => $sids]);
            $DB->delete(Surge::TABLE, ['id' => $sids]);
        }
        $DB->delete(Rule::TABLE, ['id' => $rid]);
    }
    foreach (['glpi_itilcategories' => ['ST Mail', 'ST Network'], 'glpi_locations' => ['ST Floor 3']] as $tb => $names) {
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => $tb, 'WHERE' => ['name' => $names]]) as $r) {
            $cls = $tb === 'glpi_locations' ? new Location() : new ITILCategory();
            $cls->delete(['id' => $r['id']], true);
        }
    }
};
$clean();
if (($argv[2] ?? '') === 'clean') {
    echo "temizlendi\n";
    exit;
}

// --- text
$ok(Text::normalize('İNTERNET YOK Şubede') === 'internet yok subede', 'normalize: Turkish case + diacritics', Text::normalize('İNTERNET YOK Şubede'));
$ok(Text::tokens('Bağlanamıyorum, bağlantı yok!') === ['baglan', 'baglan', 'yok'], 'tokens: stem merges suffixes', Text::tokens('Bağlanamıyorum, bağlantı yok!'));
$ok(Text::tokens('Hello, please help: the printer error') === ['printe'], 'tokens: stop words dropped', Text::tokens('Hello, please help: the printer error'));
$v = Text::vectors([1 => Text::terms('Outlook açılmıyor', ''), 2 => Text::terms('OUTLOOK ACILMIYOR!!', ''), 3 => Text::terms('Yazıcı çıktı vermiyor', '')]);
$ok(Text::cosine($v[1], $v[2]) > 0.99 && Text::cosine($v[1], $v[3]) < 0.01, 'cosine: same complaint ≈ 1, different ≈ 0');
$c = Text::cluster([1 => ['a' => 1.0], 2 => ['a' => 0.8, 'b' => 0.6], 3 => ['b' => 1.0], 4 => ['z' => 1.0]], 0.5);
$ok(count($c) === 2 && in_array([1, 2, 3], array_map(static fn($g) => (sort($g) ? $g : $g), $c), true), 'cluster: single linkage chains 1-2-3, 4 alone', $c);

// --- fixture
$cat = (int) (new ITILCategory())->add(['name' => 'ST Mail', 'entities_id' => 0, 'is_recursive' => 1]);
$cat2 = (int) (new ITILCategory())->add(['name' => 'ST Network', 'entities_id' => 0, 'is_recursive' => 1]);
$loc = (int) (new Location())->add(['name' => 'ST Floor 3', 'entities_id' => 0, 'is_recursive' => 1]);
$others = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Rule::TABLE, 'WHERE' => ['is_active' => 1]])), 'id');
if ($others) {
    $DB->update(Rule::TABLE, ['is_active' => 0], ['id' => $others]); // only the test rule runs
}
try {
    $rid = Rule::save(0, ['name' => 'ST rule', 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'window_minutes' => 60, 'min_tickets' => 5,
        'similarity' => 0.3, 'match_category' => 1, 'match_location' => 0, 'match_title' => 1, 'ticket_type' => 0, 'categories' => []]);
    $mk = static function (string $name, int $c, string $content = '') use ($loc): int {
        return (int) (new Ticket())->add(['name' => 'ST· ' . $name, 'content' => $content ?: $name, 'entities_id' => 0, 'itilcategories_id' => $c,
            'locations_id' => $loc, '_users_id_requester' => 2]);
    };
    $outlook = [];
    foreach (['Outlook açılmıyor', 'Outlook açılmıyor, mail alamıyorum', 'Outlook programı açılmıyor', 'OUTLOOK AÇILMIYOR!!', 'outlook acilmiyor yardim'] as $i => $t) {
        $outlook[] = $mk($t, $cat);
        if ($i === 1) {
            $mk('Yazıcı çıktı vermiyor', $cat); // noise in the same category
        }
    }
    $mk('Toplantı odası rezervasyonu', $cat);
    $vpn = [$mk('VPN bağlanamıyorum', $cat2), $mk('VPN bağlantı hatası', $cat2)]; // similar but below the threshold

    $sid = (int) ($DB->request(['SELECT' => 'id', 'FROM' => Surge::TABLE, 'WHERE' => ['rules_id' => $rid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current()['id'] ?? 0);
    $ok($sid > 0, 'surge detected on ticket creation (no cron needed)');
    $ids = Surge::ticketIds($sid);
    sort($ids);
    $ok($ids === $outlook, 'surge holds exactly the 5 Outlook tickets (noise and VPN left out)', ['got' => $ids, 'want' => $outlook]);
    $s = Surge::get($sid);
    $ok((int) $s['ticket_count'] === 5 && (int) $s['itilcategories_id'] === $cat && (int) $s['locations_id'] === $loc && $s['status'] === Surge::NEW, 'surge fields: count, category, common location, status new', $s);
    $ok(str_contains(mb_strtolower((string) $s['name']), 'outlook'), 'surge named after its most central ticket: ' . $s['name']);
    $ok(in_array('outlook', (array) json_decode((string) $s['keywords'], true), true), 'keywords are readable words (outlook, not the stem)', $s['keywords']);
    $ok(array_sum(Surge::histogram($sid)) === 5, 'arrival histogram counts 5');

    // late ticket joins the live surge
    $late = $mk('Outlook açılmıyor hâlâ', $cat);
    $ok(in_array($late, Surge::ticketIds($sid), true) && (int) Surge::get($sid)['ticket_count'] === 6, 'late similar ticket joins the existing surge');
    $r = Detector::run();
    $ok($r['created'] === [] && (int) Surge::get($sid)['ticket_count'] === 6, 'running detection again changes nothing (idempotent)', $r);

    // master ticket
    $mid = Surge::createMaster($sid, 'ST· [ST-MAJOR] Mail outage');
    $links = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_tickets_tickets', 'WHERE' => ['tickets_id_2' => $mid, 'link' => CommonITILObject_CommonITILObject::SON_OF]])->current()['c'];
    $ok($mid > 0 && $links === 6, "master ticket #$mid created, 6 children linked as 'son of'", $links);
    $ok(!in_array($mid, Surge::ticketIds($sid), true), 'master ticket is not itself a member');
    $s = Surge::get($sid);
    $ok($s['status'] === Surge::ACTIVE && (int) $s['users_id_ack'] === 2, 'surge acknowledged (status in progress)');
    $late2 = $mk('Outlook mail açılmıyor', $cat);
    $ok(in_array($late2, Surge::ticketIds($sid), true) && (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_tickets_tickets', 'WHERE' => ['tickets_id_1' => $late2, 'tickets_id_2' => $mid]])->current()['c'] === 1, 'ticket arriving after the master is linked to it automatically');

    // problem
    $pid = Surge::createProblem($sid);
    $DB->update('glpi_problems', ['name' => 'ST· problem'], ['id' => $pid]);
    $pl = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_problems_tickets', 'WHERE' => ['problems_id' => $pid]])->current()['c'];
    $ok($pid > 0 && $pl === 8, "problem $pid linked to 7 members + master", $pl);

    // remove a false member
    Surge::removeTicket($sid, $late2);
    $ok(!in_array($late2, Surge::ticketIds($sid), true) && (int) Surge::get($sid)['ticket_count'] === 6, 'removed ticket leaves the surge');
    Detector::run();
    $ok(!in_array($late2, Surge::ticketIds($sid), true), 'removed ticket is not re-added by detection');

    // broadcast
    [$done] = Surge::broadcast($sid, '<p>ST· We are on it.</p>');
    $fu = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => Surge::ticketIds($sid), 'is_private' => 0, 'content' => ['LIKE', '%We are on it%']]])->current()['c'];
    $ok($done === 6 && $fu === 6, 'broadcast: public follow-up on the 6 open tickets', [$done, $fu]);

    // webhook payload (dry run)
    Config::save(['webhook_url' => '', 'webhook_format' => 'slack'] + Config::DEFAULTS);
    $p = Notifier::send('detected', $sid, true);
    $ok(isset($p['text']) && str_contains($p['text'], '6 tickets'), 'webhook payload (Slack)', $p);
    Config::save(['webhook_format' => 'generic'] + Config::DEFAULTS);
    $p = Notifier::send('master', $sid, true);
    $ok(($p['surge']['master_ticket'] ?? 0) === $mid && $p['event'] === 'master', 'webhook payload (generic JSON)', $p);
    $ok(Notifier::send('detected', $sid) === null, 'no webhook URL → nothing sent');

    // cascade: solving the master solves every member
    (new ITILSolution())->add(['itemtype' => 'Ticket', 'items_id' => $mid, 'content' => '<p>ST· Mail server restarted.</p>']);
    $open = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => Surge::ticketIds($sid), 'status' => Ticket::getNotSolvedStatusArray()]])->current()['c'];
    $ok($open === 0 && Surge::get($sid)['status'] === Surge::RESOLVED, 'solving the master solves all members and resolves the surge', $open);
    $ok(Surge::forTicket($outlook[0])['role'] === 'member' && Surge::forTicket($mid)['role'] === 'master', 'forTicket: member / master roles');

    // rule input cleaning
    $c = Rule::clean(['name' => '', 'min_tickets' => 1, 'window_minutes' => 99999, 'similarity' => '0,5', 'ticket_type' => 7, 'categories' => ['3', 'x', '0']]);
    $ok($c['min_tickets'] === 2 && $c['window_minutes'] === 1440 && $c['similarity'] === 0.5 && $c['ticket_type'] === 0 && $c['categories'] === '[3]', 'rule input is bounded and cleaned', $c);
} finally {
    if ($others) {
        $DB->update(Rule::TABLE, ['is_active' => 1], ['id' => $others]);
    }
    Config::save(Config::DEFAULTS);
    if (($argv[2] ?? '') !== 'keep') {
        $clean();
    }
}
echo "\n$n test, $bad sorun\n";
exit($bad ? 1 : 0);
