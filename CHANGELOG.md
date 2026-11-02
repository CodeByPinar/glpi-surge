# Changelog

## [0.3.0] — 2026-11-02

- **Extension points** for other plugins: `surge_event` (detected, ticket joined, acknowledged, master, problem,
  broadcast, resolved, dismissed, reopened, notices, ticket removed), `surge_tabs` (add a tab to the Surge pages) and
  `surge_page` (add content to a surge page). See [docs/EXTENDING.md](docs/EXTENDING.md). A failing listener is
  logged and never breaks Surge.
- Fix: *Solve all tickets* on a surge with a master ticket reported "0 ticket(s) solved" — members solved through the
  master now count.

## [0.2.0] — 2026-11-02

Everything from the 0.1 roadmap.

- **Notices for end users**: publish a short agent-written text from a surge page. It is shown at the top of the
  self-service portal for users of the surge's entities, can be dismissed for the session, and turns into *"Resolved"*
  for a configurable time. Ticket details are never exposed. Optional display for central-interface users too.
- **Statistics** page: surges, tickets absorbed, median time to detect / resolve, handled-as-incident and dismissed
  rates, surges per week, top categories and locations (30 / 90 / 180 / 365 days).
- **On-call rota** (days, hours, overnight slots) and **e-mail on detection** to fixed users / groups and to whoever
  is on call, through GLPI's notification queue.
- **Semantic similarity** (optional, off by default): OpenAI-compatible `/v1/embeddings` endpoint you host (Ollama,
  llama.cpp, LocalAI…), cached vectors, blended with TF-IDF by a configurable weight. Falls back to TF-IDF when
  the endpoint is down. The API key is stored encrypted.
- **Languages**: French, German, Spanish and Portuguese (Brazil) user interface; stop words and diacritic folding for
  French, German, Spanish, Portuguese and Italian (plus English and Turkish).
- `plugin.xml` descriptor and logo for the GLPI plugin catalogue.
- Settings: unticked boxes are saved reliably; secrets are never shown back.

## [0.1.0] — 2026-11-02

First release.

- Real-time detection of bursts of similar tickets: on ticket creation and every 5 minutes (automatic action `surgeDetect`).
- Detection rules: time window, minimum count, same category / location, text similarity threshold, ticket type,
  categories, entity; "Save and test" dry run on current tickets.
- Text similarity without external services: normalisation with diacritic folding (Turkish, Latin), stop words,
  6-character prefix stemming, TF-IDF against the latest 1000 tickets, cosine similarity, single-linkage clustering.
- Surge page: tickets with similarity, arrival chart, common words; acknowledge, dismiss / reopen, remove a ticket.
- Major incident handling: master ticket with all members linked as children (late tickets linked automatically), problem
  with all members linked, public follow-up to all open tickets, solve all; solving the master solves every member.
- Banner on tickets that belong to a surge.
- Webhook (generic JSON, Slack, Microsoft Teams) on detection, master ticket and resolution.
- Rights: see, handle, configure. English and Turkish. GLPI 11 and 12.
