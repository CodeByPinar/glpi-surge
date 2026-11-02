# ⚡ Surge — major incident detection for GLPI

When something breaks, the service desk does not get one ticket. It gets the same complaint thirty times in fifteen
minutes: *"Outlook won't open"*, *"Cannot open Outlook"*, *"outlook is not opening"*. L1 answers them one by one and
nobody realises it is one incident until much later.

**Surge** spots these bursts while they happen, groups the tickets into one *surge* and lets L1 handle them as a single
major incident. Create one master ticket or problem, link every ticket to it, send one update to everyone, and solve
them all at once.

![A detected surge: 10 similar tickets in 29 minutes](docs/screenshots/surge-detail.png)

- **Detects bursts in real time.** Surge checks each new ticket when it is created; an automatic action every 5 minutes
  is a safety net.
- **Understands messy titles.** "Outlook won't open", "OUTLOOK NOT OPENING — urgent" and "Email not syncing in Outlook"
  end up together, while "Printer out of toner" in the same category does not.
- **One click to a major incident.** Surge creates the master ticket and links every child to it ("son of"). Tickets that
  arrive later are linked automatically. You can also open a problem and link everything.
- **Tell everyone at once.** One public follow-up goes to every open ticket of the surge, and GLPI notifies the
  requesters as usual.
- **Solve once.** Solving the master ticket solves every linked ticket with the same solution. "Solve all tickets" does
  the same from the surge page.
- **Banner on the ticket.** Agents opening any ticket of a surge see that it belongs to one, and which master ticket
  it is linked to.
- **Webhook** to Slack, Microsoft Teams or any HTTP endpoint when a surge is detected, gets a master ticket and is resolved.
- **No AI service, no external calls.** Detection is plain PHP text similarity running inside GLPI. Your tickets never
  leave your server.
- GLPI **11 and 12**, one code base. English and Turkish included.

| Surges | Major incident in progress | Ticket banner |
|---|---|---|
| ![Surges list](docs/screenshots/surges.png) | ![Surge with a master ticket](docs/screenshots/surge-major-incident.png) | ![Banner on a ticket](docs/screenshots/ticket-banner.png) |

## How detection works

A **detection rule** says what counts as a surge. The default rule is *"5 or more open tickets in 30 minutes, same
category, similar title or description"*. Each rule also has an entity, a ticket type (incident / request / any), and
optional categories and location matching. You can run several rules side by side, e.g. a sensitive one for network
outages.

For every active rule, Surge:

1. **Collects candidates.** These are open tickets created within the rule's time window that are not already part of
   a surge.
2. **Joins live surges.** A candidate that matches a live surge (same category / location if the rule requires it, and
   text similar enough to the surge's centroid) is added to it. If the surge already has a master ticket, the new
   ticket is linked to it.
3. **Finds new surges.** The remaining candidates are grouped by category and location, then clustered by text
   similarity. Any group of at least `min_tickets` tickets becomes a new surge.

Text similarity is deliberately simple and transparent:

- **Normalisation.** HTML is stripped, text is lower-cased and diacritics are folded (Turkish ı/İ/ş/ğ and Latin
  accents), so "İNTERNET YOK" equals "internet yok".
- **Tokens and stop words.** Words shorter than 3 characters are dropped, as are English, Turkish and service-desk
  stop words ("hello", "please", "problem", "lütfen"…).
- **Prefix stemming (6 characters).** This merges most suffix variations of agglutinative languages ("bağlanamıyorum",
  "bağlantı" → *baglan*) and English inflections ("printers", "printing" → *printe*).
- **TF-IDF weighting** against the latest 1000 tickets. A word that is rare in everyday tickets but shared by the whole
  burst (*outlook*) carries the weight. Title words count twice.
- **Cosine similarity** between tickets, then **single-linkage clustering**. Two tickets belong together when a chain of
  similar pairs connects them.

Each ticket's similarity to the surge is shown on the surge page ("Match"). A wrong member can be removed with one click
and is never added back. **Save and test** on a rule shows what it would group right now, without creating anything, so
you can tune the threshold on real tickets.

## Installation

Requirements: GLPI 11.0+ or 12.0+, PHP 8.2+.

```bash
cd /var/www/glpi/plugins
sudo tar xzf /tmp/surge-0.1.0.tgz        # creates plugins/surge
sudo chown -R www-data:www-data surge
cd /var/www/glpi
sudo -u www-data php bin/console glpi:plugin:install surge -u <admin>   # GLPI 12: plugin:install
sudo -u www-data php bin/console glpi:plugin:activate surge             # GLPI 12: plugin:activate
```

Alternatively, extract the archive and use **Setup → Plugins → Surge → Install / Enable**.

The automatic action **surgeDetect** runs every 5 minutes; make sure GLPI's cron is configured (it usually is). The
menu entry is **Assistance → Surge**.

## Configuration

- **Assistance → Surge → Detection rules**: time window, minimum ticket count, same category / location, text
  similarity threshold (0.25 loose · 0.35 balanced · 0.5 strict), ticket type, categories, entity.
- **Assistance → Surge → Settings**:
  - urgency and impact of the master ticket;
  - whether solving the master solves all linked tickets;
  - automatic linking of late tickets;
  - how long a surge keeps collecting tickets;
  - webhook URL and format (generic JSON, Slack, Teams).
- **Administration → Profiles → Surge** — rights:

  | Right | Allows |
  |---|---|
  | See surges | Surge list and the banner on tickets |
  | Handle surges | Acknowledge, master ticket, problem, broadcast, solve, dismiss |
  | Detection rules and settings | Rules and settings pages |

  On install, profiles with configuration rights get everything and other central-interface profiles get the first two.
  Upgrades never change existing rights.

Actions respect GLPI rights. Creating the master ticket or the problem needs the matching GLPI right. Follow-ups and
solutions are only added to tickets the user may update; the others are skipped and reported.

### Webhook payload (generic)

```json
{
  "event": "detected",
  "surge": { "id": 12, "name": "Outlook won't open", "status": "new", "tickets": 9,
             "first": "2026-11-02 09:14:00", "last": "2026-11-02 09:27:00",
             "category": "Email", "location": "", "master_ticket": null, "problem": null,
             "url": "https://glpi.example.com/plugins/surge/front/surge.form.php?id=12" },
  "text": "⚡ Surge detected: \"Outlook won't open\" — 9 tickets · Email — https://…"
}
```

Events: `detected`, `master`, `resolved`. Slack and Teams receive `{"text": "…"}`.

## Development

```
setup.php, hook.php      plugin declaration, install / uninstall (additive upgrades)
src/Text.php             normalisation, stemming, TF-IDF, cosine, clustering
src/Detector.php         candidates, joining live surges, new surges
src/Surge.php            surge data, actions, automatic action
src/Rule.php, Config.php detection rules, settings
src/TicketHooks.php      hooks: detection on ticket creation, cascading solutions, ticket banner
src/Notifier.php         webhook
front/                   pages
locales/                 surge.pot, tr_TR, en_GB  (python tools/i18n.py extracts, merges and compiles)
tests/                   integration tests and demo data for a GLPI *test* instance
```

- **Integration tests** (`tests/surge_test.php`, 27 checks; `tests/surge_pages.sh`, 30 checks) run on a disposable
  GLPI 11 and GLPI 12 instance. They cover text processing, detection and every action.
- `tests/demo.php` creates the demo data used for the screenshots.
- **Translations:** run `python tools/i18n.py`. Translations live in `tools/<lang>.json`; pull requests for new
  languages are welcome.
- **Packaging:** run `python tools/package.py`, which writes `dist/surge-<version>.tgz`, `.zip` and `.sha256`.

## Roadmap

- Optional embedding similarity (local model) on top of TF-IDF.
- Status page / banner on the self-service portal ("We know about the mail outage").
- Surge statistics: mean time to detect, tickets absorbed per incident.
- Notify the on-call technician.

## License

Copyright © 2026 Pınar Topuz. Released under the [GNU General Public License v3.0 or later](LICENSE).
