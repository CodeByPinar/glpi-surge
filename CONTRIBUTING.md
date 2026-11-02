# Contributing to Surge

Thanks for taking the time to help! Bug reports, ideas, translations and pull requests are all welcome.

## Reporting a bug

Open an [issue](https://github.com/CodeByPinar/glpi-surge/issues) with:

- GLPI version, Surge version, PHP and database versions;
- what you did, what you expected and what happened;
- relevant lines from `files/_log/surge.log` and `files/_log/php-errors.log` (remove personal data).

For a detection problem ("these tickets should / should not be grouped"), include the ticket **titles**, the rule
settings and the scores shown on the surge page or in **Save and test**.

## Proposing a change

1. Fork the repository and create a branch from `main`.
2. Keep the code style of the surrounding code (PSR-12, short comments that explain *why*).
3. Make sure the plugin still works on **both GLPI 11 and GLPI 12**:
   - no CSRF-only APIs without a check;
   - no redeclared `$rightname`;
   - no `Html::displayRightError()` (use the HTTP exceptions).
4. Install and upgrade must stay **additive**: never change existing rules, surges, settings or rights.
5. Run the integration tests on a disposable GLPI instance (see the *Development* section of the README).
6. Open a pull request describing the change and how you tested it.

## Adding a language

1. Run `python tools/i18n.py` so `locales/surge.pot` is up to date.
2. Create `tools/<lang>.json` (for example `tools/de_DE.json`), copying the keys of `tools/tr_TR.json`. Translate the
   values and keep placeholders such as `%d`, `%s` and `%1$d` unchanged.
3. Run `python tools/i18n.py` again. It creates `locales/<lang>.po` and `locales/<lang>.mo`.
4. Optionally add stop words for the language to `Text::STOP` in `src/Text.php` (lower-case, without diacritics).

## Code of conduct

Be kind and constructive. Everyone is welcome here.
