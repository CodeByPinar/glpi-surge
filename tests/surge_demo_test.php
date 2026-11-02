<?php

/**
 * Surge 0.3.1 — tests for the demo data (Settings → Demo data): load, content, refuse twice, remove everything.
 *   php surge_demo_test.php [iq|g12]
 */
$env = $argv[1] ?? 'iq';
require __DIR__ . ($env === 'g12' ? '/g12_boot.php' : '/iq_boot.php');
iq_login(2);
Session::changeActiveEntities(0);

use GlpiPlugin\Surge\Config;
use GlpiPlugin\Surge\Demo;
use GlpiPlugin\Surge\OnCall;
use GlpiPlugin\Surge\Surge;

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
$count = static fn(string $t, array $w): int => (int) $DB->request(['COUNT' => 'c', 'FROM' => $t, 'WHERE' => $w])->current()['c'];

foreach (['tr_TR' => 'Outlook açılmıyor', 'en_GB' => "Outlook won't open"] as $lang => $first) {
    $_SESSION['glpilanguage'] = $lang;
    if (Demo::loaded()) {
        Demo::clean();
    }
    try {
        $r = Demo::create();
        $d = json_decode(Config::get('demo_data'), true);
        $ok($r['tickets'] === 20 && count($d['tickets']) === 20, "$lang: 20 tickets", $r);
        $ok($count('glpi_tickets', ['id' => $d['tickets'], 'name' => $first]) >= 1, "$lang: texts in the user's language");
        $ok($r['surges'] >= 2, "$lang: the two outages are detected", $r);
        $live = iterator_to_array($DB->request(['FROM' => Surge::TABLE, 'WHERE' => ['id' => $d['surges'], 'status' => [Surge::NEW, Surge::ACTIVE]]]));
        $net = array_values(array_filter($live, static fn($s) => (int) $s['tickets_id_master'] > 0));
        $ok(count($live) >= 2 && count($net) === 1 && (int) $net[0]['is_public'] === 1 && (int) $net[0]['ticket_count'] === 6, "$lang: network outage has a master ticket and a public notice", $net);
        $mail = array_values(array_filter($live, static fn($s) => (int) $s['tickets_id_master'] === 0));
        $ok($mail && (int) $mail[0]['ticket_count'] >= 6, "$lang: mail outage grouped (the exact count depends on the instance's word statistics)", $mail);
        $ok($count(Surge::TABLE, ['id' => $d['surges'], 'keywords' => json_encode([Demo::TAG])]) === 16, "$lang: 16 past surges for the statistics");
        $ok(count($d['oncall']) === 3 && count(OnCall::all()) >= 3, "$lang: on-call rota");
        $ok(count($d['users']) === 8 && $count('glpi_users', ['id' => $d['users'], 'is_active' => 1]) === 8, "$lang: 8 demo users");
        $ok((Surge::stats(90)['surges'] ?? 0) >= 16, "$lang: statistics have data");
        try {
            Demo::create();
            $ok(false, "$lang: second load refused");
        } catch (RuntimeException $e) {
            $ok(true, "$lang: second load refused");
        }
        Demo::clean();
        $ok(!Demo::loaded(), "$lang: cleaned flag");
        $ok($count('glpi_tickets', ['id' => array_merge($d['tickets'], $d['masters'])]) === 0, "$lang: tickets and master removed");
        $ok($count(Surge::TABLE, ['id' => $d['surges']]) === 0 && $count(Surge::ITEMS, ['surges_id' => $d['surges']]) === 0, "$lang: surges removed");
        $ok($count('glpi_users', ['id' => $d['users']]) === 0, "$lang: demo users removed");
        $ok($count('glpi_itilcategories', ['id' => $d['cats']]) === 0 && $count('glpi_locations', ['id' => $d['locations']]) === 0, "$lang: categories and location removed");
        $ok($count(OnCall::TABLE, ['id' => $d['oncall']]) === 0 && $count('glpi_plugin_surge_rules', ['id' => $d['rules']]) === 0, "$lang: on-call slots and demo rule removed");
    } finally {
        if (Demo::loaded()) {
            Demo::clean();
        }
    }
}
echo "\n$n test, $bad sorun\n";
exit($bad ? 1 : 0);
