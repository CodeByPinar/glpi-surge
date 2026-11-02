<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use CommonITILObject;
use ITILSolution;
use Ticket;

/** TicketHooks — GLPI hooks: detection on new tickets, cascading solutions, banner on the ticket form. */
final class TicketHooks
{
    private static bool $cascading = false;

    /** Set while Surge itself creates tickets (master ticket): they must not be detected as part of a surge. */
    public static bool $quiet = false;

    /** New ticket → immediate detection (a burst is flagged without waiting for the automatic action). */
    public static function onTicketAdd(Ticket $t): void
    {
        if (self::$quiet) {
            return;
        }
        try {
            Detector::run();
        } catch (\Throwable $e) {
            \Toolbox::logInFile('surge', 'Detection on ticket add: ' . $e->getMessage() . "\n");
        }
    }

    /** Master ticket solved → same solution on every open member (setting "cascade_solution"). */
    public static function onSolutionAdd(ITILSolution $sol): void
    {
        if (self::$cascading || ($sol->fields['itemtype'] ?? '') !== 'Ticket' || Config::get('cascade_solution') !== '1') {
            return;
        }
        $hit = Surge::forTicket((int) $sol->fields['items_id']);
        if (!$hit || $hit['role'] !== 'master' || in_array($hit['surge']['status'], [Surge::RESOLVED, Surge::DISMISSED], true)) {
            return;
        }
        self::$cascading = true;
        try {
            $sid = (int) $hit['surge']['id'];
            foreach (Surge::ticketIds($sid) as $tid) {
                $t = new Ticket();
                if ($t->getFromDB($tid) && !in_array((int) $t->fields['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)) {
                    (new ITILSolution())->add([
                        'itemtype'          => 'Ticket',
                        'items_id'          => $tid,
                        'content'           => (string) $sol->fields['content'],
                        'solutiontypes_id'  => (int) ($sol->fields['solutiontypes_id'] ?? 0),
                    ]);
                }
            }
            Surge::setStatus($sid, Surge::RESOLVED);
            Notifier::send('resolved', $sid);
        } finally {
            self::$cascading = false;
        }
    }

    /** Banner on top of the ticket's right panel. @param array{item?:mixed} $params */
    public static function banner(array $params): void
    {
        $item = $params['item'] ?? null;
        if (!$item instanceof Ticket || $item->isNewItem() || !Profile::canRead()) {
            return;
        }
        $hit = Surge::forTicket((int) $item->getID());
        if (!$hit) {
            return;
        }
        $s = $hit['surge'];
        $st = Surge::statuses()[$s['status']] ?? [$s['status'], 'secondary'];
        $mins = max(1, (int) round((strtotime((string) $s['date_last']) - strtotime((string) $s['date_first'])) / 60));
        $msg = $hit['role'] === 'master'
            ? sprintf(__('Master ticket of surge #%1$d — %2$d linked tickets.', 'surge'), $s['id'], $s['ticket_count'])
            : sprintf(__('Part of surge #%1$d — %2$d similar tickets in %3$d min.', 'surge'), $s['id'], $s['ticket_count'], $mins)
                . ((int) $s['tickets_id_master'] > 0 && (int) $s['tickets_id_master'] !== (int) $item->getID()
                    ? ' ' . sprintf(__('Master ticket: #%d.', 'surge'), $s['tickets_id_master']) : '');
        echo "<div class='surge-banner surge-" . Ui::e($s['status']) . "'><i class='ti ti-bolt'></i><div><b>" . Ui::e($s['name']) . "</b>"
            . " <span class='badge bg-{$st[1]}-lt'>" . Ui::e($st[0]) . "</span><br>" . Ui::e($msg)
            . " <a href='" . Ui::e(Ui::url('surge.form', ['id' => $s['id']])) . "'>" . Ui::e(__('Open surge', 'surge')) . " →</a></div></div>";
    }
}
