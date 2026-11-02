<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 */

namespace GlpiPlugin\Surge;

use Html;
use Session;

/** Ui — page chrome and small helpers shared by the front pages. */
final class Ui
{
    public static function e($s): string
    {
        return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $page, array $q = []): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        $u = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/surge/front/' . $page . '.php';
        $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
        return $q ? $u . '?' . http_build_query($q) : $u;
    }

    /** Hidden CSRF field (GLPI 11; GLPI 12 has no CSRF tokens). */
    public static function csrf(): string
    {
        return method_exists(Session::class, 'getNewCSRFToken') ? Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) : '';
    }

    public static function need(int ...$bits): void
    {
        foreach ($bits as $b) {
            if (Profile::has($b)) {
                return;
            }
        }
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }

    public static function header(string $active, string $title): void
    {
        Html::header('Surge — ' . $title, $_SERVER['PHP_SELF'] ?? '', 'helpdesk', Menu::class);
        $tabs = ['surge' => [__('Surges', 'surge'), 'ti ti-bolt'], 'stats' => [__('Statistics', 'surge'), 'ti ti-chart-bar']];
        if (Profile::has(Profile::CONFIG)) {
            $tabs['rules'] = [__('Detection rules', 'surge'), 'ti ti-filter'];
            $tabs['oncall'] = [__('On-call', 'surge'), 'ti ti-phone-call'];
            $tabs['config'] = [__('Settings', 'surge'), 'ti ti-adjustments'];
        }
        $tabs = Events::tabs($tabs);
        echo "<div class='surge container-fluid px-0'><div class='surge-head'><h2><i class='ti ti-bolt'></i> Surge</h2>"
            . "<span class='text-muted'>" . self::e(__('Bursts of similar tickets, handled as one major incident', 'surge')) . "</span></div>";
        echo "<ul class='nav nav-pills surge-nav mb-3'>";
        foreach ($tabs as $k => $tab) {
            [$label, $icon] = $tab;
            echo "<li class='nav-item'><a class='nav-link" . ($k === $active ? ' active' : '') . "' href='" . self::e($tab[2] ?? self::url($k)) . "'><i class='{$icon}'></i> " . self::e($label) . "</a></li>";
        }
        echo "</ul>";
    }

    public static function footer(): void
    {
        echo "</div>";
        Html::footer();
    }

    public static function dt($d): string
    {
        return $d ? (string) Html::convDateTime((string) $d) : '';
    }

    public static function status(string $s): string
    {
        [$label, $c] = Surge::statuses()[$s] ?? [$s, 'secondary'];
        return "<span class='badge bg-{$c}-lt'>" . self::e($label) . "</span>";
    }

    /** Inline SVG bar chart of arrivals. @param list<int> $h */
    public static function spark(array $h, int $w = 96, int $hgt = 24): string
    {
        $max = max(1, ...$h);
        $bw = $w / max(1, count($h));
        $bars = '';
        foreach ($h as $i => $v) {
            $bh = $v > 0 ? max(2, $v / $max * ($hgt - 2)) : 0;
            $bars .= sprintf("<rect x='%.1f' y='%.1f' width='%.1f' height='%.1f' rx='1'/>", $i * $bw + 1, $hgt - $bh, $bw - 2, $bh);
        }
        return "<svg class='surge-spark' width='{$w}' height='{$hgt}' viewBox='0 0 {$w} {$hgt}' role='img' aria-label='" . self::e(__('Ticket arrivals', 'surge')) . "'>{$bars}</svg>";
    }

    public static function name(string $table, int $id): string
    {
        return $id > 0 ? (string) \Dropdown::getDropdownName($table, $id) : '';
    }
}
