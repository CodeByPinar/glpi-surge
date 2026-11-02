# Extending Surge

Surge exposes three GLPI plugin hooks so that another plugin can react to surges or add to the Surge pages without
patching Surge. They exist since **Surge 0.3.0**. Register them in your plugin's `plugin_init_<key>()` like any GLPI hook.
Your plugin must be active for its listeners to run.

A listener that throws is logged in `files/_log/surge.log` and never breaks Surge.

## `surge_event` — something happened to a surge

```php
$PLUGIN_HOOKS['surge_event']['myplugin'] = [MyListener::class, 'onSurgeEvent'];

public static function onSurgeEvent(array $p): void
{
    // $p['event'], $p['surges_id'], $p['users_id'] (0 when run by the automatic action) + event data
}
```

| `event` | When | Extra data |
|---|---|---|
| `detected` | A new surge was created | — |
| `ticket_joined` | A ticket joined a live surge | `tickets_id` |
| `acknowledged` | An agent acknowledged the surge (also implied by master ticket, problem, broadcast) | — |
| `master` | Master ticket created and members linked | `tickets_id` (the master) |
| `problem` | Problem created and members linked | `problems_id` |
| `broadcast` | Public follow-up added to the open members | `done` (count), `html` |
| `resolved` | *Solve all* or cascade from the master ticket | `solved` (count) or `via_master` |
| `dismissed` / `reopened` | Status changed by an agent | — |
| `notice_published` / `notice_withdrawn` | Self-service notice | `message` |
| `ticket_removed` | A member was taken out of the surge | `tickets_id` |

Read the surge with `GlpiPlugin\Surge\Surge::get($id)` and its tickets with `Surge::ticketIds($id)`.

## `surge_tabs` — add a tab to the Surge pages

A filter: receive the tabs, return them.

```php
$PLUGIN_HOOKS['surge_tabs']['myplugin'] = static function (array $tabs): array {
    $tabs['mytab'] = [__('My page', 'myplugin'), 'ti ti-star', Plugin::getWebDir('myplugin') . '/front/page.php'];
    return $tabs;
};
```

Each tab is `key => [label, Tabler icon class, URL]`. Without a URL the key is taken as a Surge page name.
Use `GlpiPlugin\Surge\Ui::header('mytab', $title)` and `Ui::footer()` on your page to get the same chrome and the tab
highlighted.

## `surge_page` — add content to a surge page

```php
$PLUGIN_HOOKS['surge_page']['myplugin'] = static function (array $p): void {
    // $p['slot']: 'main' (below the ticket list) or 'side' (below the actions); $p['surge']: the surge row
    echo "<div class='card mb-3'>…</div>";
};
```

Escape everything you print (`GlpiPlugin\Surge\Ui::e()`), check your own rights, and post forms to your own front pages.

## Text similarity

`GlpiPlugin\Surge\Text` is a small, dependency-free toolbox you may reuse: `terms($title, $content)`,
`background($docs)`, `vectors($docs, $bg)`, `cosine($a, $b)`, `centroid($vs)`, `cluster($vecs, $min)`.
