<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

/**
 * Detector — finds surges. Runs every few minutes (automatic action "surgeDetect") and right after a ticket is
 * created, so a burst is flagged while it is still happening.
 *
 * For every active rule:
 *   1. candidates = open, not deleted tickets created within the rule's time window (in the rule's entities),
 *      not already part of a surge (tickets removed from a surge by hand are never re-added to it);
 *   2. JOIN — each candidate that matches a live surge of the same rule (same category / location when required, text
 *      similar to the surge's centroid) is added to it; if the surge already has a master ticket, the new ticket is
 *      linked to the master;
 *   3. NEW — remaining candidates are grouped by category / location and clustered by text similarity; every group
 *      with at least `min_tickets` tickets becomes a new surge.
 */
final class Detector
{
    public const MAX_CANDIDATES = 400;

    /** @return array{created:list<int>,grown:array<int,int>} */
    public static function run(): array
    {
        $created = [];
        $grown = [];
        foreach (Rule::all(true) as $rule) {
            try {
                $r = self::runRule($rule);
                $created = array_merge($created, $r['created']);
                foreach ($r['grown'] as $sid => $n) {
                    $grown[$sid] = ($grown[$sid] ?? 0) + $n;
                }
            } catch (\Throwable $e) {
                \Toolbox::logInFile('surge', sprintf("Rule #%d: %s\n", $rule['id'], $e->getMessage()));
            }
        }
        foreach ($created as $sid) {
            Notifier::send('detected', $sid);
        }
        return ['created' => $created, 'grown' => $grown];
    }

    /** Open tickets of the rule's window that are not in a surge yet. @return array<int,array<string,mixed>> */
    public static function candidates(array $rule): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $since = date('Y-m-d H:i:s', time() - (int) $rule['window_minutes'] * 60);
        $ent = [(int) $rule['entities_id']];
        if ($rule['is_recursive']) {
            $ent = array_values(array_map('intval', getSonsOf('glpi_entities', (int) $rule['entities_id'])));
        }
        $where = [
            'glpi_tickets.is_deleted'  => 0,
            'glpi_tickets.date'        => ['>=', $since],
            'glpi_tickets.status'      => \Ticket::getNotSolvedStatusArray(),
            'glpi_tickets.entities_id' => $ent,
            'NOT' => ['glpi_tickets.id' => new \Glpi\DBAL\QuerySubQuery(['SELECT' => 'tickets_id', 'FROM' => Surge::ITEMS])],
        ];
        $where[] = ['NOT' => ['glpi_tickets.id' => new \Glpi\DBAL\QuerySubQuery(['SELECT' => 'tickets_id_master', 'FROM' => Surge::TABLE])]];
        if ((int) $rule['ticket_type'] > 0) {
            $where['glpi_tickets.type'] = (int) $rule['ticket_type'];
        }
        if ($cats = Rule::categoryIds($rule)) {
            $where['glpi_tickets.itilcategories_id'] = $cats;
        }
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'content', 'date', 'itilcategories_id', 'locations_id', 'entities_id'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => $where,
            'ORDER'  => 'date DESC',
            'LIMIT'  => self::MAX_CANDIDATES,
        ]) as $t) {
            $out[(int) $t['id']] = $t;
        }
        return $out;
    }

    public const BACKGROUND = 1000;

    /** @var array<string,array{n:int,df:array<string,int>}> */
    private static array $bg = [];

    /**
     * Word statistics of the latest tickets of the rule's entities (IDF background), cached for the request.
     * @return array{n:int,df:array<string,int>}
     */
    public static function background(array $rule): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ent = $rule['is_recursive'] ? array_values(array_map('intval', getSonsOf('glpi_entities', (int) $rule['entities_id']))) : [(int) $rule['entities_id']];
        $k = implode(',', $ent);
        if (!isset(self::$bg[$k])) {
            $docs = (static function () use ($DB, $ent) {
                foreach ($DB->request(['SELECT' => ['name', 'content'], 'FROM' => 'glpi_tickets', 'WHERE' => ['is_deleted' => 0, 'entities_id' => $ent], 'ORDER' => 'id DESC', 'LIMIT' => self::BACKGROUND]) as $t) {
                    yield Text::terms((string) $t['name'], (string) $t['content']);
                }
            })();
            self::$bg[$k] = Text::background($docs);
        }
        return self::$bg[$k];
    }

    /** Forgets cached statistics (tests, long-running processes). */
    public static function reset(): void
    {
        self::$bg = [];
    }

    /** Grouping key of a ticket for a rule ("c12|l0"). */
    public static function key(array $rule, array $t): string
    {
        return ($rule['match_category'] ? 'c' . (int) $t['itilcategories_id'] : 'c*') . '|' . ($rule['match_location'] ? 'l' . (int) $t['locations_id'] : 'l*');
    }

    /** @return array{created:list<int>,grown:array<int,int>} */
    public static function runRule(array $rule): array
    {
        $cand = self::candidates($rule);
        if (!$cand) {
            return ['created' => [], 'grown' => []];
        }
        $min = $rule['match_title'] ? (float) $rule['similarity'] : 0.0;

        // live surges of this rule + their member texts (for one shared IDF)
        $live = Surge::live((int) $rule['id']);
        $docs = [];
        foreach ($cand as $id => $t) {
            $docs[$id] = Text::terms((string) $t['name'], (string) $t['content']);
        }
        $members = [];
        foreach ($live as $s) {
            foreach (Surge::memberTexts((int) $s['id'], 30) as $mid => $m) {
                $members[(int) $s['id']][] = $mid;
                $docs[$mid] ??= Text::terms((string) $m['name'], (string) $m['content']);
            }
        }
        $vec = Text::vectors($docs, self::background($rule));

        // 1. join live surges
        $grown = [];
        foreach ($live as $s) {
            $sid = (int) $s['id'];
            $cent = Text::centroid(array_map(static fn($m) => $vec[$m], $members[$sid] ?? []));
            foreach ($cand as $id => $t) {
                if ($rule['match_category'] && (int) $t['itilcategories_id'] !== (int) $s['itilcategories_id']) {
                    continue;
                }
                if ($rule['match_location'] && (int) $t['locations_id'] !== (int) $s['locations_id']) {
                    continue;
                }
                $score = $cent ? Text::cosine($vec[$id], $cent) : 1.0;
                if ($score >= $min) {
                    Surge::addTicket($sid, $id, $score);
                    $grown[$sid] = ($grown[$sid] ?? 0) + 1;
                    unset($cand[$id]);
                }
            }
        }

        // 2. new surges
        $groups = [];
        foreach ($cand as $id => $t) {
            $groups[self::key($rule, $t)][$id] = $vec[$id];
        }
        $created = [];
        foreach ($groups as $g) {
            if (count($g) < (int) $rule['min_tickets']) {
                continue;
            }
            $clusters = $rule['match_title'] ? Text::cluster($g, $min) : [array_keys($g)];
            foreach ($clusters as $ids) {
                if (count($ids) < (int) $rule['min_tickets']) {
                    continue;
                }
                $created[] = Surge::create($rule, array_intersect_key($cand, array_flip($ids)), array_intersect_key($vec, array_flip($ids)));
            }
        }
        return ['created' => $created, 'grown' => $grown];
    }
}
