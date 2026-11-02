<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use CommonGLPI;
use CommonITILActor;
use CommonITILObject;
use CommonITILObject_CommonITILObject;
use ITILFollowup;
use ITILSolution;
use Problem;
use Problem_Ticket;
use Session;
use Ticket;
use Ticket_Ticket;

/**
 * Surge — a detected burst of similar tickets and what the service desk does with it.
 *
 * Life cycle: new (detected) → active (acknowledged / master ticket or problem created) → resolved | dismissed.
 * Also the automatic action "surgeDetect" (every 5 minutes by default).
 */
class Surge extends CommonGLPI
{
    public const TABLE = 'glpi_plugin_surge_surges';
    public const ITEMS = 'glpi_plugin_surge_items';

    public const NEW       = 'new';
    public const ACTIVE    = 'active';
    public const RESOLVED  = 'resolved';
    public const DISMISSED = 'dismissed';

    public static function getTypeName($nb = 0)
    {
        return _n('Surge', 'Surges', $nb, 'surge');
    }

    /** @return array<string,array{0:string,1:string}> status => [label, colour] */
    public static function statuses(): array
    {
        return [
            self::NEW       => [__('New', 'surge'), 'red'],
            self::ACTIVE    => [__('In progress', 'surge'), 'orange'],
            self::RESOLVED  => [__('Resolved', 'surge'), 'green'],
            self::DISMISSED => [__('Dismissed', 'surge'), 'secondary'],
        ];
    }

    // ------------------------------------------------------------------ automatic action

    public static function cronInfo($name)
    {
        return $name === 'surgeDetect'
            ? ['description' => __('Detect bursts of similar tickets (major incidents)', 'surge')]
            : [];
    }

    /** @param \CronTask $task */
    public static function cronSurgeDetect($task)
    {
        $r = Detector::run();
        $n = count($r['created']) + array_sum($r['grown']);
        $task->addVolume($n);
        if ($r['created']) {
            $task->log(sprintf(__('%d new surge(s)', 'surge'), count($r['created'])));
        }
        return $n > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------ data

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $r = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['id' => $id]])->current();
        return $r ?: null;
    }

    /** Surges of a rule that still collect tickets. @return list<array<string,mixed>> */
    public static function live(int $rulesId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $since = date('Y-m-d H:i:s', time() - Config::int('active_hours') * 3600);
        return array_values(iterator_to_array($DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['rules_id' => $rulesId, 'status' => [self::NEW, self::ACTIVE], 'date_last' => ['>=', $since]],
        ])));
    }

    /** Ticket ids of a surge (not removed). @return list<int> */
    public static function ticketIds(int $sid): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        return array_map('intval', array_column(iterator_to_array($DB->request(['SELECT' => 'tickets_id', 'FROM' => self::ITEMS, 'WHERE' => ['surges_id' => $sid, 'is_removed' => 0]])), 'tickets_id'));
    }

    /** Latest member tickets' texts (for the centroid). @return array<int,array{name:string,content:string}> */
    public static function memberTexts(int $sid, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.content'],
            'FROM'   => 'glpi_tickets',
            'INNER JOIN' => [self::ITEMS => ['ON' => [self::ITEMS => 'tickets_id', 'glpi_tickets' => 'id']]],
            'WHERE'  => [self::ITEMS . '.surges_id' => $sid, self::ITEMS . '.is_removed' => 0],
            'ORDER'  => self::ITEMS . '.id DESC',
            'LIMIT'  => $limit,
        ]) as $r) {
            $out[(int) $r['id']] = ['name' => (string) $r['name'], 'content' => (string) $r['content']];
        }
        return $out;
    }

    /** Surge of a ticket (as member or as master). @return array{surge:array<string,mixed>,role:string}|null */
    public static function forTicket(int $tid): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $m = $DB->request(['FROM' => self::TABLE, 'WHERE' => ['tickets_id_master' => $tid], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        if ($m) {
            return ['surge' => $m, 'role' => 'master'];
        }
        $i = $DB->request(['SELECT' => 'surges_id', 'FROM' => self::ITEMS, 'WHERE' => ['tickets_id' => $tid, 'is_removed' => 0], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        if ($i && ($s = self::get((int) $i['surges_id']))) {
            return ['surge' => $s, 'role' => 'member'];
        }
        return null;
    }

    /**
     * Creates a surge from detected tickets.
     * @param array<int,array<string,mixed>> $tickets @param array<int,array<string,float>> $vec
     * @param array<int,float> $scores similarity of each ticket to the surge (hybrid when embeddings are on)
     */
    public static function create(array $rule, array $tickets, array $vec, array $scores = []): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $cent = Text::centroid(array_values($vec));
        // a "central" ticket names the surge: among the most typical ones prefer a normally written (not ALL CAPS /
        // all lower case), short title
        $scores = [];
        foreach ($vec as $id => $v) {
            $scores[$id] = Text::cosine($v, $cent);
        }
        $top = max($scores);
        $best = null;
        $bestRank = null;
        foreach ($scores as $id => $sc) {
            if ($sc < $top - 0.08) {
                continue;
            }
            $name = trim((string) $tickets[$id]['name']);
            $plain = $name === mb_strtoupper($name) || $name === mb_strtolower($name) ? 1 : 0;
            $rank = [$plain, mb_strlen($name), -$sc];
            if ($bestRank === null || $rank < $bestRank) {
                $bestRank = $rank;
                $best = $id;
            }
        }
        $dates = array_column($tickets, 'date');
        $first = reset($tickets);
        $cat = $rule['match_category'] ? (int) $first['itilcategories_id'] : self::common($tickets, 'itilcategories_id');
        $loc = $rule['match_location'] ? (int) $first['locations_id'] : self::common($tickets, 'locations_id');
        $DB->insert(self::TABLE, [
            'rules_id'          => (int) $rule['id'],
            'entities_id'       => self::common($tickets, 'entities_id'),
            'name'              => mb_substr((string) $tickets[$best]['name'], 0, 255),
            'status'            => self::NEW,
            'itilcategories_id' => $cat,
            'locations_id'      => $loc,
            'keywords'          => json_encode(self::keywords($cent, $tickets), JSON_UNESCAPED_UNICODE),
            'ticket_count'      => 0,
            'date_first'        => min($dates),
            'date_last'         => max($dates),
            'date_creation'     => date('Y-m-d H:i:s'),
            'date_mod'          => date('Y-m-d H:i:s'),
        ]);
        $sid = (int) $DB->insertId();
        foreach ($tickets as $id => $t) {
            self::addTicket($sid, $id, $scores[$id] ?? Text::cosine($vec[$id], $cent), false);
        }
        self::refresh($sid);
        return $sid;
    }

    /** Top centroid terms as readable words ("outloo" → "outlook"). @return list<string> */
    private static function keywords(array $cent, array $tickets): array
    {
        $words = Text::words(array_map(static fn($t) => (string) $t['name'], $tickets));
        $out = [];
        foreach (array_keys($cent) as $stem) {
            $out[] = $words[$stem] ?? $stem;
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    /** Most frequent value of a field (0 when tickets disagree on more than half). */
    private static function common(array $tickets, string $field): int
    {
        $c = array_count_values(array_map(static fn($t) => (int) $t[$field], $tickets));
        arsort($c);
        $k = (int) array_key_first($c);
        return $field === 'entities_id' || reset($c) * 2 > count($tickets) ? $k : 0;
    }

    public static function addTicket(int $sid, int $tid, float $score, bool $refresh = true): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($DB->request(['COUNT' => 'c', 'FROM' => self::ITEMS, 'WHERE' => ['surges_id' => $sid, 'tickets_id' => $tid]])->current()['c'] > 0) {
            return;
        }
        $DB->insert(self::ITEMS, ['surges_id' => $sid, 'tickets_id' => $tid, 'score' => round($score, 3), 'is_removed' => 0, 'date_add' => date('Y-m-d H:i:s')]);
        if (!$refresh) {
            return;
        }
        self::refresh($sid);
        $s = self::get($sid);
        if ($s && (int) $s['tickets_id_master'] > 0 && Config::get('link_new') === '1') {
            self::link($tid, (int) $s['tickets_id_master']);
        }
        if ($s && (int) $s['problems_id'] > 0) {
            self::linkProblem((int) $s['problems_id'], $tid);
        }
    }

    /** Recomputes count and first / last dates. */
    public static function refresh(int $sid): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $r = $DB->request([
            'SELECT' => [new \Glpi\DBAL\QueryExpression('COUNT(*) AS n'), new \Glpi\DBAL\QueryExpression('MIN(t.date) AS d1'), new \Glpi\DBAL\QueryExpression('MAX(t.date) AS d2')],
            'FROM'   => self::ITEMS . ' AS i',
            'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['i' => 'tickets_id', 't' => 'id']]],
            'WHERE'  => ['i.surges_id' => $sid, 'i.is_removed' => 0],
        ])->current();
        $DB->update(self::TABLE, ['ticket_count' => (int) $r['n'], 'date_first' => $r['d1'], 'date_last' => $r['d2'], 'date_mod' => date('Y-m-d H:i:s')], ['id' => $sid]);
    }

    // ------------------------------------------------------------------ actions (service desk)

    /** Child ticket → master ticket ("son of"). */
    public static function link(int $child, int $master): void
    {
        if ($child === $master) {
            return;
        }
        /** @var \DBmysql $DB */
        global $DB;
        $exists = $DB->request(['COUNT' => 'c', 'FROM' => 'glpi_tickets_tickets', 'WHERE' => ['OR' => [
            ['tickets_id_1' => $child, 'tickets_id_2' => $master],
            ['tickets_id_1' => $master, 'tickets_id_2' => $child],
        ]]])->current()['c'];
        if (!$exists) {
            (new Ticket_Ticket())->add(['tickets_id_1' => $child, 'tickets_id_2' => $master, 'link' => CommonITILObject_CommonITILObject::SON_OF]);
        }
    }

    public static function linkProblem(int $pid, int $tid): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->request(['COUNT' => 'c', 'FROM' => 'glpi_problems_tickets', 'WHERE' => ['problems_id' => $pid, 'tickets_id' => $tid]])->current()['c']) {
            (new Problem_Ticket())->add(['problems_id' => $pid, 'tickets_id' => $tid]);
        }
    }

    private static function touch(int $sid, array $fields): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(self::TABLE, $fields + ['date_mod' => date('Y-m-d H:i:s')], ['id' => $sid]);
    }

    public static function acknowledge(int $sid): void
    {
        $s = self::get($sid);
        if ($s && $s['status'] === self::NEW) {
            self::touch($sid, ['status' => self::ACTIVE, 'users_id_ack' => (int) Session::getLoginUserID(), 'date_ack' => date('Y-m-d H:i:s')]);
            Events::fire('acknowledged', $sid);
        }
    }

    /** HTML list of the surge's tickets (for master ticket / problem descriptions). */
    private static function ticketList(int $sid): string
    {
        $li = '';
        foreach (self::ticketIds($sid) as $tid) {
            $t = new Ticket();
            if ($t->getFromDB($tid)) {
                $req = array_map(static fn($u) => getUserName((int) $u['users_id']), $t->getUsers(CommonITILActor::REQUESTER));
                $li .= '<li>#' . $tid . ' — ' . htmlspecialchars((string) $t->fields['name']) . ($req ? ' (' . htmlspecialchars(implode(', ', $req)) . ')' : '') . '</li>';
            }
        }
        return '<ul>' . $li . '</ul>';
    }

    /** Creates the master ("major incident") ticket and links every member to it. @return int ticket id */
    public static function createMaster(int $sid, string $title = ''): int
    {
        $s = self::get($sid);
        if (!$s) {
            throw new \RuntimeException(__('Surge not found.', 'surge'));
        }
        if ((int) $s['tickets_id_master'] > 0) {
            return (int) $s['tickets_id_master'];
        }
        if (!Ticket::canCreate()) {
            throw new \RuntimeException(__('You are not allowed to create tickets.', 'surge'));
        }
        $t = new Ticket();
        TicketHooks::$quiet = true;
        try {
            $id = (int) $t->add([
            'name'              => mb_substr($title !== '' ? $title : sprintf(__('[Major incident] %s', 'surge'), $s['name']), 0, 255),
            'content'           => '<p>' . htmlspecialchars(sprintf(
                __('Major incident opened by Surge from %1$d similar tickets received between %2$s and %3$s.', 'surge'),
                $s['ticket_count'],
                \Html::convDateTime($s['date_first']),
                \Html::convDateTime($s['date_last'])
            )) . '</p>' . self::ticketList($sid),
            'entities_id'       => (int) $s['entities_id'],
            'type'              => Ticket::INCIDENT_TYPE,
            'itilcategories_id' => (int) $s['itilcategories_id'],
            'locations_id'      => (int) $s['locations_id'],
            'urgency'           => Config::int('master_urgency'),
            'impact'            => Config::int('master_impact'),
            '_users_id_requester' => (int) Session::getLoginUserID(),
            ]);
        } finally {
            TicketHooks::$quiet = false;
        }
        if ($id <= 0) {
            throw new \RuntimeException(__('The master ticket could not be created.', 'surge'));
        }
        foreach (self::ticketIds($sid) as $tid) {
            self::link($tid, $id);
        }
        self::touch($sid, ['tickets_id_master' => $id]);
        self::acknowledge($sid);
        Notifier::send('master', $sid);
        Events::fire('master', $sid, ['tickets_id' => $id]);
        return $id;
    }

    /** Creates a problem and links every member (and the master) to it. @return int problem id */
    public static function createProblem(int $sid): int
    {
        $s = self::get($sid);
        if (!$s) {
            throw new \RuntimeException(__('Surge not found.', 'surge'));
        }
        if ((int) $s['problems_id'] > 0) {
            return (int) $s['problems_id'];
        }
        if (!Problem::canCreate()) {
            throw new \RuntimeException(__('You are not allowed to create problems.', 'surge'));
        }
        $p = new Problem();
        $id = (int) $p->add([
            'name'              => mb_substr((string) $s['name'], 0, 255),
            'content'           => '<p>' . htmlspecialchars(sprintf(__('Problem opened by Surge from %d similar tickets.', 'surge'), $s['ticket_count'])) . '</p>' . self::ticketList($sid),
            'entities_id'       => (int) $s['entities_id'],
            'itilcategories_id' => (int) $s['itilcategories_id'],
            'urgency'           => Config::int('master_urgency'),
            'impact'            => Config::int('master_impact'),
        ]);
        if ($id <= 0) {
            throw new \RuntimeException(__('The problem could not be created.', 'surge'));
        }
        foreach (array_filter(array_merge(self::ticketIds($sid), [(int) $s['tickets_id_master']])) as $tid) {
            self::linkProblem($id, $tid);
        }
        self::touch($sid, ['problems_id' => $id]);
        self::acknowledge($sid);
        Events::fire('problem', $sid, ['problems_id' => $id]);
        return $id;
    }

    /** Open tickets of the surge the user may update. @return array{0:list<Ticket>,1:int} [tickets, skipped] */
    private static function openTickets(int $sid): array
    {
        $out = [];
        $skipped = 0;
        foreach (self::ticketIds($sid) as $tid) {
            $t = new Ticket();
            if (!$t->getFromDB($tid) || in_array((int) $t->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)) {
                continue;
            }
            if (!$t->canUpdateItem()) {
                $skipped++;
                continue;
            }
            $out[] = $t;
        }
        return [$out, $skipped];
    }

    /** Same public follow-up on every open ticket (requesters are notified by GLPI). @return array{0:int,1:int} [done, skipped] */
    public static function broadcast(int $sid, string $html): array
    {
        [$list, $skipped] = self::openTickets($sid);
        $n = 0;
        foreach ($list as $t) {
            if ((new ITILFollowup())->add(['itemtype' => 'Ticket', 'items_id' => $t->getID(), 'content' => $html, 'is_private' => 0])) {
                $n++;
            }
        }
        self::acknowledge($sid);
        Events::fire('broadcast', $sid, ['done' => $n, 'html' => $html]);
        return [$n, $skipped];
    }

    /** Solves every open ticket (and the master) with the same solution. @return array{0:int,1:int} [done, skipped] */
    public static function resolveAll(int $sid, string $html): array
    {
        $s = self::get($sid);
        $before = count(self::openTickets($sid)[0]);
        if ($s && (int) $s['tickets_id_master'] > 0) {
            $m = new Ticket();
            if ($m->getFromDB((int) $s['tickets_id_master']) && !in_array((int) $m->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true) && $m->canUpdateItem()) {
                (new ITILSolution())->add(['itemtype' => 'Ticket', 'items_id' => $m->getID(), 'content' => $html]); // cascades (TicketHooks)
            }
        }
        [$list, $skipped] = self::openTickets($sid);
        foreach ($list as $t) {
            (new ITILSolution())->add(['itemtype' => 'Ticket', 'items_id' => $t->getID(), 'content' => $html]);
        }
        // members solved by the master's cascade count too
        $n = $before - count(self::openTickets($sid)[0]);
        self::touch($sid, ['status' => self::RESOLVED, 'date_resolved' => date('Y-m-d H:i:s')]);
        Notifier::send('resolved', $sid);
        Events::fire('resolved', $sid, ['solved' => $n]);
        return [$n, $skipped];
    }

    public static function setStatus(int $sid, string $status): void
    {
        if (isset(self::statuses()[$status])) {
            self::touch($sid, ['status' => $status] + ($status === self::RESOLVED ? ['date_resolved' => date('Y-m-d H:i:s')] : []));
            if ($status === self::DISMISSED || $status === self::ACTIVE) {
                Events::fire($status === self::DISMISSED ? 'dismissed' : 'reopened', $sid);
            }
        }
    }

    /** Takes a ticket out of a surge (false positive); it is never re-added. */
    public static function removeTicket(int $sid, int $tid): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(self::ITEMS, ['is_removed' => 1], ['surges_id' => $sid, 'tickets_id' => $tid]);
        self::refresh($sid);
        Events::fire('ticket_removed', $sid, ['tickets_id' => $tid]);
    }

    // ------------------------------------------------------------------ public notice (self-service portal)

    /** Default notice text for a surge. */
    public static function defaultNotice(array $s): string
    {
        return sprintf(__('We are aware of an issue: %s. Our team is working on it — there is no need to open a new ticket. We will post an update here.', 'surge'), $s['name']);
    }

    public static function publishNotice(int $sid, string $message): void
    {
        $message = trim(mb_substr($message, 0, 1000));
        if ($message === '') {
            throw new \RuntimeException(__('Write the notice first.', 'surge'));
        }
        self::touch($sid, ['public_message' => $message, 'is_public' => 1, 'date_public' => date('Y-m-d H:i:s')]);
        Events::fire('notice_published', $sid, ['message' => $message]);
    }

    public static function unpublishNotice(int $sid): void
    {
        self::touch($sid, ['is_public' => 0]);
        Events::fire('notice_withdrawn', $sid);
    }

    /**
     * Notices the current user may see: published surges of their active entities that are open, or resolved recently
     * (shown as "resolved"). @return list<array{id:int,message:string,resolved:bool,date:string}>
     */
    public static function notices(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $hours = Config::int('notice_resolved_hours');
        $where = ['is_public' => 1, 'OR' => [['status' => [self::NEW, self::ACTIVE]]]];
        if ($hours > 0) {
            $where['OR'][] = ['status' => self::RESOLVED, 'date_resolved' => ['>=', date('Y-m-d H:i:s', time() - $hours * 3600)]];
        }
        $out = [];
        foreach ($DB->request(['FROM' => self::TABLE, 'WHERE' => $where + getEntitiesRestrictCriteria(self::TABLE, '', '', false), 'ORDER' => 'date_public DESC', 'LIMIT' => 5]) as $r) {
            $resolved = $r['status'] === self::RESOLVED;
            $out[] = [
                'id'       => (int) $r['id'],
                // only the agent-written text is shown (the surge name comes from a ticket title and may be personal)
                'message'  => $resolved ? sprintf(__('Resolved: %s', 'surge'), $r['public_message']) : (string) $r['public_message'],
                'resolved' => $resolved,
                'date'     => (string) ($resolved ? $r['date_resolved'] : $r['date_public']),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ statistics

    /**
     * Figures for the statistics page over the last $days days (surges first seen in that period, user's entities).
     * @return array<string,mixed>
     */
    public static function stats(int $days): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $since = date('Y-m-d H:i:s', time() - $days * 86400);
        $rows = iterator_to_array($DB->request(['FROM' => self::TABLE, 'WHERE' => ['date_first' => ['>=', $since]] + getEntitiesRestrictCriteria(self::TABLE, '', '', false)]));
        $median = static function (array $a): ?float {
            if (!$a) {
                return null;
            }
            sort($a);
            $n = count($a);
            return $n % 2 ? (float) $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2;
        };
        $detect = [];
        $resolve = [];
        $byCat = [];
        $byLoc = [];
        $byWeek = [];
        $status = array_fill_keys(array_keys(self::statuses()), 0);
        $tickets = 0;
        $master = 0;
        foreach ($rows as $r) {
            $first = strtotime((string) $r['date_first']);
            $tickets += (int) $r['ticket_count'];
            $status[$r['status']] = ($status[$r['status']] ?? 0) + 1;
            $master += (int) $r['tickets_id_master'] > 0 || (int) $r['problems_id'] > 0 ? 1 : 0;
            if ($r['date_creation']) {
                $detect[] = max(0, strtotime((string) $r['date_creation']) - $first) / 60;
            }
            if ($r['status'] === self::RESOLVED && $r['date_resolved']) {
                $resolve[] = max(0, strtotime((string) $r['date_resolved']) - $first) / 3600;
            }
            $c = (int) $r['itilcategories_id'];
            $byCat[$c] ??= ['surges' => 0, 'tickets' => 0];
            $byCat[$c]['surges']++;
            $byCat[$c]['tickets'] += (int) $r['ticket_count'];
            if ((int) $r['locations_id'] > 0) {
                $byLoc[(int) $r['locations_id']] = ($byLoc[(int) $r['locations_id']] ?? 0) + 1;
            }
            $wk = date('o-\WW', $first);
            $byWeek[$wk] = ($byWeek[$wk] ?? 0) + 1;
        }
        uasort($byCat, static fn($a, $b) => $b['tickets'] <=> $a['tickets']);
        arsort($byLoc);
        // continuous weeks (oldest → newest)
        $weeks = [];
        for ($t = strtotime('monday this week', time() - $days * 86400); $t <= time(); $t += 7 * 86400) {
            $k = date('o-\WW', $t);
            $weeks[$k] = $byWeek[$k] ?? 0;
        }
        $n = count($rows);
        return [
            'surges'        => $n,
            'tickets'       => $tickets,
            'avg_tickets'   => $n ? $tickets / $n : 0,
            'detect_min'    => $median($detect),
            'resolve_h'     => $median($resolve),
            'handled_pct'   => $n ? 100 * $master / $n : 0,
            'dismissed_pct' => $n ? 100 * $status[self::DISMISSED] / $n : 0,
            'status'        => $status,
            'categories'    => array_slice($byCat, 0, 8, true),
            'locations'     => array_slice($byLoc, 0, 8, true),
            'weeks'         => array_slice($weeks, -26, null, true),
        ];
    }

    /** Arrivals per time bucket for the sparkline. @return list<int> */
    public static function histogram(int $sid, int $buckets = 12): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $s = self::get($sid);
        $times = [];
        foreach ($DB->request([
            'SELECT' => ['t.date'],
            'FROM'   => self::ITEMS . ' AS i',
            'INNER JOIN' => ['glpi_tickets AS t' => ['ON' => ['i' => 'tickets_id', 't' => 'id']]],
            'WHERE'  => ['i.surges_id' => $sid, 'i.is_removed' => 0],
        ]) as $r) {
            $times[] = strtotime((string) $r['date']);
        }
        $h = array_fill(0, $buckets, 0);
        if (!$times || !$s) {
            return $h;
        }
        $a = min($times);
        $span = max(60, max($times) - $a + 1);
        foreach ($times as $x) {
            $h[min($buckets - 1, (int) floor(($x - $a) / $span * $buckets))]++;
        }
        return $h;
    }
}
