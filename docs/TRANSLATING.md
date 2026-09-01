<!--
  - SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Translating Kanso

Kanso follows each user's Nextcloud language automatically — a German user sees
German, a French user sees French — as long as a translation exists for that
language. Translations are very welcome, and you don't need to write any code to
contribute one.

## How it works

- Every user-facing string in the app is wrapped for translation:
  `t('kanso', '…')` / `n('kanso', …)` in the Vue frontend, `$l->t('…')` /
  `$l->n(…)` in the PHP backend (templates, notifications, activity, setup
  checks).
- `translationfiles/templates/kanso.pot` is the **template** — the full list of
  English source strings, regenerated from the code.
- `translationfiles/<lang>/kanso.po` is the **translation** for one language
  (e.g. `de` for German). This is the only file you edit.
- `l10n/<lang>.js` and `l10n/<lang>.json` are **generated** from the `.po` and
  are what Nextcloud actually loads (`.js` for the frontend, `.json` for the
  backend). Don't hand-edit these — run the compile step instead.

Language codes are Nextcloud language codes: `de`, `fr`, `es`, `it`, `nl`,
`pt_BR`, … (the code Nextcloud uses for **translations**, not the `de_DE`-style
locale used only for date formatting).

## Add or update a translation

1. Make sure the template is current:

   ```bash
   npm run l10n:extract        # refresh translationfiles/templates/kanso.pot
   ```

2. Start a new language from the template (skip if the `.po` already exists):

   ```bash
   node scripts/l10n.mjs init <lang>     # e.g. node scripts/l10n.mjs init fr
   ```

   `init` stamps the right `Plural-Forms` header for the language and writes
   exactly that many `msgstr[n]` slots — one for Chinese, two for German or
   French, three for Polish or Russian. A language code the tool doesn't know
   gets the two-form default; correct its `Plural-Forms` header by hand and then
   run the sync below to re-slot the plural entries.

   For an existing language, merge new template strings into the `.po`:

   ```bash
   npm run l10n:sync                     # every language
   npm run l10n:sync fr                  # just one
   ```

   Sync adds new strings with an empty `msgstr`, refreshes the `#:` source
   references, drops strings the app no longer has, and never touches an
   existing translation or the catalogue's `Plural-Forms` header. No `gettext`
   installation needed.

3. Fill in the `msgstr` values. Edit `translationfiles/<lang>/kanso.po` in a text
   editor or a PO editor such as [Poedit](https://poedit.net/). Leave a `msgstr`
   empty to fall back to the English source — partial translations are fine.

   **Keep placeholders exactly as they are** — `{count}`, `{name}`, `{card}`,
   `%n`, `%1$s` — you may move them within a sentence but never rename or
   translate them. Fill in every `msgstr[n]` slot a plural entry has — how many
   there are is your language's business (`msgstr[0]` only for Chinese,
   `msgstr[0..1]` for German or French, `msgstr[0..2]` for Polish or Russian),
   and `init` already wrote the right number.

4. Compile and check it in:

   ```bash
   npm run l10n:compile        # writes l10n/<lang>.js and l10n/<lang>.json
   ```

   Commit the `.po` **and** the generated `l10n/<lang>.{js,json}`.

## Verifying in Nextcloud

Set your Nextcloud account language (Settings → Personal information → Language)
to your target language and open Kanso. If a string still shows in English,
either its `msgstr` is empty or the app JS is cached — a hard reload (or a fresh
browser profile) picks up the new `l10n/<lang>.js`.

## Notes for developers

- New source strings must be wrapped in `t('kanso', …)` / `$l->t(…)` to be
  translatable, then picked up by `npm run l10n:extract`. CI fails if the
  extracted template or the compiled bundles are stale (the `build-frontend`
  job re-runs extract + compile and diffs), so commit the regenerated files.
- CI also runs `npm run l10n:lint` on every `translationfiles/<lang>/kanso.po`:
  it rejects malformed PO syntax and a `Plural-Forms` header that doesn't match
  the `msgstr[n]` forms actually supplied, and — the main point — diffs the
  placeholders (`{count}`, `%n`, `%1$s`, …) in every non-empty `msgstr` against
  those in its `msgid`/`msgid_plural`. A renamed or dropped placeholder fails
  the build with the language and msgid, instead of only breaking at runtime
  for users of that language. Empty `msgstr`s (untranslated) are never
  checked. Run it locally with `npm run l10n:lint`; its own fixture tests are
  `npm run test:l10n`.
- Built-in enum labels (card types, priority levels, filter facets, swimlane
  groups) are wrapped **at their definition** — `label: t('kanso', 'Bug')` in
  `useCardType.js`, `usePriority.js`, `useBoardFilters.js`, `useSwimlanes.js`,
  etc. — and rendered directly, so the extractor sees them like any other
  literal. Add a new enum label the same way; there's no separate string
  manifest to keep in sync.
