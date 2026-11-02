<?php

/**
 * Surge 0.2.0 — tests for notices, statistics, on-call rota, e-mail, semantic similarity and languages.
 *   php surge_v2_test.php [iq|g12]          (needs tests/embed_mock.py listening on 127.0.0.1:18082)
 * Test data is named "S2·…" and removed at the end.
 */
$env = $argv[1] ?? 'iq';
require __DIR__ . ($env === 'g12' ? '/g12_boot.php' : '/iq_boot.php');
iq_login(2);
Session::changeActiveEntities('all');

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Detector;
use GlpiPlugin\Surge\Embeddings;
use GlpiPlugin\Surge\Mailer;
use GlpiPlugin\Surge\OnCall;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;
use GlpiPlugin\Surge\Text;

/** @var DBmysql $DB */
global $DB, $CFG_GLPI;
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
$cfg0 = Config::DEFAULTS;
$others = array_column(iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => Rule::TABLE, 'WHERE' => ['is_active' => 1]])), 'id');
$made = ['tickets' => [], 'surges' => [], 'rules' => [], 'oncall' => [], 'cats' => []];
$mk = static function (string $name, int $cat) use (&$made): int {
    $id = (int) (new Ticket())->add(['name' => 'S2· ' . $name, 'content' => $name, 'entities_id' => 0, 'itilcategories_id' => $cat, '_users_id_requester' => 2]);
    $made['tickets'][] = $id;
    return $id;
};
try {
    if ($others) {
        $DB->update(Rule::TABLE, ['is_active' => 0], ['id' => $others]);
    }

    // --- languages
    $ok(Text::tokens('Ça ne marche pas: ÉCRAN noir') === ['marche', 'ecran', 'noir'], 'French: accents folded, stop words dropped', Text::tokens('Ça ne marche pas: ÉCRAN noir'));
    $ok(Text::tokens('Der Drucker funktioniert nicht') === ['drucke', 'funkti'], 'German', Text::tokens('Der Drucker funktioniert nicht'));
    $ok(Text::tokens('No puedo iniciar sesión') === ['puedo', 'inicia', 'sesion'], 'Spanish', Text::tokens('No puedo iniciar sesión'));
    $ok(Text::tokens('Não consigo acessar o e-mail') === ['consig', 'acessa', 'mail'], 'Portuguese', Text::tokens('Não consigo acessar o e-mail'));
    $ok(Text::tokens('İNTERNET YOK') === ['intern', 'yok'], 'Turkish still folded (İ before lower-casing)', Text::tokens('İNTERNET YOK'));

    // --- on-call rota
    $mon10 = strtotime('2026-11-02 10:00:00'); // a Monday
    $ok(date('N', $mon10) === '1', 'fixture date is a Monday');
    $slot = ['is_active' => 1, 'days' => 31, 'time_start' => '18:00', 'time_end' => '08:00'];
    $ok(!OnCall::covers($slot, $mon10), 'weekday night slot: not at Monday 10:00');
    $ok(OnCall::covers($slot, strtotime('2026-11-02 22:00:00')), 'weekday night slot: Monday 22:00');
    $ok(OnCall::covers($slot, strtotime('2026-11-03 07:30:00')), 'weekday night slot: Tuesday 07:30 (Monday night continues)');
    $ok(!OnCall::covers($slot, strtotime('2026-11-08 07:30:00')), 'weekday night slot: not Sunday 07:30 (Saturday is not in the slot)');
    $ok(OnCall::covers(['is_active' => 1, 'days' => 96, 'time_start' => '00:00', 'time_end' => '24:00'], strtotime('2026-11-07 15:00:00')), 'weekend full-day slot');
    $ok(!OnCall::covers(['is_active' => 0, 'days' => 127, 'time_start' => '00:00', 'time_end' => '24:00'], $mon10), 'inactive slot never covers');
    $made['oncall'][] = OnCall::save(0, ['users_id' => 4, 'days' => [1, 2, 4, 8, 16, 32, 64], 'time_start' => '00:00', 'time_end' => '24:00', 'is_active' => 1, 'comment' => 'S2']);
    $ok(in_array(4, OnCall::current(), true), 'rota saved; user 4 is on call now');
    $ok(OnCall::describe(OnCall::all()[0]) !== '', 'rota description');

    // --- e-mail recipients and queue
    $mailOn = [$CFG_GLPI['use_notifications'] ?? 0, $CFG_GLPI['notifications_mailing'] ?? 0];
    $CFG_GLPI['use_notifications'] = 1;
    $CFG_GLPI['notifications_mailing'] = 1;
    $u4 = (string) UserEmail::getDefaultForUser(4);
    $addMail = $u4 === '';
    if ($addMail) {
        (new UserEmail())->add(['users_id' => 4, 'email' => 's2-oncall@example.com', 'is_default' => 1]);
    }
    Config::save(['notify_users' => [], 'notify_groups' => [], 'notify_oncall' => '1']);
    $rec = Mailer::recipients();
    $ok(isset($rec[4]), 'on-call user is a recipient', $rec);
    Config::save(['notify_oncall' => '0']);
    $ok(!isset(Mailer::recipients()[4]), 'on-call notification can be turned off');
    Config::save(['notify_oncall' => '1']);

    // --- detection with notice, mail, stats
    $cat = (int) (new ITILCategory())->add(['name' => 'S2 Mail', 'entities_id' => 0, 'is_recursive' => 1]);
    $made['cats'][] = $cat;
    $rid = Rule::save(0, ['name' => 'S2 rule', 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'window_minutes' => 60, 'min_tickets' => 4,
        'similarity' => 0.3, 'match_category' => 1, 'match_title' => 1, 'categories' => []]);
    $made['rules'][] = $rid;
    $q0 = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_queuednotifications', 'WHERE' => ['event' => 'surge_detected']])->current()['c'];
    foreach (['Outlook won\'t open', 'Cannot open Outlook', 'Outlook not opening', 'outlook does not open'] as $t) {
        $mk($t, $cat);
    }
    $sid = (int) ($DB->request(['SELECT' => 'id', 'FROM' => Surge::TABLE, 'WHERE' => ['rules_id' => $rid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current()['id'] ?? 0);
    $made['surges'][] = $sid;
    $ok($sid > 0, 'surge detected');
    $q1 = (int) $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_queuednotifications', 'WHERE' => ['event' => 'surge_detected']])->current()['c'];
    $ok($q1 > $q0, 'detection e-mail queued in GLPI notification queue (' . ($q1 - $q0) . ')');
    $qn = $DB->request(['FROM' => 'glpi_queuednotifications', 'WHERE' => ['event' => 'surge_detected'], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
    $ok(str_contains((string) $qn['name'], '[Surge]') && in_array($qn['recipient'], [$u4, 's2-oncall@example.com'], true), 'e-mail subject and on-call recipient', [$qn['name'], $qn['recipient']]);

    // notice
    $ok(Surge::notices() === [] || !in_array($sid, array_column(Surge::notices(), 'id'), true), 'no notice before publishing');
    Surge::publishNotice($sid, "S2· We know about the mail outage.\nNext update at 10:30.");
    $nt = array_values(array_filter(Surge::notices(), static fn($x) => $x['id'] === $sid));
    $ok(count($nt) === 1 && str_contains($nt[0]['message'], 'mail outage') && !$nt[0]['resolved'], 'published notice is visible', $nt);
    $ok(!str_contains(json_encode(Surge::notices()), 'Outlook won'), 'notice exposes no ticket titles');
    // with a master ticket the members are solved by the cascade; they must still be counted
    $members = (int) Surge::get($sid)['ticket_count'];
    $made['tickets'][] = Surge::createMaster($sid, 'S2· [Major incident] mail');
    [$done] = Surge::resolveAll($sid, '<p>S2· fixed</p>');
    $ok($done === $members, 'solve all with a master counts the members solved by the cascade', [$done, $members]);
    $nt = array_values(array_filter(Surge::notices(), static fn($x) => $x['id'] === $sid));
    $ok(count($nt) === 1 && $nt[0]['resolved'] && str_contains($nt[0]['message'], 'mail outage') && !str_contains($nt[0]['message'], 'Outlook'), 'resolved surge shows the agent text as "resolved" for a while', $nt);
    Config::save(['notice_resolved_hours' => '0']);
    $ok(!in_array($sid, array_column(Surge::notices(), 'id'), true), 'resolved notice hidden when the grace period is 0');
    Config::save(['notice_resolved_hours' => '2']);
    Surge::unpublishNotice($sid);
    $ok(!in_array($sid, array_column(Surge::notices(), 'id'), true), 'withdrawn notice disappears');
    try {
        Surge::publishNotice($sid, '   ');
        $ok(false, 'empty notice must be refused');
    } catch (RuntimeException $e) {
        $ok(true, 'empty notice refused');
    }

    // stats
    $st = Surge::stats(30);
    $ok($st['surges'] >= 1 && $st['tickets'] >= 4 && $st['status'][Surge::RESOLVED] >= 1, 'statistics count the surge', $st);
    $ok($st['detect_min'] !== null && count($st['weeks']) >= 4, 'statistics: time to detect and weekly series');

    // --- semantic similarity (mock server)
    $cat2 = (int) (new ITILCategory())->add(['name' => 'S2 Access', 'entities_id' => 0, 'is_recursive' => 1]);
    $made['cats'][] = $cat2;
    $DB->update(Rule::TABLE, ['is_active' => 0], ['id' => $rid]);
    $rid2 = Rule::save(0, ['name' => 'S2 semantic', 'is_active' => 1, 'entities_id' => 0, 'is_recursive' => 1, 'window_minutes' => 60, 'min_tickets' => 4,
        'similarity' => 0.4, 'match_category' => 1, 'match_title' => 1, 'categories' => []]);
    $made['rules'][] = $rid2;
    Config::save(['embed_enabled' => '0']);
    $para = ['Cannot log in to the VPN', 'Remote access authentication failed', 'VPN login denied', 'Unable to sign in remotely', 'Tunnel credentials error'];
    foreach ($para as $t) {
        $mk($t, $cat2);
    }
    $s2 = (int) ($DB->request(['SELECT' => 'id', 'FROM' => Surge::TABLE, 'WHERE' => ['rules_id' => $rid2]])->current()['id'] ?? 0);
    $ok($s2 === 0, 'paraphrases without shared words: no surge with words only');
    Config::save(['embed_enabled' => '1', 'embed_url' => 'http://127.0.0.1:18082/v1', 'embed_model' => 's2-mock', 'embed_weight' => '0.8', 'embed_key' => 'S2-secret']);
    $ok(Config::secret('embed_key') === 'S2-secret' && Config::get('embed_key') !== 'S2-secret', 'API key stored encrypted');
    Config::save(['embed_key' => '']);
    $ok(Config::secret('embed_key') === 'S2-secret', 'empty key field keeps the stored key');
    $v = Embeddings::fetch(['VPN login', 'remote authentication']);
    $ok($v !== null && count($v) === 2 && Embeddings::cosine(Embeddings::norm($v[0]), Embeddings::norm($v[1])) > 0.9, 'mock endpoint: paraphrases are close');
    Detector::reset();
    Detector::run();
    $s2 = (int) ($DB->request(['SELECT' => 'id', 'FROM' => Surge::TABLE, 'WHERE' => ['rules_id' => $rid2]])->current()['id'] ?? 0);
    $made['surges'][] = $s2;
    $ok($s2 > 0 && count(Surge::ticketIds($s2)) === 5, 'with embeddings the 5 paraphrases form one surge', $s2 ? Surge::ticketIds($s2) : 0);
    $cached = (int) $DB->request(['COUNT' => 'c', 'FROM' => Embeddings::TABLE, 'WHERE' => ['tickets_id' => $made['tickets'], 'model' => 's2-mock']])->current()['c'];
    $ok($cached >= 5, "vectors cached per ticket ($cached)");
    Config::save(['embed_url' => 'http://127.0.0.1:1/v1']);
    $ok(Embeddings::fetch(['x']) === null && Embeddings::$error !== null, 'unreachable endpoint → null + error, detection falls back to words');
    $ok(is_array(Detector::run()), 'detection still runs with the endpoint down');
} finally {
    foreach ($made['tickets'] as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    $sids = array_filter($made['surges']);
    if ($sids) {
        $masters = array_filter(array_column(iterator_to_array($DB->request(['SELECT' => 'tickets_id_master', 'FROM' => Surge::TABLE, 'WHERE' => ['id' => $sids]])), 'tickets_id_master'));
        foreach ($masters as $m) {
            (new Ticket())->delete(['id' => $m], true);
        }
        $DB->delete(Surge::ITEMS, ['surges_id' => $sids]);
        $DB->delete(Surge::TABLE, ['id' => $sids]);
    }
    if ($made['tickets']) {
        $DB->delete(Embeddings::TABLE, ['tickets_id' => $made['tickets']]);
    }
    foreach ($made['rules'] as $r) {
        Rule::delete($r);
    }
    foreach ($made['oncall'] as $o) {
        OnCall::delete($o);
    }
    foreach ($made['cats'] as $c) {
        (new ITILCategory())->delete(['id' => $c], true);
    }
    $DB->delete('glpi_queuednotifications', ['event' => 'surge_detected', 'name' => ['LIKE', '%S2·%']]);
    if (!empty($addMail)) {
        $DB->delete('glpi_useremails', ['users_id' => 4, 'email' => 's2-oncall@example.com']);
    }
    if (isset($mailOn)) {
        [$CFG_GLPI['use_notifications'], $CFG_GLPI['notifications_mailing']] = $mailOn;
    }
    if ($others) {
        $DB->update(Rule::TABLE, ['is_active' => 1], ['id' => $others]);
    }
    Config::save($cfg0 + ['embed_key_clear' => '1']);
}
echo "\n$n test, $bad sorun\n";
exit($bad ? 1 : 0);
