# 0.4.0: Single translation sheet, translations:check

Date: 2026-10-05
Status: Draft for review

## Why

CaRMS (`~/Docker/_vhosts/carms.test/webroot`) is the project proving this
package. Before adopting it, CaRMS ran its own single-sheet sync with a
`translations:check` CI command. Moving to the package's tab-per-locale layout
lost three things CaRMS relies on:

1. One tab where reviewers see every locale side by side, with status and
   notes columns.
2. Placeholder protection on pull. A translator who drops `:name` in the sheet
   currently gets the broken line written into the lang file.
3. A CI check that every locale has the same keys and placeholders as English,
   covering PHP files, JSON files and vendor overrides.

0.4.0 replaces the per-locale tabs with one sheet as the source of truth and
moves the check into the package. Breaking changes are acceptable: the package
has few users and no migration tooling is needed.

## Scope

In scope:

- Single `Translations` tab replacing `Translations - {locale}` tabs.
- `translations:check` command covering PHP, JSON and vendor translations.
- Pull rejects rows whose placeholders don't match English.
- Generic sheet formatting and a `TranslationsPushed` event for app-specific
  additions.
- Laravel 11 to 13 support in the test matrix.

Out of scope (later release):

- Syncing JSON (`lang/{locale}.json`) and vendor (`lang/vendor/...`) lines to
  the sheet. The check reads them; push and pull do not.
- CaRMS-specific sheet content: Read me tab, Glossary tab, status dropdown,
  custom row order. CaRMS adds these through the event.
- Durable "needs review" flags in the sheet.

## Sheet layout

One tab, named by config `sheet` (default `Translations`). Row 1 is the
header. Columns are found by header text, not position.

| key | group | default | en | fr | … | status | notes |
|---|---|---|---|---|---|---|---|
| `failed` | `auth` | These credentials… | These credentials… | Identifiants invalides. | | reviewed | |

- `key`: the dotted key within the file, e.g. `form.name`.
- `group`: the file, relative to `lang/{locale}/` without `.php`, e.g. `auth`
  or `resources/schools`. Matches how `__()` addresses it.
- `default`: the source-locale (English) value in code as of the last push.
  The baseline for detecting who changed the English. Protected; can be
  hidden.
- `en`: the source-locale column (header = config `source_locale`). Editable.
  Always filled.
- One column per other locale. A header is a locale column when `lang/` has a
  directory with that name.
- Any other header is an unrecognised column. Its values travel with their
  row on every push. This is how `status`, `notes`, or anything else a project
  adds survives.

Required headers: `key`, `group`, `default`, and the source locale. If an
existing tab lacks any of them, push and pull stop with an error naming the
missing headers.

Column order written by push: `key, group, default, {source}`, then locale
columns in their existing order, then new locales (alphabetical), then
unrecognised columns in their existing order.

Row order: by group, then key (string sort).

## Components

### New

- **`Support\Placeholders`**: ported unchanged from CaRMS.
  `in(string): list<string>` returns lower-cased, unique, sorted names;
  `match(string, string): bool`. Pattern
  `/(?<![\p{L}\p{N}_]):([a-zA-Z_][a-zA-Z0-9_]*)/u`, so `10:30`, `https://`
  and a colon before a space are not placeholders. Case-insensitive because
  Laravel treats `:name`, `:Name`, `:NAME` as one replacement.
- **`Services\TranslationCatalogue`**: ported from CaRMS, generalised.
  - `lines(string $locale): array<group, array<key, string>>`, sorted by group
    and key. Non-string values are dropped.
  - Groups: PHP files under `lang/{locale}/` including subdirectories
    (`resources/schools`); `lang/{locale}.json` as group `*`; vendor overrides
    as `{namespace}::{file}`.
  - Vendor namespaces: directories under `lang/vendor/` that contain any
    non-source locale directory (CaRMS hard-coded `fr`).
  - Vendor source lines: the published `lang/vendor/{ns}/{source}` if present,
    otherwise the package's own files via the translator loader's namespace
    hints.
  - JSON source fallback: when `lang/{source}.json` is absent, the source
    locale's `*` group is built from the union of other locales' JSON keys,
    each key mapping to itself. Laravel renders a missing JSON key as the key,
    so this is the English the app actually shows.
  - `appGroups(string $locale)`: `lines()` filtered to PHP app groups (no `*`,
    no `::`). Push and pull use this.
- **`Sheet\SheetRow`**: value object. `group`, `key`, `default`,
  `values: array<locale, string>`, `extras: array<header, string>`.
- **`Sheet\TranslationSheet`**: reads and writes the tab through
  `GoogleSheetsService`.
  - `read(): ?SheetContents` returns null when the tab is missing; otherwise
    the parsed header (locale columns, unrecognised columns) and rows. Short
    rows are padded with empty strings. Fully empty rows are skipped. Throws
    on missing required headers.
  - `write(array $header, list<SheetRow>)`: creates the tab if missing,
    writes header and rows from `A1`, then clears leftover rows below and
    columns to the right of the written block.
- **`Sheet\SheetFormatter`**: builds one `batchUpdate` request list, applied
  after each push when config `format` is true:
  - freeze row 1 and column 1;
  - basic filter on the header row (only added if the tab has none);
  - column widths; wrap and top-align body cells;
  - grey background on `key`, `group`, `default`;
  - red background on an empty locale cell in a row with a key (conditional
    format);
  - warning-only protected range over `key`, `group`, `default`.
  
  Re-runs must be idempotent: conditional rules and the protected range the
  package owns are found by a fixed description, deleted and re-added.
  Approach ported from CaRMS `SheetStyle`.
- **`Events\TranslationsPushed`**: dispatched at the end of a successful,
  non-dry-run push. Properties: `sheetName`, `sheetId`, `rows` (the written
  `SheetRow` list). Listeners reach the Google client via
  `app(GoogleSheetsService::class)`.
- **`Commands\CheckCommand`** (`translations:check`): see below.

### Rewritten

- **`Services\TranslationReconciler`**: input is the code catalogue for every
  locale (app groups) and the existing sheet rows (empty on first push or
  `--fresh`). Output is the new `SheetRow` list and a report. Rules below.
- **`Commands\PushCommand`** and **`Commands\PullCommand`**: rebuilt on the
  components above.

### Kept

- `GoogleSheetsService`: unchanged.
- `TranslationFileWriter`: unchanged. Pull calls it with
  `lang/{locale}/{group}.php`. The 0.3.1 `splitKey` heuristic is removed; the
  group column makes it unnecessary.
- `DiscoversLocales`: unchanged.
- `TranslationBackupManager`: one snapshot per push (the whole tab) instead
  of one per locale. Files at `{backup path}/{timestamp}.json`; pruning keeps
  the newest `keep` files.

### Removed

- `Support\TranslationConventions` and per-locale tab naming.
- `PullCommand::splitKey`.

## Push: `translations:push [--dry-run] [--no-backup] [--fresh]`

No locale argument; push always rewrites the whole tab.

1. Discover locales under `lang/` (excluding `vendor` and dot directories).
   Fail if the source locale directory is missing.
2. Read each locale's app groups from the catalogue.
3. Read the tab. Missing: it will be created. Missing required headers: fail.
4. Unless `--no-backup`, `--dry-run`, or the tab is empty: back up the rows.
5. Reconcile (below). With `--fresh`, existing rows are ignored.
6. Build the header (column order above) and sort rows.
7. Unless `--dry-run`: write, format (if enabled), dispatch
   `TranslationsPushed`.
8. Print the report and the tab URL.

### Reconcile rules

The row set is every `(group, key)` present in any locale's code.

Source columns, comparing code (C), sheet `default` (D) and sheet source (E):

| Case | default | source column | Report |
|---|---|---|---|
| New row | C | C | new |
| C = D | D | E (keeps an editor's edit) | unchanged |
| C ≠ D, E = D (no edit) | C | C | english changed |
| C ≠ D, C = E (edit was pulled) | C | E | unchanged |
| C ≠ D, E ≠ D, C ≠ E | C | E (sheet wins) | conflict, listed |
| Key only in non-source code | empty | empty | non-source only |

A blank E on an existing row counts as "no edit" and is filled with C.

"English changed" rows that already have any non-blank locale cell are listed
as needing translation review.

Locale columns: keep a non-blank sheet value; otherwise use that locale's code
value if any; otherwise blank. Cells filled from code are counted.

Unrecognised columns: copied from the existing row with the same
`(group, key)`.

Sheet rows whose `(group, key)` is not in any locale's code are dropped and
listed as removed.

Known limitation: an edit made in the sheet between push's read and write is
overwritten. The Sheets API has no conditional write. The backup holds the
pre-push state.

## Pull: `translations:pull {locale?} [--dry-run]`

1. Read the tab. Missing: fail ("run translations:push first"). Missing
   required headers: fail.
2. Locales: every locale column with a directory under `lang/`, or only the
   named locale. A named locale with no column fails.
3. For each row whose group is an app group (no `*`, no `::`):
   - Blank cell: skipped. Pull never writes an empty string.
   - Source locale: value is the source column. Its placeholders must match
     `default`.
   - Other locales: placeholders must match the row's sheet source value.
   - When the value being compared against is blank (a non-source-only row,
     or an empty `default`), no placeholder check is made; `translations:check`
     reports those keys instead.
   - Mismatch: row rejected, listed with locale, group, key, expected and
     actual placeholders. Not written.
4. Group accepted values by `(locale, group)` and write each through
   `TranslationFileWriter` to `lang/{locale}/{group}.php`. Missing files,
   missing keys and non-string values are warned about as in 0.3.
5. `--dry-run`: print per-file counts and any rejections; write nothing.
6. Exit code: failure if any row was rejected (after writing the accepted
   rows) or if any locale errored; success otherwise.

## Check: `translations:check [--allow-missing]`

Compares every discovered non-source locale against the source locale over
the full catalogue (PHP, JSON, vendor). No Google access.

Problems:

- **Missing from {locale}**: a source key the locale lacks.
- **Not in {source}**: a locale key the source lacks.
- **Placeholders differ**: both have the key; `Placeholders::match` fails.
  Message shows both placeholder lists.

Output: a table `Locale | Group | Key | Problem`, then an error line with the
count. With no problems: an info line with key and group counts per locale.

Exit code: failure when any problem is found. With `--allow-missing`,
"Missing from" rows are still shown but don't cause failure.

No non-source locales: info message, success.

## Config

```php
'credentials_path' => env('TRANSLATION_CREDENTIALS_PATH', storage_path('app/laravel-translation-credentials.json')),
'spreadsheet_id' => env('TRANSLATION_SPREADSHEET_ID'),
'scopes' => ['https://www.googleapis.com/auth/spreadsheets'],
'sheet' => env('TRANSLATION_SHEET', 'Translations'),
'source_locale' => 'en',
'format' => env('TRANSLATION_FORMAT_SHEET', true),
'backup' => [ /* path, keep, auto_prune: unchanged */ ],
```

Removed: `key_column`, `original_value_column`, `updated_value_column`,
`header_row`.

## Testing

PHPUnit with Orchestra Testbench, mocked `GoogleSheetsService` as today.

- `Placeholders`, `TranslationCatalogue`, `CheckCommand`: port CaRMS tests
  (`tests/Feature/Console/Commands/CheckTranslationsTest.php`,
  `tests/Feature/TranslationKeysTest.php`), generalised to several locales.
  Add: JSON fallback without `en.json`; vendor namespace with a non-`fr`
  locale; `--allow-missing`.
- `TranslationReconciler`: one test per row of the reconcile table; locale
  fill from code; unrecognised columns kept; removed rows; column order with
  a new locale.
- `TranslationSheet`: header parsing, short rows, missing required headers,
  trailing clear ranges.
- `PushCommand` / `PullCommand`: end-to-end with mocks; placeholder
  rejection and exit code; blank cells; missing tab; dry runs; event
  dispatched (and not on dry run); `--fresh`.
- `SheetFormatter`: request list contains the managed protection and rules;
  idempotent deletion of previously managed ones.
- `composer.json` dev: widen `orchestra/testbench` to include the majors for
  Laravel 11, 12 and 13, and `phpunit/phpunit` to `^11.0|^12.0`. Confirm the
  exact testbench major for Laravel 13 against Packagist when implementing
  (expected `^11.0`). Convert all `/** @test */` to `#[Test]` (PHPUnit 12
  drops annotations).

## Release

- Version 0.4.0 in `composer.json` and `CHANGELOG.md`.
- README rewritten for the single sheet, check command and event. Note that
  CI running `translations:check` must install dev dependencies.
- Upgrade steps in CHANGELOG:
  1. On 0.3.1, run `translations:pull` so code holds the latest translations.
  2. Upgrade to 0.4.0; update a published config to the new keys.
  3. Run `translations:push` to build the `Translations` tab.
  4. Delete the old `Translations - {locale}` tabs.

## CaRMS follow-up (not part of this package work)

- Require `paper-leaf-tech/laravel-translation: ^0.4`.
- Delete `CheckTranslations`, `TranslationCatalogue`, `Placeholders` and
  their tests; the CI step keeps working because the command name is the
  same.
- Add a `TranslationsPushed` listener for the Read me tab, Glossary tab and
  status dropdown, ported from the deleted `SheetStyle` and `Glossary`.
