# 0.5.0: Single translation sheet

Date: 2026-10-05
Status: Revised for review

## Revision note

The first draft targeted 0.4.0 and included `Placeholders`,
`TranslationCatalogue`, `translations:check` and a placeholder guard on pull.
Those shipped separately in v0.4.0 (commits `253faa7`, `464d6a8`, `9af81e7`)
while this spec was being written. This revision:

- targets **0.5.0**;
- treats `Placeholders`, `TranslationCatalogue` and `CheckCommand` as
  existing code, changed only where the single sheet needs it;
- keeps 0.4.0's placeholder reference on pull (the source line in code)
  instead of the sheet's English. Code holds the English Laravel substitutes
  into, so it is the stronger reference.

## Why

CaRMS (`~/Docker/_vhosts/carms.test/webroot`) is the project proving this
package. Before adopting it, CaRMS ran its own single-sheet sync. The
package's tab-per-locale layout loses what CaRMS relies on: one tab where
reviewers see every locale side by side, with status and notes columns that
survive each push.

0.5.0 replaces the per-locale tabs with one sheet as the source of truth.
Breaking changes are acceptable: the package has few users and no migration
tooling is needed.

## Scope

In scope:

- Single `Translations` tab replacing `Translations - {locale}` tabs.
- Push and pull rebuilt around it, with the sheet's English editable against
  a `default` baseline.
- Generic sheet formatting and a `TranslationsPushed` event for app-specific
  additions.
- Source locale configurable (`source_locale`), used by check, push and pull.
- Catalogue: source JSON fallback when `lang/{source}.json` is absent; a clear
  error for malformed JSON.
- Laravel 11 to 13 in the test matrix.

Out of scope (later release):

- Syncing JSON (`lang/{locale}.json`) and vendor (`lang/vendor/...`) lines to
  the sheet. `translations:check` reads them; push and pull do not.
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
- `default`: the source-locale value in code as of the last push. The
  baseline for detecting who changed the English. Protected; can be hidden.
- Source locale column (header = config `source_locale`, default `en`).
  Editable. Always filled.
- One column per other locale. A header is a locale column when `lang/` has a
  directory with that name.
- Any other non-blank header is an unrecognised column. Its values travel
  with their row on every push. This is how `status`, `notes`, or anything
  else a project adds survives.

Header validation (push and pull):

- Required headers: `key`, `group`, `default`, and the source locale. Missing
  any: error naming them.
- Two columns with the same header: error.
- A blank header over a column that has values: error naming the column
  letter. A blank header over an empty column is ignored.

Column order written by push: `key, group, default, {source}`, then locale
columns in their existing order, then new locales (alphabetical), then
unrecognised columns in their existing order.

Row order: by group, then key (byte-wise string comparison).

Rows: key and group cells are trimmed; every other cell is kept exactly,
including leading/trailing whitespace and newlines. Fully blank rows are
skipped. When two rows share a `(group, key)`, the first is used and the
later ones are reported as duplicates.

## Components

### Existing, changed

- **`Support\TranslationConventions`**: slimmed to the source locale.
  `sourceLocale(): string` reads config `source_locale` (default `en`);
  `isSourceLocale(string): bool`. Sheet naming, header constants and the
  `SOURCE_LOCALE` constant are removed once nothing uses them.
- **`Services\TranslationCatalogue`**:
  - `appGroups(string $locale)`: the app's PHP groups only (no `*`, no
    vendor). Push and pull use this.
  - JSON source fallback: when `lang/{source}.json` is absent, the source
    locale's `*` group is the union of the other `lang/*.json` keys, each
    mapped to itself. Laravel renders a missing JSON key as the key.
  - Malformed `lang/{locale}.json`: throws a `RuntimeException` naming the
    file, instead of reading it as empty.
- **`Commands\CheckCommand`**: uses `TranslationConventions::sourceLocale()`.
  Otherwise unchanged (its 0.4.0 behaviour stands: optional `{lang}`,
  `--allow-missing` allows missing and extra keys but not placeholder
  mismatches, vendor extras not reported).

### New

- **`Sheet\SheetRow`**: readonly value object. `group`, `key`, `default`,
  `values: array<locale, string>` (source included), `extras:
  array<header, string>`. `id()`, `label()` (`group.key`), `value($locale)`.
- **`Sheet\SheetContents`**: readonly. `locales` (non-source locale headers in
  sheet order), `extras` (unrecognised headers in sheet order), `rows`,
  `duplicates` (labels), `grid` (raw cells including the header, for
  backups).
- **`Sheet\TranslationSheet`**: reads and writes the tab through
  `GoogleSheetsService`.
  - `read(string $source, array $locales): ?SheetContents`: null when the
    tab is missing; an empty `SheetContents` when the tab has no cells.
    Applies the header validation and row rules above.
  - `write(array $headers, array $rows)`: creates the tab if missing, writes
    header and rows from `A1`, then clears leftover rows below and columns
    to the right of the written block.
- **`Sheet\SheetReconciler`**: code catalogue for every locale plus the
  existing `SheetContents` (or null) in; headers, sorted rows and a report
  out. Rules under Push.
- **`Sheet\SheetFormatter`**: builds one `batchUpdate` request list, applied
  after each push when config `format` is true:
  - freeze row 1 and column 1;
  - header row bold on a dark background;
  - basic filter over all columns (only added if the tab has none);
  - column widths for `key`, `group`, `default` and locale columns; wrap and
    top-align body cells;
  - grey background on `key`, `group`, `default`;
  - red background on an empty locale cell in a row with a key (one
    conditional rule per locale column);
  - warning-only protected range over `key`, `group`, `default`.

  Re-runs are idempotent. The package's conditional rules carry the marker
  `N("laravel-translation")=0` in their formula (always true, so harmless)
  and are deleted and re-added; rules without it are left alone. The
  protected range is found by a fixed description and replaced.
- **`GoogleSheetsService`** gains two thin methods for the formatter:
  `getSheet(string): ?Sheet` (tab metadata: properties, conditional formats,
  protected ranges, basic filter) and `batchUpdate(array $requests): void`.
- **`Events\TranslationsPushed`**: dispatched after a successful,
  non-dry-run push. Properties: `sheetName`, `sheetId`, `headers`, `rows`.
  Listeners reach the Google client via `app(GoogleSheetsService::class)`.

### Rewritten

- **`Commands\PushCommand`**, **`Commands\PullCommand`**.
- **`Services\TranslationBackupManager`**: one snapshot of the tab per push,
  at `{backup path}/{timestamp}.json`; pruning keeps the newest `keep` JSON
  files in that directory and ignores 0.4 per-locale subdirectories.

### Removed

- `Services\TranslationReconciler` (replaced by `SheetReconciler`).
- `PullCommand::splitKey` and the sheet-key mapping helpers.

## Push: `translations:push [--dry-run] [--no-backup] [--fresh]`

No locale argument; push always rewrites the whole tab.

1. Discover locales under `lang/` (excluding `vendor` and dot directories).
   Fail if the source locale directory is missing.
2. Read the tab. Header errors: fail before anything is written.
3. Read each locale's app groups from the catalogue.
4. Unless `--no-backup`, `--dry-run`, or the tab has no rows: back up the
   tab's raw cells. Disabled backups print a notice; a failed backup warns
   and push continues.
5. Reconcile. With `--fresh`, existing rows are ignored (the backup still
   runs).
6. Print the report.
7. Unless `--dry-run`: write, format (if enabled), dispatch
   `TranslationsPushed`, print the tab URL.

### Reconcile rules

The row set is every `(group, key)` in any locale's code.

Source columns, comparing code (C), sheet `default` (D) and the sheet's
source cell (E). A blank E on an existing row is read as D (no edit).

| Case | default | source column | Report |
|---|---|---|---|
| New row | C | C | new |
| C = D | D | E (keeps an editor's edit) | — |
| C ≠ D, E = D (no edit) | C | C | source changed |
| C ≠ D, E = C (edit was pulled) | C | E | — |
| C ≠ D, E ≠ D, E ≠ C | C | E (sheet wins) | conflict |
| Key absent from source code | blank | existing E, else blank | missing from source |

"Source changed" rows whose existing row has any non-blank locale cell are
also listed as needing translation review.

Locale columns: a non-blank sheet value is kept; otherwise that locale's code
value, if any; otherwise blank. On existing rows, cells filled from code are
counted.

Unrecognised columns: copied from the existing row with the same
`(group, key)`; blank on new rows.

Existing rows whose `(group, key)` is not in any locale's code are dropped
and listed as removed.

Known limitation: an edit made in the sheet between push's read and write is
overwritten. The Sheets API has no conditional write. The backup holds the
pre-push state.

## Pull: `translations:pull {locale?} [--dry-run]`

1. Read the tab. Missing: fail ("run translations:push first"). Header
   errors: fail.
2. Locales: the source locale plus every locale column with a directory under
   `lang/`, or only the named locale. A named locale with no such column
   fails.
3. For each row with a non-blank group and key, whose group is not `*` and
   has no `::`:
   - Blank cell: skipped. Pull never writes an empty string.
   - Placeholder guard: the value's placeholders must match the source line
     in code (`appGroups($source)`) for that group and key. When code has no
     source line, no check is made; `translations:check` reports the key.
   - Mismatch: rejected, listed with locale, key, expected and found
     placeholders, and not written.
4. Write accepted values per `(locale, group)` through
   `TranslationFileWriter` to `lang/{locale}/{group}.php`. Missing files,
   missing keys and non-string values are warned about as in 0.4.
5. `--dry-run`: print per-file counts and rejections; write nothing.
6. Exit code: failure if any row was rejected (after writing the accepted
   rows); success otherwise.

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

PHPUnit with Orchestra Testbench. Sheet-facing tests use an in-memory
`FakeGoogleSheetsService` (a subclass that stores cells) instead of Mockery,
so push and pull can be tested against real sheet state.

- `TranslationCatalogue`: `appGroups`, JSON fallback, malformed JSON.
- `CheckCommand`: existing tests keep passing; one for a configured source
  locale; one for JSON without a source file.
- `TranslationSheet`: header parsing and validation, short rows, blank rows,
  whitespace kept, duplicates, write and trailing clears.
- `SheetReconciler`: one test per reconcile-table row; locale fill; extras;
  removed rows; header order; deleted-locale column kept.
- `SheetFormatter`: requests built; managed rules and protection replaced,
  others untouched; filter only when absent.
- `PushCommand` / `PullCommand`: end to end against the fake.
- `composer.json` dev: `orchestra/testbench: ^9.0|^10.0|^11.0` (11 is
  Laravel 13), `phpunit/phpunit: ^11.5|^12.5`. Convert all `/** @test */` to
  `#[Test]`.

## Release

- Version 0.5.0 in `composer.json` and `CHANGELOG.md`.
- README rewritten for the single sheet and the event.
- Upgrade steps in CHANGELOG:
  1. On 0.4.0, run `translations:pull` so code holds the latest translations.
  2. Upgrade to 0.5.0; update a published config to the new keys.
  3. Run `translations:push` to build the `Translations` tab.
  4. Delete the old `Translations - {locale}` tabs.

## CaRMS follow-up (not part of this package work)

- Require `paper-leaf-tech/laravel-translation: ^0.5`.
- Add a `TranslationsPushed` listener for the Read me tab, Glossary tab and
  status dropdown, ported from the deleted `SheetStyle` and `Glossary`.
