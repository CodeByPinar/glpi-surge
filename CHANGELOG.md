# Changelog

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
