<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use ITILCategory;
use Location;
use Profile_User;
use Session;
use Ticket;
use User;

/**
 * Demo — sample data to see Surge at work (Settings → Demo data). Two outages (mail, floor-3 network) among everyday
 * tickets, a master ticket and a portal notice on the network outage, past surges for the statistics and an on-call
 * rota. Turkish texts when the user's language is Turkish, English otherwise.
 *
 * Everything created is remembered (setting "demo_data") and removed by clean(): tickets, master ticket, categories,
 * location, the demo users (no password, cannot log in), surges and on-call slots.
 */
final class Demo
{
    public const TAG = 'surge-demo';

    public static function loaded(): bool
    {
        return self::ids() !== [];
    }

    /** @return array<string,list<int>> */
    private static function ids(): array
    {
        $d = json_decode(Config::get('demo_data'), true);
        return is_array($d) ? $d : [];
    }

    private static function texts(): array
    {
        $tr = str_starts_with((string) ($_SESSION['glpilanguage'] ?? ''), 'tr');
        return $tr ? [
            'cats'     => ['E-posta', 'Ağ'],
            'location' => 'Genel Müdürlük · 3. Kat',
            'users'    => ['Elif Kaya', 'Mert Demir', 'Zeynep Arslan', 'Can Yıldız', 'Ayşe Çelik', 'Burak Şahin', 'Deniz Aydın', 'Ece Koç'],
            'rule'     => 'Örnek veri kuralı',
            'master'   => '[Büyük olay] 3. kat ağ kesintisi',
            'broadcast' => '3. kattaki ağ kesintisinden haberdarız. Bir anahtar (switch) değiştiriliyor; bir sonraki bilgilendirme 10:30’da.',
            'notice'   => '3. katta ağ kesintisi: haberdarız, bir anahtar (switch) değiştiriliyor. Yeni kayıt açmanıza gerek yok — bir sonraki bilgilendirme 10:30’da.',
            'oncall'   => ['hafta içi geceleri', 'hafta sonu', 'mesai saatleri'],
            'tickets'  => [
                [41, 'Outlook açılmıyor', 'mail', 'Outlook yükleniyor ekranında kalıp kapanıyor.'],
                [39, '2. kat yazıcısında toner bitti', '', 'Yeni toner kartuşu gönderebilir misiniz?'],
                [38, "Outlook'u açamıyorum", 'mail', 'Sabahtan beri Outlook başlamıyor.'],
                [36, 'Outlook açılışta sürekli çöküyor', 'mail', 'Açılış ekranından hemen sonra çöküyor.'],
                [35, 'Outlook e-postaları eşitlemiyor', 'mail', "20 dakikadır Outlook'a yeni e-posta gelmiyor."],
                [33, 'Outlook açılmıyor — acil', 'mail', 'Toplantım var ve Outlook açılmıyor.'],
                [32, 'Stajyer için yeni dizüstü bilgisayar', '', 'Stajyerimiz pazartesi başlıyor.'],
                [31, 'Outlook başlamıyor', 'mail', '"Profil yükleniyor" ekranında takılı kalıyor.'],
                [29, 'outlook açılmıyor', 'mail', 'outlook çalışmıyor'],
                [27, 'Outlook profil yüklenirken takılıyor', 'mail', 'İki kez yeniden başlattım, hâlâ takılı.'],
                [24, '3. katta internet yok', 'net', 'Masaüstü bilgisayarımda internet bağlantısı yok.'],
                [23, '3. katta kablosuz internet çalışmıyor', 'net', 'Kablosuz ağ görünmüyor.'],
                [22, 'Outlook hatası: Microsoft Outlook başlatılamıyor', 'mail', 'Hata mesajı: Microsoft Outlook başlatılamıyor.'],
                [21, 'İnternet çalışmıyor (3. katta)', 'net', 'Web siteleri açılmıyor.'],
                [20, 'İK portalı için parola sıfırlama', '', 'İK portalı parolamı unuttum.'],
                [19, '3. katta ağ bağlantısı yok, internet yok', 'net', 'Ağ kablosu takılı ama internet yok.'],
                [17, '3. katta internet kesik', 'net', '3. kattaki tüm ekip çevrimdışı.'],
                [15, '3. katta internet kesintisi', 'net', 'Hiçbir şey açılmıyor, Teams bağlantısı koptu.'],
                [12, 'Outlook hemen kapanıyor', 'mail', 'Açılıyor ve hemen kapanıyor.'],
                [8, 'Monitör titriyor', '', 'İkinci monitörüm titriyor.'],
            ],
        ] : [
            'cats'     => ['Email', 'Network'],
            'location' => 'HQ · Floor 3',
            'users'    => ['Emma Clarke', 'Liam Walker', 'Sofia Rossi', 'Noah Becker', 'Mia Laurent', 'Lucas Silva', 'Ava Novak', 'Ethan Brooks'],
            'rule'     => 'Demo data rule',
            'master'   => '[Major incident] Network outage on floor 3',
            'broadcast' => 'We are aware of the network outage on floor 3. A switch is being replaced; next update at 10:30.',
            'notice'   => 'Network outage on floor 3: we are aware of it and a switch is being replaced. No need to open a new ticket — next update at 10:30.',
            'oncall'   => ['weeknights', 'weekends', 'office hours'],
            'tickets'  => [
                [41, "Outlook won't open", 'mail', 'Outlook shows a loading screen and closes.'],
                [39, 'Printer on floor 2 out of toner', '', 'Please send a new toner cartridge.'],
                [38, 'Cannot open Outlook', 'mail', 'Outlook does not start since this morning.'],
                [36, 'Outlook keeps crashing at start', 'mail', 'Outlook crashes right after the splash screen.'],
                [35, 'Email not syncing in Outlook', 'mail', 'No new email in Outlook for 20 minutes.'],
                [33, 'Outlook not opening — urgent', 'mail', 'I have a meeting and Outlook will not open.'],
                [32, 'New laptop for the intern', '', 'Our intern starts on Monday.'],
                [31, "Outlook won't start", 'mail', 'Outlook stuck on "Loading profile".'],
                [29, 'outlook is not opening', 'mail', 'outlook not working'],
                [27, 'Outlook stuck on loading profile', 'mail', 'Restarted twice, still stuck.'],
                [24, 'No internet on floor 3', 'net', 'No connection on my desk PC.'],
                [23, 'Wi-Fi down on floor 3', 'net', 'The Wi-Fi network is not visible.'],
                [22, 'Outlook error: cannot start Microsoft Outlook', 'mail', 'Error message: Cannot start Microsoft Outlook.'],
                [21, 'Internet not working (3rd floor)', 'net', 'Websites do not load.'],
                [20, 'Password reset for the HR portal', '', 'I forgot my HR portal password.'],
                [19, 'No network connection floor 3', 'net', 'Network cable connected but no internet.'],
                [17, 'Floor 3: internet down', 'net', 'Whole team on floor 3 is offline.'],
                [15, 'Internet outage on the third floor', 'net', 'Nothing loads, Teams disconnected.'],
                [12, 'Outlook closes immediately', 'mail', 'It opens and closes immediately.'],
                [8, 'Monitor flickering', '', 'My second monitor flickers.'],
            ],
        ];
    }

    /** Creates the sample data (once). @return array{tickets:int,surges:int} */
    public static function create(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (self::loaded()) {
            throw new \RuntimeException(__('Demo data is already loaded.', 'surge'));
        }
        $tx = self::texts();
        $ent = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $ids = ['tickets' => [], 'masters' => [], 'users' => [], 'cats' => [], 'locations' => [], 'rules' => [], 'surges' => [], 'oncall' => []];
        $save = static function () use (&$ids): void {
            Config::save(['demo_data' => json_encode($ids)]);
        };
        try {
            $mail = (int) (new ITILCategory())->add(['name' => $tx['cats'][0], 'entities_id' => $ent, 'is_recursive' => 1, 'comment' => self::TAG]);
            $net = (int) (new ITILCategory())->add(['name' => $tx['cats'][1], 'entities_id' => $ent, 'is_recursive' => 1, 'comment' => self::TAG]);
            $floor = (int) (new Location())->add(['name' => $tx['location'], 'entities_id' => $ent, 'is_recursive' => 1, 'comment' => self::TAG]);
            $ids['cats'] = array_values(array_filter([$mail, $net]));
            $ids['locations'] = array_values(array_filter([$floor]));
            // a dedicated rule, so the demo works whatever the existing rules say
            $ids['rules'][] = Rule::save(0, ['name' => $tx['rule'], 'is_active' => 1, 'entities_id' => $ent, 'is_recursive' => 1, 'window_minutes' => 60,
                'min_tickets' => 5, 'similarity' => 0.35, 'match_category' => 1, 'match_title' => 1, 'categories' => [], 'comment' => self::TAG]);
            $save();

            // requesters: self-service users without password (they cannot log in)
            $selfService = (int) ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'helpdesk'], 'ORDER' => 'id', 'LIMIT' => 1])->current()['id'] ?? 0);
            foreach ($tx['users'] as $full) {
                [$first, $last] = explode(' ', $full, 2);
                $login = 'surgedemo.' . strtolower(strtr($first, ['ı' => 'i', 'ş' => 's', 'ç' => 'c', 'ğ' => 'g', 'ö' => 'o', 'ü' => 'u', 'Ç' => 'c', 'Ş' => 's', 'İ' => 'i', 'Ö' => 'o', 'Ü' => 'u']));
                if ((int) ($DB->request(['COUNT' => 'c', 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login]])->current()['c'] ?? 0) > 0) {
                    $login .= '.' . substr(md5((string) microtime(true)), 0, 4);
                }
                $uid = (int) (new User())->add(['name' => $login, 'firstname' => $first, 'realname' => $last, 'is_active' => 1, 'comment' => self::TAG]);
                if ($uid > 0) {
                    $ids['users'][] = $uid;
                    if ($selfService > 0) {
                        $DB->delete('glpi_profiles_users', ['users_id' => $uid]);
                        (new Profile_User())->add(['users_id' => $uid, 'profiles_id' => $selfService, 'entities_id' => $ent, 'is_recursive' => 1]);
                    }
                }
            }
            $save();

            $cat = ['mail' => $mail, 'net' => $net, '' => 0];
            $made = [];
            $i = 0;
            foreach ($tx['tickets'] as [$ago, $name, $c, $text]) {
                $i++;
                $id = (int) (new Ticket())->add(['name' => $name, 'content' => '<p>' . htmlspecialchars($text) . '</p>', 'entities_id' => $ent, 'itilcategories_id' => $cat[$c],
                    'locations_id' => $c === 'net' ? $floor : 0, '_users_id_requester' => $ids['users'][$i % max(1, count($ids['users']))] ?? Session::getLoginUserID(), 'urgency' => 3]);
                if ($id > 0) {
                    $made[$id] = $ago;
                    $ids['tickets'][] = $id;
                }
            }
            $save();
            // spread the arrival times like a real morning
            foreach ($made as $id => $ago) {
                $d = date('Y-m-d H:i:s', time() - $ago * 60);
                $DB->update('glpi_tickets', ['date' => $d, 'date_creation' => $d], ['id' => $id]);
            }
            Detector::reset();
            Detector::run();
            $sids = array_values(array_unique(array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => 'surges_id', 'FROM' => Surge::ITEMS, 'WHERE' => ['tickets_id' => array_keys($made) ?: [0]]])), 'surges_id'))));
            $ids['surges'] = $sids;
            $save();
            foreach ($sids as $sid) {
                Surge::refresh($sid);
                $dates = array_column(iterator_to_array($DB->request(['SELECT' => 't.date', 'FROM' => Surge::ITEMS . ' AS i', 'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['i' => 'tickets_id', 't' => 'id']]],
                    'WHERE' => ['i.surges_id' => $sid], 'ORDER' => 't.date'])), 'date');
                if ($dates) {
                    $DB->update(Surge::TABLE, ['date_creation' => $dates[min(4, count($dates) - 1)]], ['id' => $sid]); // detected at the 5th ticket
                }
                $s = Surge::get($sid);
                if ($s && (int) $s['locations_id'] === $floor && $floor > 0) {
                    // the network outage is being handled: master ticket, broadcast, notice on the portal
                    $ids['masters'][] = Surge::createMaster($sid, $tx['master']);
                    $save();
                    Surge::broadcast($sid, '<p>' . htmlspecialchars($tx['broadcast']) . '</p>');
                    Surge::publishNotice($sid, $tx['notice']);
                }
            }

            // past surges for the statistics page (no tickets behind them)
            mt_srand(42);
            $cats = [$mail, $mail, $net, $net, $net, 0, $mail];
            for ($k = 0; $k < 16; $k++) {
                $first = time() - mt_rand(3, 84) * 86400 - mt_rand(8, 17) * 3600;
                $status = $k === 3 || $k === 11 ? Surge::DISMISSED : Surge::RESOLVED;
                $DB->insert(Surge::TABLE, [
                    'rules_id' => $ids['rules'][0], 'entities_id' => $ent, 'name' => $tx['tickets'][[0, 10, 2, 13, 4][$k % 5]][1], 'status' => $status,
                    'itilcategories_id' => $cats[$k % count($cats)], 'locations_id' => $k % 3 === 0 ? $floor : 0, 'keywords' => json_encode([self::TAG]),
                    'ticket_count' => mt_rand(5, 24), 'date_first' => date('Y-m-d H:i:s', $first), 'date_last' => date('Y-m-d H:i:s', $first + mt_rand(10, 50) * 60),
                    'date_creation' => date('Y-m-d H:i:s', $first + mt_rand(2, 9) * 60),
                    'date_resolved' => $status === Surge::RESOLVED ? date('Y-m-d H:i:s', $first + mt_rand(30, 300) * 60) : null,
                    'problems_id' => 0, 'tickets_id_master' => 0, 'is_public' => 0,
                ]);
                $ids['surges'][] = (int) $DB->insertId();
            }
            mt_srand();

            // on-call rota
            $u = $ids['users'];
            if (count($u) >= 4) {
                $ids['oncall'][] = OnCall::save(0, ['users_id' => $u[1], 'days' => [64, 1, 2, 4, 8], 'time_start' => '18:00', 'time_end' => '08:00', 'is_active' => 1, 'comment' => self::TAG . ' · ' . $tx['oncall'][0]]);
                $ids['oncall'][] = OnCall::save(0, ['users_id' => $u[2], 'days' => [32, 64], 'time_start' => '00:00', 'time_end' => '24:00', 'is_active' => 1, 'comment' => self::TAG . ' · ' . $tx['oncall'][1]]);
                $ids['oncall'][] = OnCall::save(0, ['users_id' => $u[3], 'days' => [1, 2, 4, 8, 16], 'time_start' => '08:00', 'time_end' => '18:00', 'is_active' => 1, 'comment' => self::TAG . ' · ' . $tx['oncall'][2]]);
            }
        } finally {
            $save();
        }
        return ['tickets' => count($ids['tickets']), 'surges' => count($sids ?? [])];
    }

    /** Removes everything create() made. */
    public static function clean(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ids = self::ids();
        $tickets = array_map('intval', array_merge($ids['tickets'] ?? [], $ids['masters'] ?? []));
        $sids = array_map('intval', $ids['surges'] ?? []);
        // master tickets created by hand on demo surges
        if ($sids) {
            foreach ($DB->request(['SELECT' => 'tickets_id_master', 'FROM' => Surge::TABLE, 'WHERE' => ['id' => $sids]]) as $r) {
                if ((int) $r['tickets_id_master'] > 0) {
                    $tickets[] = (int) $r['tickets_id_master'];
                }
            }
        }
        foreach (array_unique($tickets) as $id) {
            $t = new Ticket();
            if ($t->getFromDB($id)) {
                $t->delete(['id' => $id], true);
            }
        }
        if ($sids) {
            $DB->delete(Surge::ITEMS, ['surges_id' => $sids]);
            $DB->delete(Surge::TABLE, ['id' => $sids]);
        }
        foreach ($ids['oncall'] ?? [] as $id) {
            OnCall::delete((int) $id);
        }
        foreach ($ids['rules'] ?? [] as $id) {
            Rule::delete((int) $id);
        }
        foreach ($ids['users'] ?? [] as $id) {
            $u = new User();
            if ($u->getFromDB((int) $id) && ($u->fields['comment'] ?? '') === self::TAG) {
                $u->delete(['id' => (int) $id], true);
            }
        }
        foreach ($ids['cats'] ?? [] as $id) {
            (new ITILCategory())->delete(['id' => (int) $id], true);
        }
        foreach ($ids['locations'] ?? [] as $id) {
            (new Location())->delete(['id' => (int) $id], true);
        }
        Config::save(['demo_data' => '']);
    }
}
