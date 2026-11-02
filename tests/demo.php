<?php

/**
 * Surge — demo data for screenshots on a TEST instance: two outages (mail, floor-3 network) plus everyday noise.
 *   php demo.php [iq|g12] [create|clean]
 * Created ticket ids are kept in surge_demo_ids.json next to this script so "clean" can remove them.
 */
$env = $argv[1] ?? 'iq';
require __DIR__ . ($env === 'g12' ? '/g12_boot.php' : '/iq_boot.php');
iq_login(2);
Session::changeActiveEntities('all');

use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Surge;

/** @var DBmysql $DB */
global $DB;

$store = __DIR__ . '/surge_demo_ids.json';
$clean = static function () use ($DB, $store): void {
    $ids = is_file($store) ? array_map('intval', (array) json_decode((string) file_get_contents($store), true)) : [];
    $sids = $ids ? array_unique(array_column(iterator_to_array($DB->request(['SELECT' => 'surges_id', 'FROM' => Surge::ITEMS, 'WHERE' => ['tickets_id' => $ids]])), 'surges_id')) : [];
    $masters = $sids ? array_filter(array_column(iterator_to_array($DB->request(['SELECT' => 'tickets_id_master', 'FROM' => Surge::TABLE, 'WHERE' => ['id' => $sids]])), 'tickets_id_master')) : [];
    foreach (array_merge($ids, $masters) as $id) {
        (new Ticket())->delete(['id' => $id], true);
    }
    if ($sids) {
        $DB->delete(Surge::ITEMS, ['surges_id' => $sids]);
        $DB->delete(Surge::TABLE, ['id' => $sids]);
    }
    @unlink($store);
    foreach (['glpi_itilcategories' => ['Email', 'Network'], 'glpi_locations' => ['HQ · Floor 3']] as $tb => $names) {
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => $tb, 'WHERE' => ['name' => $names, 'comment' => 'surge-demo']]) as $r) {
            ($tb === 'glpi_locations' ? new Location() : new ITILCategory())->delete(['id' => $r['id']], true);
        }
    }
    foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['LIKE', 'surgedemo.%']]]) as $r) {
        (new User())->delete(['id' => $r['id']], true);
    }
};
$clean();
if (($argv[2] ?? 'create') === 'clean') {
    echo "cleaned\n";
    exit;
}

$mail = (int) (new ITILCategory())->add(['name' => 'Email', 'entities_id' => 0, 'is_recursive' => 1, 'comment' => 'surge-demo']);
$net = (int) (new ITILCategory())->add(['name' => 'Network', 'entities_id' => 0, 'is_recursive' => 1, 'comment' => 'surge-demo']);
$floor = (int) (new Location())->add(['name' => 'HQ · Floor 3', 'entities_id' => 0, 'is_recursive' => 1, 'comment' => 'surge-demo']);
// demo requesters (Self-Service on the root entity), removed by "clean"
$selfService = (int) ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'helpdesk'], 'LIMIT' => 1])->current()['id'] ?? 0);
$users = [];
foreach (['Emma Clarke', 'Liam Walker', 'Sofia Rossi', 'Noah Becker', 'Mia Laurent', 'Lucas Silva', 'Ava Novak', 'Ethan Brooks'] as $full) {
    [$first, $last] = explode(' ', $full);
    $uid = (int) (new User())->add(['name' => 'surgedemo.' . strtolower($first), 'firstname' => $first, 'realname' => $last, 'is_active' => 1,
        '_profiles_id' => $selfService, '_entities_id' => 0, '_is_recursive' => 1]);
    if ($uid > 0 && $selfService > 0 && !$DB->request(['COUNT' => 'c', 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $uid]])->current()['c']) {
        (new Profile_User())->add(['users_id' => $uid, 'profiles_id' => $selfService, 'entities_id' => 0, 'is_recursive' => 1]);
    }
    $users[] = $uid;
}
$i = 0;
$mk = static function (string $name, int $cat, int $loc, string $content) use ($users, &$i): int {
    $i++;
    return (int) (new Ticket())->add(['name' => $name, 'content' => '<p>' . $content . '</p>', 'entities_id' => 0, 'itilcategories_id' => $cat, 'locations_id' => $loc,
        '_users_id_requester' => $users[$i % max(1, count($users))] ?? 2, 'urgency' => 3]);
};
$plan = [
    // [minutes ago, title, category, location, description]
    [41, "Outlook won't open", $mail, 0, 'Outlook shows a loading screen and closes.'],
    [39, 'Printer on floor 2 out of toner', 0, 0, 'Please send a new toner cartridge.'],
    [38, 'Cannot open Outlook', $mail, 0, 'Outlook does not start since this morning.'],
    [36, 'Outlook keeps crashing at start', $mail, 0, 'Outlook crashes right after the splash screen.'],
    [35, 'Email not syncing in Outlook', $mail, 0, 'No new email in Outlook for 20 minutes.'],
    [33, 'Outlook not opening — urgent', $mail, 0, 'I have a meeting and Outlook will not open.'],
    [32, 'New laptop for the intern', 0, 0, 'Our intern starts on Monday.'],
    [31, "Outlook won't start", $mail, 0, 'Outlook stuck on "Loading profile".'],
    [29, 'outlook is not opening', $mail, 0, 'outlook not working'],
    [27, 'Outlook stuck on loading profile', $mail, 0, 'Restarted twice, still stuck.'],
    [24, 'No internet on floor 3', $net, $floor, 'No connection on my desk PC.'],
    [23, 'Wi-Fi down on floor 3', $net, $floor, 'The Wi-Fi network is not visible.'],
    [22, 'Outlook error: cannot start Microsoft Outlook', $mail, 0, 'Error message: Cannot start Microsoft Outlook.'],
    [21, 'Internet not working (3rd floor)', $net, $floor, 'Websites do not load.'],
    [20, 'Password reset for the HR portal', 0, 0, 'I forgot my HR portal password.'],
    [19, 'No network connection floor 3', $net, $floor, 'Network cable connected but no internet.'],
    [17, 'Floor 3: internet down', $net, $floor, 'Whole team on floor 3 is offline.'],
    [15, 'Internet outage on the third floor', $net, $floor, 'Nothing loads, Teams disconnected.'],
    [12, 'Outlook closes immediately', $mail, 0, 'It opens and closes immediately.'],
    [8, 'Monitor flickering', 0, 0, 'My second monitor flickers.'],
];
$made = [];
foreach ($plan as [$ago, $name, $cat, $loc, $txt]) {
    $made[$mk($name, $cat, $loc, $txt)] = $ago;
}
file_put_contents($store, json_encode(array_keys($made)));
// spread the arrival times like a real morning, then refresh the surges' first / last dates
foreach ($made as $id => $ago) {
    $DB->update('glpi_tickets', ['date' => date('Y-m-d H:i:s', time() - $ago * 60), 'date_creation' => date('Y-m-d H:i:s', time() - $ago * 60)], ['id' => $id]);
}
foreach (array_unique(array_column(iterator_to_array($DB->request(['SELECT' => 'surges_id', 'FROM' => Surge::ITEMS, 'WHERE' => ['tickets_id' => array_keys($made)]])), 'surges_id')) as $sid) {
    Surge::refresh((int) $sid);
    echo "surge #$sid: " . Surge::get((int) $sid)['name'] . ' — ' . Surge::get((int) $sid)['ticket_count'] . " tickets\n";
}
echo count($made) . " demo tickets, rules active: " . count(Rule::all(true)) . "\n";
