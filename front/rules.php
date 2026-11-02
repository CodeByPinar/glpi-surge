<?php

/**
 * Surge — major incident detection for GLPI
 * @copyright 2026 Pınar Topuz
 * @license   GPL-3.0-or-later
 *
 * Detection rules: list, edit (?id=), new (?id=0&edit=1), delete. "Test" shows what the rule would find right now
 * without creating anything.
 */

use GlpiPlugin\Surge\Detector;
use GlpiPlugin\Surge\Profile;
use GlpiPlugin\Surge\Rule;
use GlpiPlugin\Surge\Text;
use GlpiPlugin\Surge\Ui;

include('../../../inc/includes.php');

Ui::need(Profile::CONFIG);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (isset($_POST['delete'])) {
        Rule::delete($id);
        Session::addMessageAfterRedirect(__('Rule deleted.', 'surge'), false, INFO);
        Html::redirect(Ui::url('rules'));
    }
    $id = Rule::save($id, $_POST);
    Session::addMessageAfterRedirect(__('Rule saved.', 'surge'), false, INFO);
    Html::redirect(Ui::url('rules', isset($_POST['test']) ? ['id' => $id, 'edit' => 1, 'test' => 1] : []));
}

Ui::header('rules', __('Detection rules', 'surge'));
$editId = isset($_GET['edit']) ? (int) ($_GET['id'] ?? 0) : -1;

if ($editId < 0) {
    echo "<div class='d-flex mb-3'><a class='btn btn-primary ms-auto' href='" . Ui::e(Ui::url('rules', ['id' => 0, 'edit' => 1])) . "'><i class='ti ti-plus'></i> " . Ui::e(__('New rule', 'surge')) . "</a></div>";
    echo "<div class='card'><table class='table table-vcenter card-table'><thead><tr><th>" . Ui::e(__('Rule', 'surge')) . "</th><th>" . Ui::e(__('Condition', 'surge')) . "</th><th>"
        . Ui::e(__('Entity', 'surge')) . "</th><th>" . Ui::e(__('Active', 'surge')) . "</th></tr></thead><tbody>";
    foreach (Rule::all() as $r) {
        echo "<tr><td><a class='fw-bold' href='" . Ui::e(Ui::url('rules', ['id' => $r['id'], 'edit' => 1])) . "'>" . Ui::e($r['name']) . "</a>"
            . ($r['comment'] !== '' ? "<div class='small text-muted'>" . Ui::e($r['comment']) . "</div>" : '') . "</td>"
            . "<td class='small'>" . Ui::e(Rule::summary($r)) . "</td><td class='small'>" . Ui::e(Ui::name('glpi_entities', (int) $r['entities_id']) ?: Dropdown::getDropdownName('glpi_entities', 0))
            . ($r['is_recursive'] ? ' ' . Ui::e(__('(and sub-entities)', 'surge')) : '') . "</td>"
            . "<td>" . ($r['is_active'] ? "<span class='badge bg-green-lt'>" . Ui::e(__('Yes')) . "</span>" : "<span class='badge bg-secondary-lt'>" . Ui::e(__('No')) . "</span>") . "</td></tr>";
    }
    echo "</tbody></table></div>";
    Ui::footer();
    return;
}

$r = $editId > 0 ? Rule::get($editId) : null;
if ($editId > 0 && !$r) {
    throw new \Glpi\Exception\Http\NotFoundHttpException();
}
$r ??= Rule::FIELDS + ['id' => 0, 'name' => __('Same category, similar text', 'surge')];
$chk = static fn(string $n, string $label, string $help = ''): string => "<label class='form-check mb-2'><input class='form-check-input' type='checkbox' name='{$n}' value='1'" . ($r[$n] ? ' checked' : '') . ">"
    . "<span class='form-check-label'>" . Ui::e($label) . "</span>" . ($help !== '' ? "<span class='form-check-description small text-muted d-block'>" . Ui::e($help) . "</span>" : '') . "</label>";

echo "<form method='post' action='" . Ui::e(Ui::url('rules')) . "'>" . Ui::csrf() . Html::hidden('id', ['value' => (int) $r['id']]);
echo "<div class='row g-3'><div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Rule', 'surge')) . "</h3></div><div class='card-body'>";
echo "<label class='form-label'>" . Ui::e(__('Name', 'surge')) . "</label><input class='form-control mb-3' name='name' required value='" . Ui::e($r['name']) . "'>";
echo $chk('is_active', __('Active', 'surge'));
echo "<label class='form-label mt-2'>" . Ui::e(__('Entity', 'surge')) . "</label>";
Entity::dropdown(['name' => 'entities_id', 'value' => (int) $r['entities_id'], 'entity' => $_SESSION['glpiactiveentities'] ?? 0]);
echo "<div class='mt-2'>" . $chk('is_recursive', __('Include sub-entities', 'surge')) . "</div>";
echo "<label class='form-label mt-2'>" . Ui::e(__('Comment', 'surge')) . "</label><textarea class='form-control' name='comment' rows='2'>" . Ui::e($r['comment']) . "</textarea>";
echo "</div></div></div>";

echo "<div class='col-lg-6'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . Ui::e(__('Condition', 'surge')) . "</h3></div><div class='card-body'>";
echo "<div class='row g-2 mb-3'><div class='col-6'><label class='form-label'>" . Ui::e(__('At least (tickets)', 'surge')) . "</label><input class='form-control' type='number' min='2' max='500' name='min_tickets' value='" . (int) $r['min_tickets'] . "'></div>"
    . "<div class='col-6'><label class='form-label'>" . Ui::e(__('Within (minutes)', 'surge')) . "</label><input class='form-control' type='number' min='1' max='1440' name='window_minutes' value='" . (int) $r['window_minutes'] . "'></div></div>";
echo $chk('match_category', __('Same category', 'surge'), __('Tickets without a category are grouped together.', 'surge'));
echo $chk('match_location', __('Same location', 'surge'));
echo $chk('match_title', __('Similar title / description', 'surge'), __('Turn off to group by category / location only (e.g. "any 10 tickets about the VPN in 15 minutes").', 'surge'));
echo "<label class='form-label mt-2'>" . Ui::e(__('Text similarity threshold', 'surge')) . " <span class='text-muted'>(0.05 – 1)</span></label><input class='form-control mb-1' name='similarity' value='" . Ui::e(number_format((float) $r['similarity'], 2, '.', '')) . "'>"
    . "<div class='form-text mb-3'>" . Ui::e(__('0.25 loose · 0.35 balanced · 0.5 strict. Use "Save and test" to tune it on the current tickets.', 'surge')) . "</div>";
echo "<label class='form-label'>" . Ui::e(__('Ticket type', 'surge')) . "</label><select class='form-select mb-3' name='ticket_type'>";
foreach ([0 => __('Any', 'surge'), 1 => __('Incidents only', 'surge'), 2 => __('Requests only', 'surge')] as $k => $l) {
    echo "<option value='{$k}'" . ((int) $r['ticket_type'] === $k ? ' selected' : '') . ">" . Ui::e($l) . "</option>";
}
echo "</select><label class='form-label'>" . Ui::e(__('Only these categories', 'surge')) . " <span class='text-muted'>(" . Ui::e(__('empty = all', 'surge')) . ")</span></label>";
ITILCategory::dropdown(['name' => 'categories[]', 'values' => Rule::categoryIds($r), 'multiple' => true, 'width' => '100%', 'entity' => $_SESSION['glpiactiveentities'] ?? 0]);
echo "</div></div></div></div>";

echo "<div class='d-flex gap-2 mt-3'><button class='btn btn-primary' name='save' value='1'><i class='ti ti-device-floppy'></i> " . Ui::e(__('Save', 'surge')) . "</button>"
    . "<button class='btn btn-outline-primary' name='test' value='1'><i class='ti ti-flask'></i> " . Ui::e(__('Save and test', 'surge')) . "</button>"
    . "<a class='btn btn-ghost-secondary' href='" . Ui::e(Ui::url('rules')) . "'>" . Ui::e(__('Back', 'surge')) . "</a>";
if ((int) $r['id'] > 0) {
    echo "<button class='btn btn-ghost-danger ms-auto' name='delete' value='1' onclick=\"return confirm(" . Ui::e(json_encode(__('Delete this rule? Surges it found are kept.', 'surge'))) . ")\"><i class='ti ti-trash'></i> " . Ui::e(__('Delete', 'surge')) . "</button>";
}
echo "</div></form>";

// dry run: what would this rule group right now (no surge is created)
if (isset($_GET['test']) && (int) $r['id'] > 0) {
    $cand = Detector::candidates($r);
    $groups = [];
    foreach ($cand as $tid => $t) {
        $groups[Detector::key($r, $t)][$tid] = Text::terms((string) $t['name'], (string) $t['content']);
    }
    echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'><i class='ti ti-flask me-1'></i>" . Ui::e(__('Test on current tickets', 'surge')) . "</h3>"
        . "<span class='ms-2 text-muted small'>" . Ui::e(sprintf(__('%1$d open ticket(s) in the last %2$d minutes that are not in a surge yet', 'surge'), count($cand), $r['window_minutes'])) . "</span></div><div class='card-body'>";
    $any = false;
    foreach ($groups as $g) {
        $vec = Text::vectors($g, Detector::background($r));
        foreach ($r['match_title'] ? Text::cluster($vec, (float) $r['similarity']) : [array_keys($vec)] as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $any = true;
            $ok = count($ids) >= (int) $r['min_tickets'];
            echo "<div class='mb-3'><span class='badge " . ($ok ? 'bg-red' : 'bg-secondary-lt') . "'>" . count($ids) . " / " . (int) $r['min_tickets'] . "</span> "
                . Ui::e($ok ? __('would become a surge', 'surge') : __('similar, below the threshold', 'surge')) . "<ul class='small mb-0'>";
            foreach (array_slice($ids, 0, 12) as $tid) {
                echo "<li>#" . (int) $tid . " " . Ui::e($cand[$tid]['name']) . "</li>";
            }
            echo "</ul></div>";
        }
    }
    if (!$any) {
        echo "<p class='text-muted mb-0'>" . Ui::e(__('No similar tickets in the window right now.', 'surge')) . "</p>";
    }
    echo "</div></div>";
}
Ui::footer();
