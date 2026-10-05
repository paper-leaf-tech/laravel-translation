# Single Translation Sheet (0.5.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the per-locale `Translations - {locale}` tabs with one `Translations` tab (`key | group | default | en | fr | … | extras`) that push and pull use as the single source of truth.

**Architecture:** `TranslationSheet` reads and writes the tab by header name into `SheetRow` value objects. `SheetReconciler` merges code (from the existing `TranslationCatalogue`) with the tab's current rows. `SheetFormatter` builds idempotent Google formatting requests. Push and pull become thin commands over these pieces. `TranslationFileWriter`, `Placeholders` and `translations:check` (all shipped in 0.4.0) are reused.

**Tech Stack:** PHP 8.2+, Laravel 11–13, spatie/laravel-package-tools, google/apiclient (Sheets v4), nikic/php-parser, PHPUnit 11/12 with Orchestra Testbench 9–11.

**Spec:** `docs/superpowers/specs/2026-10-05-single-sheet-design.md`

## Global Constraints

- Package version for this release: `0.5.0` (in `composer.json` and `CHANGELOG.md`). `v0.4.0` is already tagged; do not reuse it.
- `require.php` stays `^8.2`: no typed class constants (`const string X`), no PHP 8.3+ syntax in `src/`.
- Dev constraints: `orchestra/testbench: ^9.0|^10.0|^11.0`, `phpunit/phpunit: ^11.5|^12.5`.
- Tests use `#[Test]` attributes (`PHPUnit\Framework\Attributes\Test`); no `/** @test */`.
- Sheet headers are exactly `key`, `group`, `default`, then locale codes; config `sheet` default `Translations`; config `source_locale` default `en`; config `format` default `true`.
- Config keys removed: `key_column`, `original_value_column`, `updated_value_column`, `header_row`.
- Only `key` and `group` cells are trimmed. Every other cell value is kept byte-for-byte.
- Pull never writes an empty string to a lang file.
- Pull's placeholder reference is the source-locale line **in code**, not the sheet.
- The published config must not reference Google classes (keep the literal scope URL).
- Run `vendor/bin/pint <paths you changed>` before each commit; never run Pint on the whole repo (it reformats untouched files).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. **Duplicate `(group, key)` rows** (a reviewer copy-pastes a row): the first row wins and push/pull say so. Push must not write both. Tests: Task 3 `it_keeps_the_first_of_duplicate_rows`, Task 6 `it_warns_about_duplicate_rows_and_keeps_the_first`, Task 7 `it_uses_the_first_of_duplicate_rows_and_warns`.
2. **A column with values but no header**: push would silently drop it. Read must fail and name the column letter. Test: Task 3 `it_rejects_values_under_a_blank_header`.
3. **A locale column whose `lang/` directory was deleted**: its values must be kept as an unrecognised column, not wiped. Tests: Task 4 `it_keeps_extra_columns_in_header_order`, Task 6 `it_keeps_the_column_of_a_locale_whose_directory_was_removed`.
4. **Newlines and surrounding spaces in translations** (multi-line emails, a trailing non-breaking space before `:`): must survive push and pull unchanged. Tests: Task 3 `it_trims_only_key_and_group`, Task 6 `it_keeps_newlines_and_spaces_in_cells`, Task 7 `it_keeps_newlines_and_surrounding_spaces_in_values`.
5. **Malformed `lang/{locale}.json`**: must fail loudly with the file path instead of being read as empty (which makes check report every key as missing). Test: Task 2 `it_names_the_file_when_json_is_malformed`.

---

## File Structure

| File | Status | Responsibility |
|---|---|---|
| `composer.json`, `phpunit.xml` | modify | Laravel 11–13 test matrix, PHPUnit 12 config |
| `tests/TestCase.php` | modify | temp lang path, `writeLangFiles`, `fakeSheets`, format off by default |
| `tests/Support/FakeGoogleSheetsService.php` | create | in-memory sheet for tests |
| `src/Support/TranslationConventions.php` | modify | source locale from config only |
| `src/Services/TranslationCatalogue.php` | modify | `appGroups()`, JSON fallback, malformed-JSON error |
| `src/Commands/CheckCommand.php` | modify | configured source locale |
| `src/Sheet/SheetRow.php` | create | one row value object |
| `src/Sheet/SheetContents.php` | create | parsed tab: locale/extra headers, rows, duplicates, raw grid |
| `src/Sheet/TranslationSheet.php` | create | read/write the tab by header name |
| `src/Sheet/ReconcileReport.php` | create | what a push changed |
| `src/Sheet/ReconcileResult.php` | create | headers + rows + report |
| `src/Sheet/SheetReconciler.php` | create | merge code with the tab |
| `src/Sheet/SheetFormatter.php` | create | idempotent formatting requests |
| `src/Services/GoogleSheetsService.php` | modify | `getSheet()`, `batchUpdate()` |
| `src/Services/TranslationBackupManager.php` | modify | one snapshot per push |
| `src/Events/TranslationsPushed.php` | create | post-push hook |
| `src/Commands/PushCommand.php` | rewrite | single-sheet push |
| `src/Commands/PullCommand.php` | rewrite | single-sheet pull |
| `src/Services/TranslationReconciler.php` | delete | replaced by `SheetReconciler` |
| `src/LaravelTranslationServiceProvider.php` | modify | drop old reconciler binding |
| `config/laravel-translation.php` | rewrite | new keys |
| `README.md`, `CHANGELOG.md` | modify | docs, 0.5.0 entry |

---

### Task 1: Test tooling for Laravel 13 and the in-memory sheet

**Files:**
- Modify: `composer.json`, `composer.lock`, `phpunit.xml`, every file under `tests/` containing `/** @test */`
- Modify: `tests/TestCase.php`, `tests/Feature/CheckCommandTest.php`
- Create: `tests/Support/FakeGoogleSheetsService.php`, `tests/Unit/FakeGoogleSheetsServiceTest.php`

**Interfaces:**
- Produces: `TestCase::useTemporaryLangPath(): string`, `TestCase::temporaryDirectory(string $name): string`, `TestCase::writeLangFiles(array $files, ?string $root = null): void`, `TestCase::fakeSheets(): FakeGoogleSheetsService`.
- Produces: `FakeGoogleSheetsService` (extends `GoogleSheetsService`) with public `seed(string $tab, array $rows): void`, `rows(string $tab): array` (trimmed like Google), public `array $batches` (each `batchUpdate` call's requests), public `array $metadata` (tab => extra `Sheet` fields), and overrides of `getSheetId`, `createSheetIfMissing`, `getSheetData`, `updateSheetData`, `clearSheetData`, `getSpreadsheetUrl`, `getSheet`, `batchUpdate`.
- Note: `getSheet()` and `batchUpdate()` are added to the real `GoogleSheetsService` in Task 5. In this task the fake declares them as plain public methods (no parent to override yet); Task 5 makes them overrides without changing the fake.

- [ ] **Step 1: Widen dev dependencies and update**

```bash
composer require --dev "orchestra/testbench:^9.0|^10.0|^11.0" "phpunit/phpunit:^11.5|^12.5" --no-update
composer update -W
```

Expected: upgrades to `laravel/framework v13.x`, `orchestra/testbench v11.x`, `phpunit/phpunit 12.x` (verified by a dry run on PHP 8.4).

- [ ] **Step 2: Migrate the PHPUnit config to the installed schema**

```bash
vendor/bin/phpunit --migrate-configuration
```

Expected: `phpunit.xml` now references the 12.x schema (a `phpunit.xml.bak` may be created; delete it).

- [ ] **Step 3: Convert annotations to attributes**

```bash
for f in $(grep -rl '/\*\* @test \*/' tests); do
  perl -0pi -e 's#/\*\* \@test \*/#\#[Test]#g; s#(\nuse [^\n]+;\n)(?!use )#$1use PHPUnit\\Framework\\Attributes\\Test;\n#' "$f"
done
grep -rL 'use PHPUnit\\Framework\\Attributes\\Test;' $(grep -rl '#\[Test\]' tests)
vendor/bin/pint tests
```

Expected: the `grep -rL` line prints nothing (every converted file imports `Test`). Pint sorts the imports.

- [ ] **Step 4: Run the suite on Laravel 13**

Run: `composer test`
Expected: all tests pass, `PHPUnit Deprecations: 0`.

- [ ] **Step 5: Write the fake's failing test**

Create `tests/Unit/FakeGoogleSheetsServiceTest.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FakeGoogleSheetsServiceTest extends TestCase
{
    #[Test]
    public function it_writes_from_an_anchor_and_reads_back_trimmed_like_google(): void
    {
        $fake = new FakeGoogleSheetsService();
        $fake->createSheetIfMissing('Tab');
        $fake->updateSheetData('Tab', 'A1', [['a', 'b', ''], ['c']]);
        $fake->updateSheetData('Tab', 'B3', [['d']]);

        $this->assertSame([['a', 'b'], ['c'], ['', 'd']], $fake->getSheetData('Tab', 'A1:ZZ'));
    }

    #[Test]
    public function it_clears_from_a_row_and_from_a_column(): void
    {
        $fake = new FakeGoogleSheetsService();
        $fake->seed('Tab', [['a', 'b', 'c'], ['d', 'e', 'f'], ['g', 'h', 'i']]);

        $fake->clearSheetData('Tab', 'A3:ZZ');
        $fake->clearSheetData('Tab', 'C1:ZZ');

        $this->assertSame([['a', 'b'], ['d', 'e']], $fake->rows('Tab'));
    }

    #[Test]
    public function it_reports_missing_tabs_and_creates_them_once(): void
    {
        $fake = new FakeGoogleSheetsService();

        $this->assertNull($fake->getSheetId('Tab'));
        $this->assertNull($fake->getSheet('Tab'));
        $this->assertTrue($fake->createSheetIfMissing('Tab'));
        $this->assertFalse($fake->createSheetIfMissing('Tab'));
        $this->assertSame(1000, $fake->getSheetId('Tab'));
        $this->assertSame(1000, $fake->getSheet('Tab')->getProperties()->getSheetId());
    }
}
```

- [ ] **Step 6: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/FakeGoogleSheetsServiceTest.php`
Expected: FAIL, `Class "PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService" not found`.

- [ ] **Step 7: Write the fake**

Create `tests/Support/FakeGoogleSheetsService.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Support;

use Google\Service\Sheets\Sheet;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

/**
 * An in-memory spreadsheet. Cells are stored sparsely by zero-based row and
 * column; reads come back the way the Sheets API returns them, with trailing
 * empty cells and rows trimmed.
 */
class FakeGoogleSheetsService extends GoogleSheetsService
{
    /** @var array<string, array<int, array<int, string>>> */
    public array $tabs = [];

    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    /** @var array<string, array<string, mixed>> tab => extra Sheet fields (conditionalFormats, protectedRanges, basicFilter) */
    public array $metadata = [];

    /**
     * @param  list<list<string>>  $rows
     */
    public function seed(string $tab, array $rows): void
    {
        $this->tabs[$tab] = array_map(fn (array $cells): array => array_map('strval', array_values($cells)), array_values($rows));
    }

    /**
     * @return list<list<string>>
     */
    public function rows(string $tab): array
    {
        return $this->trimmed($this->tabs[$tab] ?? []);
    }

    public function getSheetId(string $sheetName): ?int
    {
        $index = array_search($sheetName, array_keys($this->tabs), true);

        return $index === false ? null : 1000 + $index;
    }

    public function createSheetIfMissing(string $sheetName): bool
    {
        if (array_key_exists($sheetName, $this->tabs)) {
            return false;
        }

        $this->tabs[$sheetName] = [];

        return true;
    }

    public function getSheetData(string $sheetName, string $range): array
    {
        return $this->rows($sheetName);
    }

    public function updateSheetData(string $sheetName, string $range, array $values): bool
    {
        [$column, $row] = $this->anchor($range);

        foreach (array_values($values) as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $this->tabs[$sheetName][$row + $r][$column + $c] = (string) $value;
            }
        }

        return true;
    }

    public function clearSheetData(string $sheetName, string $range): bool
    {
        [$column, $row] = $this->anchor($range);

        foreach ($this->tabs[$sheetName] ?? [] as $r => $cells) {
            foreach (array_keys($cells) as $c) {
                if ($r >= $row && $c >= $column) {
                    $this->tabs[$sheetName][$r][$c] = '';
                }
            }
        }

        return true;
    }

    public function getSpreadsheetUrl(?string $sheetName = null): string
    {
        return 'https://docs.google.com/spreadsheets/d/test-spreadsheet-id/edit';
    }

    public function getSheet(string $sheetName): ?Sheet
    {
        $sheetId = $this->getSheetId($sheetName);

        if ($sheetId === null) {
            return null;
        }

        return new Sheet(['properties' => ['sheetId' => $sheetId, 'title' => $sheetName]] + ($this->metadata[$sheetName] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     */
    public function batchUpdate(array $requests): void
    {
        $this->batches[] = $requests;
    }

    /**
     * Zero-based [column, row] of a range's top-left cell, e.g. "B3:ZZ" => [1, 2].
     *
     * @return array{0: int, 1: int}
     */
    private function anchor(string $range): array
    {
        preg_match('/^([A-Z]+)(\d+)/', $range, $matches);

        $column = 0;
        foreach (str_split($matches[1]) as $letter) {
            $column = $column * 26 + (ord($letter) - 64);
        }

        return [$column - 1, (int) $matches[2] - 1];
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return list<list<string>>
     */
    private function trimmed(array $grid): array
    {
        $rows = [];
        $lastRow = $grid === [] ? -1 : max(array_keys($grid));

        for ($r = 0; $r <= $lastRow; $r++) {
            $cells = $grid[$r] ?? [];
            $lastColumn = $cells === [] ? -1 : max(array_keys($cells));
            $row = [];

            for ($c = 0; $c <= $lastColumn; $c++) {
                $row[] = $cells[$c] ?? '';
            }

            while ($row !== [] && end($row) === '') {
                array_pop($row);
            }

            $rows[] = $row;
        }

        while ($rows !== [] && end($rows) === []) {
            array_pop($rows);
        }

        return $rows;
    }
}
```

- [ ] **Step 8: Add the shared test helpers**

Replace `tests/TestCase.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;
use PaperleafTech\LaravelTranslation\LaravelTranslationServiceProvider;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;

abstract class TestCase extends Orchestra
{
    protected string $testBackupPath;

    /** @var list<string> */
    protected array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBackupPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-translation-tests-'.uniqid();
        config()->set('laravel-translation.backup.path', $this->testBackupPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->testBackupPath ?? null, ...$this->temporaryDirectories] as $directory) {
            if ($directory !== null && File::isDirectory($directory)) {
                File::deleteDirectory($directory);
            }
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelTranslationServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        config()->set('laravel-translation.spreadsheet_id', 'test-spreadsheet-id');
        config()->set('laravel-translation.credentials_path', __DIR__.'/fixtures/credentials.json');
        config()->set('laravel-translation.format', false);
    }

    protected function backupPath(): string
    {
        return $this->testBackupPath;
    }

    /**
     * Point lang_path() at an empty directory that is removed after the test.
     */
    protected function useTemporaryLangPath(): string
    {
        $path = $this->temporaryDirectory('lang');
        $this->app->useLangPath($path);

        return $path;
    }

    protected function temporaryDirectory(string $name): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR."laravel-translation-{$name}-".uniqid();
        File::ensureDirectoryExists($path);
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    /**
     * @param  array<string, array<array-key, mixed>|string>  $files  path relative to $root => PHP array, JSON array, or raw file contents
     */
    protected function writeLangFiles(array $files, ?string $root = null): void
    {
        foreach ($files as $file => $contents) {
            $path = ($root ?? lang_path()).'/'.$file;

            File::ensureDirectoryExists(dirname($path));
            File::put($path, match (true) {
                is_string($contents) => $contents,
                str_ends_with($file, '.json') => json_encode($contents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                default => '<?php return '.var_export($contents, true).';',
            });
        }
    }

    protected function fakeSheets(): FakeGoogleSheetsService
    {
        $fake = new FakeGoogleSheetsService();
        $this->app->instance(GoogleSheetsService::class, $fake);

        return $fake;
    }
}
```

- [ ] **Step 9: Move `CheckCommandTest` onto the shared helpers**

In `tests/Feature/CheckCommandTest.php`:
1. Delete the `$langPath` property, the `tearDown()` method, and the `writeLangFiles()` method at the bottom (now inherited).
2. Replace `setUp()` with:

```php
    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
        $this->packagePath = $this->temporaryDirectory('package');
    }
```

3. Run `grep -n 'langPath' tests/Feature/CheckCommandTest.php`; replace any remaining `$this->langPath` with `lang_path()`.

- [ ] **Step 10: Run the whole suite**

Run: `composer test`
Expected: all tests pass, including the three new fake tests.

- [ ] **Step 11: Commit**

```bash
vendor/bin/pint tests
git add composer.json composer.lock phpunit.xml tests
git commit -m "Test against Laravel 11-13 with PHPUnit attributes and an in-memory sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Configurable source locale and catalogue changes

**Files:**
- Modify: `src/Support/TranslationConventions.php`, `src/Services/TranslationCatalogue.php`, `src/Commands/CheckCommand.php`
- Test: `tests/Unit/TranslationConventionsTest.php`, `tests/Unit/TranslationCatalogueTest.php` (create), `tests/Feature/CheckCommandTest.php`

**Interfaces:**
- Consumes: `TestCase::useTemporaryLangPath()`, `writeLangFiles()` (Task 1).
- Produces: `TranslationConventions::sourceLocale(): string`, `TranslationConventions::isSourceLocale(string): bool` (config-backed).
- Produces: `TranslationCatalogue::appGroups(string $locale): array<string, array<string, string>>` (app PHP groups only, sorted). `lines()` keeps its 0.4.0 signature.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/TranslationConventionsTest.php`:

```php
    #[Test]
    public function it_reads_the_source_locale_from_config(): void
    {
        config()->set('laravel-translation.source_locale', 'en_CA');

        $this->assertSame('en_CA', TranslationConventions::sourceLocale());
        $this->assertTrue(TranslationConventions::isSourceLocale('en_CA'));
        $this->assertFalse(TranslationConventions::isSourceLocale('en'));
    }
```

Create `tests/Unit/TranslationCatalogueTest.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TranslationCatalogueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
    }

    #[Test]
    public function app_groups_are_the_php_files_only(): void
    {
        $this->writeLangFiles([
            'en/auth.php' => ['failed' => 'Failed', 'nested' => ['save' => 'Save'], 'count' => 3],
            'en/resources/schools.php' => ['title' => 'Schools'],
            'en.json' => ['Hello!' => 'Hello!'],
            'vendor/demo/fr/messages.php' => ['saved' => 'Enregistré'],
        ]);

        $groups = $this->app->make(TranslationCatalogue::class)->appGroups('en');

        $this->assertSame([
            'auth' => ['failed' => 'Failed', 'nested.save' => 'Save'],
            'resources/schools' => ['title' => 'Schools'],
        ], $groups);
    }

    #[Test]
    public function source_json_lines_fall_back_to_the_other_locales_keys(): void
    {
        $this->writeLangFiles([
            'fr.json' => ['Hello, :name!' => 'Bonjour, :name !', 'Regards,' => 'Cordialement,'],
            'es.json' => ['Hello, :name!' => '¡Hola, :name!'],
        ]);

        $lines = $this->app->make(TranslationCatalogue::class)->lines('en');

        $this->assertSame(['Hello, :name!' => 'Hello, :name!', 'Regards,' => 'Regards,'], $lines['*']);
    }

    #[Test]
    public function a_source_json_file_wins_over_the_fallback(): void
    {
        $this->writeLangFiles([
            'en.json' => ['Hello!' => 'Hi!'],
            'fr.json' => ['Hello!' => 'Salut !', 'Other' => 'Autre'],
        ]);

        $lines = $this->app->make(TranslationCatalogue::class)->lines('en');

        $this->assertSame(['Hello!' => 'Hi!'], $lines['*']);
    }

    #[Test]
    public function it_names_the_file_when_json_is_malformed(): void
    {
        $this->writeLangFiles(['fr.json' => '{"Hello!": "Bonjour",}']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(lang_path('fr.json'));

        $this->app->make(TranslationCatalogue::class)->lines('fr');
    }
}
```

Add to `tests/Feature/CheckCommandTest.php`:

```php
    #[Test]
    public function it_compares_against_the_configured_source_locale(): void
    {
        config()->set('laravel-translation.source_locale', 'en_CA');

        $this->writeLangFiles([
            'en_CA/common.php' => ['save' => 'Save', 'cancel' => 'Cancel'],
            'fr_CA/common.php' => ['save' => 'Enregistrer'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Missing from fr_CA')
            ->doesntExpectOutputToContain('=== en_CA ===')
            ->assertFailed();
    }

    #[Test]
    public function it_checks_json_lines_without_a_source_json_file(): void
    {
        $this->writeLangFiles([
            'en/common.php' => ['save' => 'Save'],
            'fr/common.php' => ['save' => 'Enregistrer'],
            'fr.json' => ['Hello, :name!' => 'Bonjour !'],
        ]);

        $this->artisan('translations:check')
            ->expectsOutputToContain('Placeholders differ: en has [name], fr has []')
            ->doesntExpectOutputToContain('Not in en')
            ->assertFailed();
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter 'source_locale|TranslationCatalogueTest|configured_source|without_a_source_json'`
Expected: FAIL — `Call to undefined method ...TranslationConventions::sourceLocale()`, `Call to undefined method ...appGroups()`, and the JSON tests failing on missing `*` group / no exception.

- [ ] **Step 3: Make the source locale config-backed**

In `src/Support/TranslationConventions.php`, add `sourceLocale()` and route `isSourceLocale()` through it (keep `SOURCE_LOCALE` and the sheet helpers for now; Task 7 removes them):

```php
    public static function sourceLocale(): string
    {
        return (string) config('laravel-translation.source_locale', self::SOURCE_LOCALE);
    }

    public static function isSourceLocale(string $locale): bool
    {
        return $locale === self::sourceLocale();
    }
```

In `src/Commands/CheckCommand.php`, replace both `TranslationConventions::SOURCE_LOCALE` with `TranslationConventions::sourceLocale()`.

- [ ] **Step 4: Add `appGroups()`, the JSON fallback and the malformed-JSON error**

In `src/Services/TranslationCatalogue.php`, add `use PaperleafTech\LaravelTranslation\Support\TranslationConventions;` and `use RuntimeException;`, then replace the start of `lines()` (everything before the vendor loop) and add three methods:

```php
    public function lines(string $locale, array $vendorNamespaces = []): array
    {
        $groups = $this->appGroups($locale);

        $json = $this->jsonLines($locale);
        if ($json !== null) {
            $groups[self::JSON_GROUP] = $json;
        }

        foreach ($vendorNamespaces as $namespace) {
            foreach ($this->packageFiles($namespace, $locale) as $file => $contents) {
                $groups["{$namespace}::{$file}"] = $this->flatten($contents);
            }
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * The app's own PHP files: what push and pull sync to the sheet.
     *
     * @return array<string, array<string, string>> group => [dotted key => line], both sorted
     */
    public function appGroups(string $locale): array
    {
        $groups = array_map(fn (array $contents): array => $this->flatten($contents), $this->readDirectory(lang_path($locale)));
        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * lang/{locale}.json. Laravel renders a JSON key that has no source-locale
     * line as the key itself, so without lang/{source}.json the source lines
     * are the other locales' keys mapped to themselves.
     *
     * @return array<string, string>|null null when the locale has no JSON lines
     */
    protected function jsonLines(string $locale): ?array
    {
        $path = lang_path("{$locale}.json");

        if (File::exists($path)) {
            return $this->flatten($this->readJson($path), dotted: false);
        }

        if (! TranslationConventions::isSourceLocale($locale)) {
            return null;
        }

        $keys = [];
        foreach (File::glob(lang_path('*.json')) as $other) {
            foreach (array_keys($this->readJson($other)) as $key) {
                $keys[(string) $key] = (string) $key;
            }
        }

        return $keys === [] ? null : $this->flatten($keys, dotted: false);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readJson(string $path): array
    {
        $decoded = json_decode(File::get($path), true);

        if (! is_array($decoded)) {
            $reason = json_last_error() === JSON_ERROR_NONE ? 'expected a JSON object' : json_last_error_msg();

            throw new RuntimeException("Could not read {$path}: {$reason}.");
        }

        return $decoded;
    }
```

- [ ] **Step 5: Run the tests**

Run: `composer test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint src/Support/TranslationConventions.php src/Services/TranslationCatalogue.php src/Commands/CheckCommand.php tests/Unit/TranslationConventionsTest.php tests/Unit/TranslationCatalogueTest.php tests/Feature/CheckCommandTest.php
git add src tests
git commit -m "Make the source locale configurable; JSON fallback and errors in the catalogue

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Read and write the translation tab

**Files:**
- Create: `src/Sheet/SheetRow.php`, `src/Sheet/SheetContents.php`, `src/Sheet/TranslationSheet.php`
- Test: `tests/Unit/TranslationSheetTest.php`

**Interfaces:**
- Consumes: `FakeGoogleSheetsService`, `fakeSheets()` (Task 1); `GoogleSheetsService::getSheetId/getSheetData/createSheetIfMissing/updateSheetData/clearSheetData/getSpreadsheetUrl` (existing).
- Produces:
  - `final readonly class SheetRow(string $group, string $key, string $default = '', array $values = [], array $extras = [])` with `id(): string`, `static idFor(string $group, string $key): string`, `label(): string` (`"{group}.{key}"`), `value(string $locale): string`.
  - `final readonly class SheetContents(array $locales = [], array $extras = [], array $rows = [], array $duplicates = [], array $grid = [])`.
  - `TranslationSheet` constants `KEY = 'key'`, `GROUP = 'group'`, `DEFAULT = 'default'`; methods `name(): string`, `exists(): bool`, `read(string $source, array $locales): ?SheetContents`, `write(array $headers, array $rows): void`, `url(): string`, `static column(int $number): string`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TranslationSheetTest.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Sheet\SheetRow;
use PaperleafTech\LaravelTranslation\Sheet\TranslationSheet;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class TranslationSheetTest extends TestCase
{
    private FakeGoogleSheetsService $sheets;

    private TranslationSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sheets = $this->fakeSheets();
        $this->sheet = $this->app->make(TranslationSheet::class);
    }

    #[Test]
    public function it_reads_null_when_the_tab_is_missing(): void
    {
        $this->assertNull($this->sheet->read('en', ['en', 'fr']));
    }

    #[Test]
    public function it_reads_an_empty_tab_as_no_rows(): void
    {
        $this->sheets->createSheetIfMissing('Translations');

        $contents = $this->sheet->read('en', ['en', 'fr']);

        $this->assertSame([], $contents->rows);
        $this->assertSame([], $contents->locales);
    }

    #[Test]
    public function it_reads_rows_by_header_name_whatever_the_column_order(): void
    {
        $this->sheets->seed('Translations', [
            ['notes', 'fr', 'key', 'en', 'group', 'default', 'de'],
            ['Shorter', 'Échec', 'failed', 'Failed!', 'auth', 'Failed', 'Fehler'],
            ['', '', 'throttle', 'Slow down', 'auth', 'Slow down'],
        ]);

        $contents = $this->sheet->read('en', ['en', 'fr']);

        $this->assertSame(['fr'], $contents->locales);
        $this->assertSame(['notes', 'de'], $contents->extras);
        $this->assertEquals([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed!', 'fr' => 'Échec'], ['notes' => 'Shorter', 'de' => 'Fehler']),
            new SheetRow('auth', 'throttle', 'Slow down', ['en' => 'Slow down', 'fr' => ''], ['notes' => '', 'de' => '']),
        ], $contents->rows);
        $this->assertSame($this->sheets->rows('Translations'), $contents->grid);
    }

    #[Test]
    public function it_trims_only_key_and_group(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en', 'fr'],
            [' failed ', " auth\n", 'Failed', 'Failed', "  Ligne un\nLigne deux\u{00A0}"],
        ]);

        $row = $this->sheet->read('en', ['en', 'fr'])->rows[0];

        $this->assertSame('failed', $row->key);
        $this->assertSame('auth', $row->group);
        $this->assertSame("  Ligne un\nLigne deux\u{00A0}", $row->value('fr'));
    }

    #[Test]
    public function it_skips_blank_rows(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en'],
            ['failed', 'auth', 'Failed', 'Failed'],
            [],
            ['', '', '', ''],
            ['throttle', 'auth', 'Slow', 'Slow'],
        ]);

        $this->assertCount(2, $this->sheet->read('en', ['en'])->rows);
    }

    #[Test]
    public function it_keeps_the_first_of_duplicate_rows(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en', 'fr'],
            ['failed', 'auth', 'Failed', 'Failed', 'Premier'],
            ['failed', 'auth', 'Failed', 'Failed', 'Second'],
        ]);

        $contents = $this->sheet->read('en', ['en', 'fr']);

        $this->assertCount(1, $contents->rows);
        $this->assertSame('Premier', $contents->rows[0]->value('fr'));
        $this->assertSame(['auth.failed'], $contents->duplicates);
    }

    #[Test]
    public function it_names_missing_required_columns(): void
    {
        $this->sheets->seed('Translations', [['key', 'group', 'fr']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing the column(s): default, en');

        $this->sheet->read('en', ['en', 'fr']);
    }

    #[Test]
    public function it_rejects_two_columns_with_the_same_header(): void
    {
        $this->sheets->seed('Translations', [['key', 'group', 'default', 'en', 'notes', 'notes']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('two "notes" columns');

        $this->sheet->read('en', ['en']);
    }

    #[Test]
    public function it_rejects_values_under_a_blank_header(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', '', 'default', 'en'],
            ['failed', 'auth', 'stray note', 'Failed', 'Failed'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Column C has values but no header');

        $this->sheet->read('en', ['en']);
    }

    #[Test]
    public function it_ignores_an_empty_column_with_a_blank_header(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', '', 'default', 'en'],
            ['failed', 'auth', '', 'Failed', 'Failed'],
        ]);

        $this->assertCount(1, $this->sheet->read('en', ['en'])->rows);
    }

    #[Test]
    public function it_writes_the_header_and_rows_and_clears_what_was_left_over(): void
    {
        $this->sheets->seed('Translations', [
            ['key', 'group', 'default', 'en', 'fr', 'old', 'older'],
            ['a', 'g', 'A', 'A', 'x', 'y', 'z'],
            ['b', 'g', 'B', 'B', 'x', 'y', 'z'],
            ['c', 'g', 'C', 'C', 'x', 'y', 'z'],
        ]);

        $this->sheet->write(['key', 'group', 'default', 'en', 'notes'], [
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed!'], ['notes' => 'n']),
        ]);

        $this->assertSame([
            ['key', 'group', 'default', 'en', 'notes'],
            ['failed', 'auth', 'Failed', 'Failed!', 'n'],
        ], $this->sheets->rows('Translations'));
    }

    #[Test]
    public function it_creates_the_tab_when_writing_to_a_missing_one(): void
    {
        config()->set('laravel-translation.sheet', 'Strings');

        $this->sheet->write(['key', 'group', 'default', 'en'], []);

        $this->assertSame([['key', 'group', 'default', 'en']], $this->sheets->rows('Strings'));
    }

    #[Test]
    #[DataProvider('columns')]
    public function it_converts_column_numbers_to_letters(int $number, string $letters): void
    {
        $this->assertSame($letters, TranslationSheet::column($number));
    }

    public static function columns(): array
    {
        return [[1, 'A'], [3, 'C'], [26, 'Z'], [27, 'AA'], [52, 'AZ'], [703, 'AAA']];
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/TranslationSheetTest.php`
Expected: FAIL, `Class "PaperleafTech\LaravelTranslation\Sheet\TranslationSheet" not found`.

- [ ] **Step 3: Write `SheetRow`**

Create `src/Sheet/SheetRow.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

/**
 * One row of the translation tab: a line's group and key, the source text
 * as of the last push (default), the text per locale, and the cells of any
 * column the package does not manage.
 */
final readonly class SheetRow
{
    /**
     * @param  array<string, string>  $values  locale => text, source locale included
     * @param  array<string, string>  $extras  unrecognised header => text
     */
    public function __construct(
        public string $group,
        public string $key,
        public string $default = '',
        public array $values = [],
        public array $extras = [],
    ) {}

    public static function idFor(string $group, string $key): string
    {
        return $group."\0".$key;
    }

    public function id(): string
    {
        return self::idFor($this->group, $this->key);
    }

    /**
     * The row as people read it in command output, e.g. `auth.failed`.
     */
    public function label(): string
    {
        return "{$this->group}.{$this->key}";
    }

    public function value(string $locale): string
    {
        return $this->values[$locale] ?? '';
    }
}
```

- [ ] **Step 4: Write `SheetContents`**

Create `src/Sheet/SheetContents.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

/**
 * The translation tab as read: which headers are locales and which belong to
 * someone else, its rows, and the raw cells for backups.
 */
final readonly class SheetContents
{
    /**
     * @param  list<string>  $locales  non-source locale headers, in sheet order
     * @param  list<string>  $extras  unrecognised headers, in sheet order
     * @param  list<SheetRow>  $rows  the first row for each (group, key)
     * @param  list<string>  $duplicates  labels of later rows that repeated a (group, key)
     * @param  list<list<string>>  $grid  every cell as read, header row included
     */
    public function __construct(
        public array $locales = [],
        public array $extras = [],
        public array $rows = [],
        public array $duplicates = [],
        public array $grid = [],
    ) {}
}
```

- [ ] **Step 5: Write `TranslationSheet`**

Create `src/Sheet/TranslationSheet.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use RuntimeException;

/**
 * The single tab push and pull share. Columns are found by their header, so
 * reviewers can reorder them or add their own; only key, group, default and
 * the source locale are required.
 */
class TranslationSheet
{
    public const KEY = 'key';

    public const GROUP = 'group';

    public const DEFAULT = 'default';

    /** Wide enough for any tab this package writes. */
    private const ALL = 'A1:ZZ';

    public function __construct(protected GoogleSheetsService $sheets) {}

    public function name(): string
    {
        return (string) config('laravel-translation.sheet', 'Translations');
    }

    public function exists(): bool
    {
        return $this->sheets->getSheetId($this->name()) !== null;
    }

    public function url(): string
    {
        return $this->sheets->getSpreadsheetUrl($this->name());
    }

    /**
     * @param  list<string>  $locales  locales with a lang/ directory, source included
     * @return SheetContents|null null when the tab does not exist
     *
     * @throws RuntimeException when the header row is unusable
     */
    public function read(string $source, array $locales): ?SheetContents
    {
        if (! $this->exists()) {
            return null;
        }

        $grid = $this->sheets->getSheetData($this->name(), self::ALL);

        if ($grid === []) {
            return new SheetContents;
        }

        $headers = array_map(fn (mixed $cell): string => trim((string) $cell), $grid[0]);
        $body = array_slice($grid, 1);
        $columns = $this->columnsByHeader($headers, $body, $source);

        $managed = [self::KEY, self::GROUP, self::DEFAULT];
        $localeHeaders = [];
        $extraHeaders = [];

        foreach (array_keys($columns) as $header) {
            $header = (string) $header;

            if (in_array($header, $managed, true) || $header === $source) {
                continue;
            }

            if (in_array($header, $locales, true)) {
                $localeHeaders[] = $header;
            } else {
                $extraHeaders[] = $header;
            }
        }

        $rows = [];
        $seen = [];
        $duplicates = [];

        foreach ($body as $cells) {
            if (implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            $cell = fn (string $header): string => (string) ($cells[$columns[$header]] ?? '');

            $values = [];
            foreach ([$source, ...$localeHeaders] as $locale) {
                $values[$locale] = $cell($locale);
            }

            $extras = [];
            foreach ($extraHeaders as $header) {
                $extras[$header] = $cell($header);
            }

            $row = new SheetRow(trim($cell(self::GROUP)), trim($cell(self::KEY)), $cell(self::DEFAULT), $values, $extras);

            if (isset($seen[$row->id()])) {
                $duplicates[] = $row->label();

                continue;
            }

            $seen[$row->id()] = true;
            $rows[] = $row;
        }

        return new SheetContents($localeHeaders, $extraHeaders, $rows, $duplicates, $grid);
    }

    /**
     * Replace the tab with this header and these rows, creating it if needed.
     *
     * @param  list<string>  $headers
     * @param  list<SheetRow>  $rows
     */
    public function write(array $headers, array $rows): void
    {
        $name = $this->name();
        $grid = [$headers];

        foreach ($rows as $row) {
            $grid[] = array_map(fn (string $header): string => $this->cell($row, $header), $headers);
        }

        $this->sheets->createSheetIfMissing($name);
        $this->sheets->updateSheetData($name, 'A1', $grid);

        // Updating a range only overwrites the cells written, so rows and
        // columns left over from a larger previous push must be cleared.
        $this->sheets->clearSheetData($name, 'A'.(count($grid) + 1).':ZZ');
        $this->sheets->clearSheetData($name, self::column(count($headers) + 1).'1:ZZ');
    }

    /**
     * A1 column letters for a 1-based column number: 1 => A, 27 => AA.
     */
    public static function column(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    protected function cell(SheetRow $row, string $header): string
    {
        return match ($header) {
            self::KEY => $row->key,
            self::GROUP => $row->group,
            self::DEFAULT => $row->default,
            default => $row->values[$header] ?? $row->extras[$header] ?? '',
        };
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $body
     * @return array<string, int> header => zero-based column
     */
    protected function columnsByHeader(array $headers, array $body, string $source): array
    {
        $columns = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                foreach ($body as $cells) {
                    if ((string) ($cells[$index] ?? '') !== '') {
                        throw new RuntimeException(sprintf(
                            'Column %s of the "%s" tab has values but no header. Give it a header or clear it.',
                            self::column($index + 1),
                            $this->name(),
                        ));
                    }
                }

                continue;
            }

            if (isset($columns[$header])) {
                throw new RuntimeException("The \"{$this->name()}\" tab has two \"{$header}\" columns. Rename or remove one.");
            }

            $columns[$header] = $index;
        }

        $missing = array_values(array_diff([self::KEY, self::GROUP, self::DEFAULT, $source], array_keys($columns)));

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'The "%s" tab is missing the column(s): %s. Restore them, or delete the tab and run translations:push.',
                $this->name(),
                implode(', ', $missing),
            ));
        }

        return $columns;
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/TranslationSheetTest.php`
Expected: all pass.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint src/Sheet tests/Unit/TranslationSheetTest.php
git add src/Sheet tests/Unit/TranslationSheetTest.php
git commit -m "Read and write the single translation tab by header name

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Reconcile code with the tab

**Files:**
- Create: `src/Sheet/ReconcileReport.php`, `src/Sheet/ReconcileResult.php`, `src/Sheet/SheetReconciler.php`
- Test: `tests/Unit/SheetReconcilerTest.php`

**Interfaces:**
- Consumes: `SheetRow`, `SheetContents`, `TranslationSheet::KEY/GROUP/DEFAULT` (Task 3).
- Produces:
  - `final class ReconcileReport` with public `list<string>` properties `$new`, `$removed`, `$sourceChanged`, `$needsReview`, `$conflicts`, `$sourceMissing`, `$duplicates`, and `int $filledFromCode`.
  - `final readonly class ReconcileResult(array $headers, array $rows, ReconcileReport $report)`.
  - `SheetReconciler::reconcile(string $source, array $code, ?SheetContents $existing): ReconcileResult`, where `$code` is `locale => group => key => line` and must include `$source`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/SheetReconcilerTest.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Sheet\SheetContents;
use PaperleafTech\LaravelTranslation\Sheet\SheetReconciler;
use PaperleafTech\LaravelTranslation\Sheet\SheetRow;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SheetReconcilerTest extends TestCase
{
    private SheetReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciler = new SheetReconciler;
    }

    /**
     * @param  list<SheetRow>  $rows
     * @param  list<string>  $extras
     */
    private function sheet(array $rows, array $extras = [], array $locales = ['fr']): SheetContents
    {
        return new SheetContents($locales, $extras, $rows);
    }

    private function code(string $english, string $french = ''): array
    {
        return [
            'en' => ['auth' => ['failed' => $english]],
            'fr' => $french === '' ? [] : ['auth' => ['failed' => $french]],
        ];
    }

    private function only(array $rows): SheetRow
    {
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    #[Test]
    public function the_first_push_builds_sorted_rows_from_code(): void
    {
        $result = $this->reconciler->reconcile('en', [
            'en' => ['resources/schools' => ['title' => 'Schools'], 'auth' => ['throttle' => 'Slow', 'failed' => 'Failed']],
            'fr' => ['auth' => ['failed' => 'Échec']],
        ], null);

        $this->assertSame(['key', 'group', 'default', 'en', 'fr'], $result->headers);
        $this->assertEquals([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => 'Échec']),
            new SheetRow('auth', 'throttle', 'Slow', ['en' => 'Slow', 'fr' => '']),
            new SheetRow('resources/schools', 'title', 'Schools', ['en' => 'Schools', 'fr' => '']),
        ], $result->rows);
        $this->assertSame(['auth.failed', 'auth.throttle', 'resources/schools.title'], $result->report->new);
        $this->assertSame(0, $result->report->filledFromCode);
    }

    #[Test]
    public function unchanged_code_keeps_an_editors_english(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Failed'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Sign-in failed', 'fr' => '']),
        ]));

        $row = $this->only($result->rows);
        $this->assertSame('Failed', $row->default);
        $this->assertSame('Sign-in failed', $row->value('en'));
        $this->assertSame([], $result->report->conflicts);
    }

    #[Test]
    public function changed_code_without_an_edit_updates_both_and_flags_translations(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Sign-in failed'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => 'Échec']),
        ]));

        $row = $this->only($result->rows);
        $this->assertSame('Sign-in failed', $row->default);
        $this->assertSame('Sign-in failed', $row->value('en'));
        $this->assertSame('Échec', $row->value('fr'));
        $this->assertSame(['auth.failed'], $result->report->sourceChanged);
        $this->assertSame(['auth.failed'], $result->report->needsReview);
    }

    #[Test]
    public function changed_code_with_no_translations_is_not_flagged_for_review(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Sign-in failed'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => '']),
        ]));

        $this->assertSame(['auth.failed'], $result->report->sourceChanged);
        $this->assertSame([], $result->report->needsReview);
    }

    #[Test]
    public function code_that_caught_up_with_a_pulled_edit_moves_the_default(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Sign-in failed'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Sign-in failed', 'fr' => '']),
        ]));

        $row = $this->only($result->rows);
        $this->assertSame('Sign-in failed', $row->default);
        $this->assertSame('Sign-in failed', $row->value('en'));
        $this->assertSame([], $result->report->sourceChanged);
        $this->assertSame([], $result->report->conflicts);
    }

    #[Test]
    public function a_change_on_both_sides_keeps_the_sheet_and_reports_a_conflict(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Code text'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Sheet text', 'fr' => '']),
        ]));

        $row = $this->only($result->rows);
        $this->assertSame('Code text', $row->default);
        $this->assertSame('Sheet text', $row->value('en'));
        $this->assertSame(['auth.failed'], $result->report->conflicts);
    }

    #[Test]
    public function a_blank_english_cell_counts_as_no_edit(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Failed'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => '', 'fr' => '']),
        ]));

        $this->assertSame('Failed', $this->only($result->rows)->value('en'));
    }

    #[Test]
    public function a_key_missing_from_source_code_gets_a_blank_default(): void
    {
        $result = $this->reconciler->reconcile('en', ['en' => [], 'fr' => ['auth' => ['only' => 'Seul']]], $this->sheet([
            new SheetRow('auth', 'only', '', ['en' => 'Only', 'fr' => '']),
        ]));

        $row = $this->only($result->rows);
        $this->assertSame('', $row->default);
        $this->assertSame('Only', $row->value('en'));
        $this->assertSame('Seul', $row->value('fr'));
        $this->assertSame(['auth.only'], $result->report->sourceMissing);
    }

    #[Test]
    public function a_sheet_translation_wins_and_a_blank_one_is_filled_from_code(): void
    {
        $result = $this->reconciler->reconcile('en', [
            'en' => ['auth' => ['failed' => 'Failed', 'throttle' => 'Slow']],
            'fr' => ['auth' => ['failed' => 'Échec (code)', 'throttle' => 'Lent']],
        ], $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => 'Échec (feuille)']),
            new SheetRow('auth', 'throttle', 'Slow', ['en' => 'Slow', 'fr' => '']),
        ]));

        $this->assertSame('Échec (feuille)', $result->rows[0]->value('fr'));
        $this->assertSame('Lent', $result->rows[1]->value('fr'));
        $this->assertSame(1, $result->report->filledFromCode);
    }

    #[Test]
    public function extra_columns_travel_with_their_row(): void
    {
        $result = $this->reconciler->reconcile('en', [
            'en' => ['auth' => ['failed' => 'Failed', 'throttle' => 'Slow']],
            'fr' => [],
        ], $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => ''], ['status' => 'reviewed', 'notes' => 'ok']),
        ], ['status', 'notes']));

        $this->assertSame(['status' => 'reviewed', 'notes' => 'ok'], $result->rows[0]->extras);
        $this->assertSame(['status' => '', 'notes' => ''], $result->rows[1]->extras);
    }

    #[Test]
    public function rows_whose_keys_left_the_code_are_dropped_and_reported(): void
    {
        $result = $this->reconciler->reconcile('en', $this->code('Failed'), $this->sheet([
            new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => '']),
            new SheetRow('auth', 'gone', 'Gone', ['en' => 'Gone', 'fr' => 'Parti']),
        ]));

        $this->assertCount(1, $result->rows);
        $this->assertSame(['auth.gone'], $result->report->removed);
    }

    #[Test]
    public function it_keeps_extra_columns_in_header_order(): void
    {
        // A "de" column whose lang/de directory was deleted is read as an extra column.
        $result = $this->reconciler->reconcile('en', [
            'en' => ['auth' => ['failed' => 'Failed']],
            'fr' => [],
            'es' => [],
            'ar' => [],
        ], $this->sheet(
            [new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => ''], ['de' => 'Fehler', 'notes' => ''])],
            ['de', 'notes'],
        ));

        $this->assertSame(['key', 'group', 'default', 'en', 'fr', 'ar', 'es', 'de', 'notes'], $result->headers);
        $this->assertSame('Fehler', $result->rows[0]->extras['de']);
    }

    #[Test]
    public function duplicates_found_when_reading_are_reported(): void
    {
        $existing = new SheetContents(['fr'], [], [new SheetRow('auth', 'failed', 'Failed', ['en' => 'Failed', 'fr' => ''])], ['auth.failed']);

        $result = $this->reconciler->reconcile('en', $this->code('Failed'), $existing);

        $this->assertSame(['auth.failed'], $result->report->duplicates);
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/SheetReconcilerTest.php`
Expected: FAIL, `Class "PaperleafTech\LaravelTranslation\Sheet\SheetReconciler" not found`.

- [ ] **Step 3: Write the result types**

Create `src/Sheet/ReconcileReport.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

/**
 * What a push changed, as row labels (`group.key`) for the command to print.
 */
final class ReconcileReport
{
    /** @var list<string> rows added to the sheet */
    public array $new = [];

    /** @var list<string> rows dropped because no locale's code has the key */
    public array $removed = [];

    /** @var list<string> source text changed in code; sheet had no edit */
    public array $sourceChanged = [];

    /** @var list<string> source changed while translations already existed */
    public array $needsReview = [];

    /** @var list<string> source changed in code and in the sheet; the sheet's text was kept */
    public array $conflicts = [];

    /** @var list<string> keys some locale has but the source locale does not */
    public array $sourceMissing = [];

    /** @var list<string> later sheet rows that repeated a (group, key) */
    public array $duplicates = [];

    /** Blank locale cells on existing rows that were filled from code. */
    public int $filledFromCode = 0;
}
```

Create `src/Sheet/ReconcileResult.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

final readonly class ReconcileResult
{
    /**
     * @param  list<string>  $headers
     * @param  list<SheetRow>  $rows  sorted by group, then key
     */
    public function __construct(
        public array $headers,
        public array $rows,
        public ReconcileReport $report,
    ) {}
}
```

- [ ] **Step 4: Write the reconciler**

Create `src/Sheet/SheetReconciler.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

/**
 * Builds the rows push writes: one per (group, key) in any locale's code,
 * merged with what reviewers have put in the sheet.
 *
 * The source column is editable, so `default` keeps the source text from
 * code as of the last push. Comparing code, default and the sheet's source
 * cell tells a developer's change from an editor's; when both changed, the
 * editor's text is kept because a later pull brings it back to code where
 * the change is visible, whereas a lost sheet edit is not.
 */
class SheetReconciler
{
    /**
     * @param  array<string, array<string, array<string, string>>>  $code  locale => group => key => line; includes $source
     * @param  SheetContents|null  $existing  null on a first push or --fresh
     */
    public function reconcile(string $source, array $code, ?SheetContents $existing): ReconcileResult
    {
        $existing ??= new SheetContents;
        $report = new ReconcileReport;
        $report->duplicates = $existing->duplicates;

        $targets = array_values(array_filter(array_keys($code), fn (string $locale): bool => $locale !== $source));

        $byId = [];
        foreach ($existing->rows as $row) {
            $byId[$row->id()] = $row;
        }

        $pairs = [];
        foreach ($code as $groups) {
            foreach ($groups as $group => $lines) {
                foreach (array_keys($lines) as $key) {
                    $pairs[SheetRow::idFor((string) $group, (string) $key)] = [(string) $group, (string) $key];
                }
            }
        }

        $rows = [];
        foreach ($pairs as $id => [$group, $key]) {
            $rows[] = $this->row($source, $targets, $code, $group, $key, $byId[$id] ?? null, $existing->extras, $report);
        }

        foreach ($byId as $id => $row) {
            if (! isset($pairs[$id])) {
                $report->removed[] = $row->label();
            }
        }

        usort($rows, fn (SheetRow $a, SheetRow $b): int => strcmp($a->group, $b->group) ?: strcmp($a->key, $b->key));
        sort($report->new, SORT_STRING);

        return new ReconcileResult($this->headers($source, $targets, $existing), $rows, $report);
    }

    /**
     * @param  list<string>  $targets
     * @return list<string>
     */
    protected function headers(string $source, array $targets, SheetContents $existing): array
    {
        $kept = array_values(array_intersect($existing->locales, $targets));
        $added = array_values(array_diff($targets, $kept));
        sort($added, SORT_STRING);

        return [TranslationSheet::KEY, TranslationSheet::GROUP, TranslationSheet::DEFAULT, $source, ...$kept, ...$added, ...$existing->extras];
    }

    /**
     * @param  list<string>  $targets
     * @param  array<string, array<string, array<string, string>>>  $code
     * @param  list<string>  $extraHeaders
     */
    protected function row(string $source, array $targets, array $code, string $group, string $key, ?SheetRow $old, array $extraHeaders, ReconcileReport $report): SheetRow
    {
        $label = "{$group}.{$key}";
        [$default, $sourceValue, $outcome] = $this->sourceColumns($code[$source][$group][$key] ?? null, $old, $source);

        if ($old === null) {
            $report->new[] = $label;
        }

        match ($outcome) {
            'changed' => $report->sourceChanged[] = $label,
            'conflict' => $report->conflicts[] = $label,
            'missing' => $report->sourceMissing[] = $label,
            default => null,
        };

        if ($outcome === 'changed' && $this->hasTranslation($old, $targets)) {
            $report->needsReview[] = $label;
        }

        $values = [$source => $sourceValue];
        foreach ($targets as $locale) {
            $sheetValue = $old?->value($locale) ?? '';
            $codeValue = $code[$locale][$group][$key] ?? '';

            if ($old !== null && $sheetValue === '' && $codeValue !== '') {
                $report->filledFromCode++;
            }

            $values[$locale] = $sheetValue !== '' ? $sheetValue : $codeValue;
        }

        $extras = [];
        foreach ($extraHeaders as $header) {
            $extras[$header] = $old?->extras[$header] ?? '';
        }

        return new SheetRow($group, $key, $default, $values, $extras);
    }

    /**
     * @return array{0: string, 1: string, 2: string} default, source cell, outcome
     */
    protected function sourceColumns(?string $code, ?SheetRow $old, string $source): array
    {
        $sheetValue = $old?->value($source) ?? '';

        if ($code === null) {
            return ['', $sheetValue, 'missing'];
        }

        if ($old === null) {
            return [$code, $code, 'new'];
        }

        $default = $old->default;
        $edited = $sheetValue === '' ? $default : $sheetValue;

        return match (true) {
            $code === $default => [$default, $edited, 'unchanged'],
            $edited === $default => [$code, $code, 'changed'],
            $edited === $code => [$code, $edited, 'unchanged'],
            default => [$code, $edited, 'conflict'],
        };
    }

    /**
     * @param  list<string>  $targets
     */
    protected function hasTranslation(?SheetRow $row, array $targets): bool
    {
        if ($row === null) {
            return false;
        }

        foreach ($targets as $locale) {
            if ($row->value($locale) !== '') {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/SheetReconcilerTest.php`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint src/Sheet tests/Unit/SheetReconcilerTest.php
git add src/Sheet tests/Unit/SheetReconcilerTest.php
git commit -m "Reconcile code with the single sheet using a default baseline

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Sheet formatting

**Files:**
- Modify: `src/Services/GoogleSheetsService.php`
- Create: `src/Sheet/SheetFormatter.php`
- Test: `tests/Unit/SheetFormatterTest.php`

**Interfaces:**
- Consumes: `TranslationSheet::KEY/GROUP/DEFAULT`, `TranslationSheet::column()` (Task 3).
- Produces: `GoogleSheetsService::getSheet(string $sheetName): ?Google\Service\Sheets\Sheet`, `GoogleSheetsService::batchUpdate(array $requests): void`; `SheetFormatter::requests(Sheet $sheet, array $headers, array $localeHeaders): array`, constants `SheetFormatter::MARKER`, `SheetFormatter::PROTECTION_DESCRIPTION`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/SheetFormatterTest.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use Google\Service\Sheets\Sheet;
use PaperleafTech\LaravelTranslation\Sheet\SheetFormatter;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SheetFormatterTest extends TestCase
{
    private const HEADERS = ['key', 'group', 'default', 'en', 'fr', 'notes'];

    private function sheet(array $fields = []): Sheet
    {
        return new Sheet(['properties' => ['sheetId' => 7, 'title' => 'Translations']] + $fields);
    }

    private function rule(string $formula): array
    {
        return ['ranges' => [['sheetId' => 7]], 'booleanRule' => ['condition' => ['type' => 'CUSTOM_FORMULA', 'values' => [['userEnteredValue' => $formula]]]]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ofType(array $requests, string $type): array
    {
        return array_values(array_map(fn (array $request): array => $request[$type], array_filter($requests, fn (array $request): bool => isset($request[$type]))));
    }

    private function requests(?Sheet $sheet = null): array
    {
        return (new SheetFormatter)->requests($sheet ?? $this->sheet(), self::HEADERS, ['en', 'fr']);
    }

    #[Test]
    public function it_adds_a_missing_translation_rule_per_locale_column(): void
    {
        $rules = $this->ofType($this->requests(), 'addConditionalFormatRule');

        $this->assertCount(2, $rules);
        $this->assertSame('=AND($A2<>"",D2="",N("laravel-translation")=0)', $rules[0]['rule']['booleanRule']['condition']['values'][0]['userEnteredValue']);
        $this->assertSame(4, $rules[1]['rule']['ranges'][0]['startColumnIndex']);
    }

    #[Test]
    public function it_replaces_only_its_own_rules_and_protection(): void
    {
        $requests = $this->requests($this->sheet([
            'conditionalFormats' => [
                $this->rule('=AND($A2<>"",D2="",'.SheetFormatter::MARKER.')'),
                $this->rule('=$F2="approved"'),
                $this->rule('=AND($A2<>"",E2="",'.SheetFormatter::MARKER.')'),
            ],
            'protectedRanges' => [
                ['protectedRangeId' => 5, 'description' => SheetFormatter::PROTECTION_DESCRIPTION],
                ['protectedRangeId' => 6, 'description' => 'Added by someone else'],
            ],
        ]));

        $this->assertSame([2, 0], array_column($this->ofType($requests, 'deleteConditionalFormatRule'), 'index'));
        $this->assertSame([5], array_column($this->ofType($requests, 'deleteProtectedRange'), 'protectedRangeId'));

        $added = $this->ofType($requests, 'addProtectedRange');
        $this->assertCount(1, $added);
        $this->assertTrue($added[0]['protectedRange']['warningOnly']);
        $this->assertSame(SheetFormatter::PROTECTION_DESCRIPTION, $added[0]['protectedRange']['description']);
        $this->assertSame(3, $added[0]['protectedRange']['range']['endColumnIndex']);
    }

    #[Test]
    public function it_adds_a_filter_only_when_the_tab_has_none(): void
    {
        $this->assertCount(1, $this->ofType($this->requests(), 'setBasicFilter'));
        $this->assertCount(0, $this->ofType($this->requests($this->sheet(['basicFilter' => ['range' => ['sheetId' => 7]]])), 'setBasicFilter'));
    }

    #[Test]
    public function it_freezes_the_header_and_sizes_only_managed_columns(): void
    {
        $requests = $this->requests();

        $frozen = $this->ofType($requests, 'updateSheetProperties')[0]['properties']['gridProperties'];
        $this->assertSame(['frozenRowCount' => 1, 'frozenColumnCount' => 1], $frozen);

        $sized = array_map(fn (array $request): int => $request['range']['startIndex'], $this->ofType($requests, 'updateDimensionProperties'));
        $this->assertSame([0, 1, 2, 3, 4], $sized);
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Unit/SheetFormatterTest.php`
Expected: FAIL, `Class "PaperleafTech\LaravelTranslation\Sheet\SheetFormatter" not found`.

- [ ] **Step 3: Add the two Google methods**

In `src/Services/GoogleSheetsService.php`, add `use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;` and `use Google\Service\Sheets\Sheet;`, then add before `handleGoogleException()`:

```php
    /**
     * A tab's properties, conditional formats, protected ranges and filter,
     * which formatting needs to replace its own rules. Null when the tab is
     * missing.
     */
    public function getSheet(string $sheetName): ?Sheet
    {
        $this->ensureInitialized();

        try {
            $spreadsheet = $this->service->spreadsheets->get($this->spreadsheetId, [
                'fields' => 'sheets(properties(sheetId,title),conditionalFormats,protectedRanges(protectedRangeId,description),basicFilter)',
            ]);
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }

        foreach ($spreadsheet->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === $sheetName) {
                return $sheet;
            }
        }

        return null;
    }

    /**
     * Apply Sheets API requests (formatting, protection, filters) in one call.
     *
     * @param  list<array<string, mixed>>  $requests
     */
    public function batchUpdate(array $requests): void
    {
        if ($requests === []) {
            return;
        }

        $this->ensureInitialized();

        try {
            $this->service->spreadsheets->batchUpdate(
                $this->spreadsheetId,
                new BatchUpdateSpreadsheetRequest(['requests' => $requests]),
            );
        } catch (\Google\Service\Exception $e) {
            $this->handleGoogleException($e);
        }
    }
```

- [ ] **Step 4: Write the formatter**

Create `src/Sheet/SheetFormatter.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Sheet;

use Google\Service\Sheets\Sheet;

/**
 * The formatting push applies to the translation tab. Rows are re-sorted on
 * every push, so everything is set per column or decided by a cell's
 * contents. Rules and the protected range this class owns are found by a
 * marker and replaced, so re-running gives the same result and leaves rules
 * added by people or an app's TranslationsPushed listener alone.
 */
class SheetFormatter
{
    /** Always true; marks a conditional rule as this package's own. */
    public const MARKER = 'N("laravel-translation")=0';

    public const PROTECTION_DESCRIPTION = 'Managed by laravel-translation: key, group and default come from code.';

    private const HEADER_BACKGROUND = '#1F4E5F';

    private const HEADER_TEXT = '#FFFFFF';

    private const READ_ONLY = '#F2F4F6';

    private const MISSING = '#FBE3E1';

    /** Pixel widths of the columns that come from code, which are also the first three. */
    private const WIDTHS = [TranslationSheet::KEY => 260, TranslationSheet::GROUP => 180, TranslationSheet::DEFAULT => 320];

    private const LOCALE_WIDTH = 320;

    /**
     * @param  list<string>  $headers  the header row as push wrote it
     * @param  list<string>  $localeHeaders  the source locale and every other locale column
     * @return list<array<string, mixed>>
     */
    public function requests(Sheet $sheet, array $headers, array $localeHeaders): array
    {
        $sheetId = (int) $sheet->getProperties()->getSheetId();
        $columns = count($headers);
        $fromCode = count(self::WIDTHS);

        $requests = [
            ['updateSheetProperties' => [
                'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 1, 'frozenColumnCount' => 1]],
                'fields' => 'gridProperties.frozenRowCount,gridProperties.frozenColumnCount',
            ]],
            $this->format($this->range($sheetId, 0, 1, 0, $columns), [
                'backgroundColor' => $this->colour(self::HEADER_BACKGROUND),
                'textFormat' => ['bold' => true, 'foregroundColor' => $this->colour(self::HEADER_TEXT)],
            ], 'userEnteredFormat(backgroundColor,textFormat)'),
            $this->format($this->range($sheetId, 1, null, 0, $columns), [
                'wrapStrategy' => 'WRAP',
                'verticalAlignment' => 'TOP',
            ], 'userEnteredFormat(wrapStrategy,verticalAlignment)'),
            $this->format($this->range($sheetId, 1, null, 0, $fromCode), [
                'backgroundColor' => $this->colour(self::READ_ONLY),
            ], 'userEnteredFormat.backgroundColor'),
        ];

        foreach ($headers as $index => $header) {
            $width = self::WIDTHS[$header] ?? (in_array($header, $localeHeaders, true) ? self::LOCALE_WIDTH : null);

            if ($width !== null) {
                $requests[] = ['updateDimensionProperties' => [
                    'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $index, 'endIndex' => $index + 1],
                    'properties' => ['pixelSize' => $width],
                    'fields' => 'pixelSize',
                ]];
            }
        }

        foreach ($this->managedRuleIndexes($sheet) as $index) {
            $requests[] = ['deleteConditionalFormatRule' => ['sheetId' => $sheetId, 'index' => $index]];
        }

        foreach ($localeHeaders as $locale) {
            $index = array_search($locale, $headers, true);

            if ($index === false) {
                continue;
            }

            $letter = TranslationSheet::column($index + 1);

            $requests[] = ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
                'ranges' => [$this->range($sheetId, 1, null, $index, $index + 1)],
                'booleanRule' => [
                    'condition' => ['type' => 'CUSTOM_FORMULA', 'values' => [['userEnteredValue' => "=AND(\$A2<>\"\",{$letter}2=\"\",".self::MARKER.')']]],
                    'format' => ['backgroundColor' => $this->colour(self::MISSING)],
                ],
            ]]];
        }

        foreach ($sheet->getProtectedRanges() ?? [] as $protected) {
            if ($protected->getDescription() === self::PROTECTION_DESCRIPTION) {
                $requests[] = ['deleteProtectedRange' => ['protectedRangeId' => (int) $protected->getProtectedRangeId()]];
            }
        }

        $requests[] = ['addProtectedRange' => ['protectedRange' => [
            'range' => $this->range($sheetId, 1, null, 0, $fromCode),
            'description' => self::PROTECTION_DESCRIPTION,
            'warningOnly' => true,
        ]]];

        if ($sheet->getBasicFilter() === null) {
            $requests[] = ['setBasicFilter' => ['filter' => ['range' => $this->range($sheetId, 0, null, 0, $columns)]]];
        }

        return $requests;
    }

    /**
     * Indexes of this package's conditional rules, highest first so each
     * deletion leaves the remaining indexes valid.
     *
     * @return list<int>
     */
    private function managedRuleIndexes(Sheet $sheet): array
    {
        $indexes = [];

        foreach ($sheet->getConditionalFormats() ?? [] as $index => $rule) {
            foreach ($rule->getBooleanRule()?->getCondition()?->getValues() ?? [] as $value) {
                if (str_contains((string) $value->getUserEnteredValue(), self::MARKER)) {
                    $indexes[] = (int) $index;

                    break;
                }
            }
        }

        rsort($indexes);

        return $indexes;
    }

    /**
     * @param  array<string, mixed>  $format
     * @param  array<string, int>  $range
     * @return array<string, mixed>
     */
    private function format(array $range, array $format, string $fields): array
    {
        return ['repeatCell' => ['range' => $range, 'cell' => ['userEnteredFormat' => $format], 'fields' => $fields]];
    }

    /**
     * A grid range; a null end row runs to the bottom of the tab.
     *
     * @return array<string, int>
     */
    private function range(int $sheetId, int $startRow, ?int $endRow, int $startColumn, int $endColumn): array
    {
        return array_filter([
            'sheetId' => $sheetId,
            'startRowIndex' => $startRow,
            'endRowIndex' => $endRow,
            'startColumnIndex' => $startColumn,
            'endColumnIndex' => $endColumn,
        ], fn (?int $value): bool => $value !== null);
    }

    /**
     * @return array{red: float, green: float, blue: float}
     */
    private function colour(string $hex): array
    {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');

        return ['red' => round($red / 255, 4), 'green' => round($green / 255, 4), 'blue' => round($blue / 255, 4)];
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `composer test`
Expected: all pass (the fake's `getSheet`/`batchUpdate` now override the real methods with matching signatures).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint src/Sheet/SheetFormatter.php src/Services/GoogleSheetsService.php tests/Unit/SheetFormatterTest.php
git add src tests/Unit/SheetFormatterTest.php
git commit -m "Format the translation tab idempotently

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Single-sheet push

**Files:**
- Rewrite: `src/Commands/PushCommand.php`, `src/Services/TranslationBackupManager.php`, `tests/Feature/PushCommandTest.php`, `tests/Unit/TranslationBackupManagerTest.php`
- Create: `src/Events/TranslationsPushed.php`
- Delete: `src/Services/TranslationReconciler.php`, `tests/Unit/TranslationReconcilerTest.php`
- Modify: `src/LaravelTranslationServiceProvider.php`

**Interfaces:**
- Consumes: `TranslationCatalogue::appGroups()`, `TranslationConventions::sourceLocale()` (Task 2); `TranslationSheet`, `SheetContents` (Task 3); `SheetReconciler`, `ReconcileReport` (Task 4); `SheetFormatter`, `GoogleSheetsService::getSheet/batchUpdate` (Task 5); `DiscoversLocales::discoverLocales()` (existing).
- Produces: `TranslationBackupManager::backup(array $rows): ?string`, `TranslationBackupManager::prune(int $keep): int`; `final class TranslationsPushed(string $sheetName, int $sheetId, array $headers, array $rows)`; command `translations:push [--dry-run] [--no-backup] [--fresh]`.

- [ ] **Step 1: Write the failing backup tests**

Replace `tests/Unit/TranslationBackupManagerTest.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TranslationBackupManagerTest extends TestCase
{
    private TranslationBackupManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new TranslationBackupManager;
    }

    #[Test]
    public function it_saves_the_sheet_rows_as_json_with_a_gitignore(): void
    {
        $rows = [['key', 'group', 'default', 'en'], ['failed', 'auth', 'Failed', 'Échec']];

        $path = $this->manager->backup($rows);

        $this->assertSame($this->backupPath(), dirname($path));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{6}\.json$/', basename($path));
        $payload = json_decode(File::get($path), true);
        $this->assertSame('Translations', $payload['sheet']);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertSame($rows, $payload['rows']);
        $this->assertSame("*\n!.gitignore\n", File::get($this->backupPath().'/.gitignore'));
    }

    #[Test]
    public function it_does_not_overwrite_an_existing_gitignore(): void
    {
        File::ensureDirectoryExists($this->backupPath());
        File::put($this->backupPath().'/.gitignore', "custom\n");

        $this->manager->backup([['key']]);

        $this->assertSame("custom\n", File::get($this->backupPath().'/.gitignore'));
    }

    #[Test]
    public function prune_keeps_the_newest_and_leaves_old_locale_folders_alone(): void
    {
        File::ensureDirectoryExists($this->backupPath().'/fr');
        File::put($this->backupPath().'/fr/2026-01-01_120000.json', '{}');

        $files = [];
        for ($i = 1; $i <= 7; $i++) {
            $files[$i] = $this->backupPath().sprintf('/2026-01-%02d_120000.json', $i);
            File::put($files[$i], '{}');
            touch($files[$i], time() - (8 - $i) * 60);
        }

        $this->assertSame(2, $this->manager->prune(5));
        $this->assertFileDoesNotExist($files[1]);
        $this->assertFileDoesNotExist($files[2]);
        $this->assertFileExists($files[3]);
        $this->assertFileExists($this->backupPath().'/fr/2026-01-01_120000.json');
    }

    #[Test]
    public function prune_rejects_a_negative_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->prune(-1);
    }

    #[Test]
    public function disabled_backups_write_and_prune_nothing(): void
    {
        config()->set('laravel-translation.backup.path', false);

        $this->assertFalse($this->manager->isEnabled());
        $this->assertNull($this->manager->backup([['key']]));
        $this->assertSame(0, $this->manager->prune(5));
    }

    #[Test]
    public function relative_paths_resolve_under_storage_and_absolute_ones_are_kept(): void
    {
        config()->set('laravel-translation.backup.path', 'app/translation-backups');
        $this->assertSame(storage_path('app/translation-backups'), $this->manager->path());

        config()->set('laravel-translation.backup.path', '/tmp/somewhere');
        $this->assertSame('/tmp/somewhere', $this->manager->path());
    }
}
```

- [ ] **Step 2: Write the failing push tests**

Replace `tests/Feature/PushCommandTest.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Events\TranslationsPushed;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PushCommandTest extends TestCase
{
    private const HEADER = ['key', 'group', 'default', 'en', 'fr'];

    private const FAILED = 'These credentials do not match our records.';

    private FakeGoogleSheetsService $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
        $this->sheets = $this->fakeSheets();
        $this->writeLangFiles([
            'en/auth.php' => ['failed' => self::FAILED, 'throttle' => 'Too many attempts.'],
            'en/resources/schools.php' => ['title' => 'Schools'],
            'fr/auth.php' => ['failed' => 'Identifiants invalides.'],
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function seed(array $rows, array $header = self::HEADER): void
    {
        $this->sheets->seed('Translations', [$header, ...$rows]);
    }

    private function rows(): array
    {
        return $this->sheets->rows('Translations');
    }

    #[Test]
    public function it_builds_the_sheet_from_code_on_the_first_push(): void
    {
        $this->artisan('translations:push')
            ->expectsOutputToContain('3 new key(s) added')
            ->assertSuccessful();

        $this->assertSame([
            self::HEADER,
            ['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.'],
            ['title', 'resources/schools', 'Schools', 'Schools'],
        ], $this->rows());
    }

    #[Test]
    public function it_fails_without_the_source_locale_directory(): void
    {
        File::deleteDirectory(lang_path('en'));

        $this->artisan('translations:push')->assertFailed();

        $this->assertArrayNotHasKey('Translations', $this->sheets->tabs);
    }

    #[Test]
    public function it_keeps_sheet_edits_translations_and_extra_columns(): void
    {
        $this->seed([
            ['failed', 'auth', self::FAILED, 'Those credentials are wrong.', 'Mauvais identifiants.', 'reviewed', 'Shorter'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.', '', '', ''],
            ['title', 'resources/schools', 'Schools', 'Schools', '', 'draft', ''],
        ], [...self::HEADER, 'status', 'notes']);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([
            [...self::HEADER, 'status', 'notes'],
            ['failed', 'auth', self::FAILED, 'Those credentials are wrong.', 'Mauvais identifiants.', 'reviewed', 'Shorter'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.'],
            ['title', 'resources/schools', 'Schools', 'Schools', '', 'draft'],
        ], $this->rows());
    }

    #[Test]
    public function it_updates_english_changed_in_code_and_flags_translations_for_review(): void
    {
        $this->seed([['failed', 'auth', 'Old text', 'Old text', 'Ancien texte']]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('need their translations reviewed')
            ->expectsOutputToContain('- auth.failed')
            ->assertSuccessful();

        $this->assertSame(['failed', 'auth', self::FAILED, self::FAILED, 'Ancien texte'], $this->rows()[1]);
    }

    #[Test]
    public function it_keeps_the_sheet_english_when_both_sides_changed(): void
    {
        $this->seed([['failed', 'auth', 'Old text', 'Sheet text', '']]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('changed in both code and the sheet')
            ->assertSuccessful();

        $this->assertSame(['failed', 'auth', self::FAILED, 'Sheet text', 'Identifiants invalides.'], $this->rows()[1]);
    }

    #[Test]
    public function it_fills_blank_cells_from_code(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, '']]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('1 empty cell(s) filled from code')
            ->assertSuccessful();

        $this->assertSame('Identifiants invalides.', $this->rows()[1][4]);
    }

    #[Test]
    public function it_drops_rows_for_keys_removed_from_code(): void
    {
        $this->seed([
            ['failed', 'auth', self::FAILED, self::FAILED, ''],
            ['gone', 'auth', 'Gone', 'Gone', 'Parti'],
            ['throttle', 'auth', 'Too many attempts.', 'Too many attempts.', ''],
            ['title', 'resources/schools', 'Schools', 'Schools', ''],
        ]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('- auth.gone')
            ->assertSuccessful();

        $this->assertCount(4, $this->rows());
        $this->assertNotContains('gone', array_column($this->rows(), 0));
    }

    #[Test]
    public function it_adds_a_column_for_a_new_locale_after_the_existing_ones(): void
    {
        $this->writeLangFiles(['es/auth.php' => ['failed' => 'Credenciales inválidas.']]);
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, '', 'note']], [...self::HEADER, 'notes']);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([...self::HEADER, 'es', 'notes'], $this->rows()[0]);
        $this->assertSame(['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.', 'Credenciales inválidas.', 'note'], $this->rows()[1]);
    }

    #[Test]
    public function it_keeps_the_column_of_a_locale_whose_directory_was_removed(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, '', 'Ungültige Anmeldedaten.']], [...self::HEADER, 'de']);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame([...self::HEADER, 'de'], $this->rows()[0]);
        $this->assertSame('Ungültige Anmeldedaten.', $this->rows()[1][5]);
    }

    #[Test]
    public function it_backs_up_the_sheet_before_writing(): void
    {
        $this->seed([['failed', 'auth', 'Old', 'Old', 'Ancien']]);
        $before = $this->rows();

        $this->artisan('translations:push')
            ->expectsOutputToContain('Backup saved')
            ->assertSuccessful();

        $backups = File::files($this->backupPath());
        $this->assertCount(1, $backups);
        $this->assertSame($before, json_decode(File::get($backups[0]->getPathname()), true)['rows']);
    }

    #[Test]
    public function it_skips_the_backup_when_asked_or_disabled(): void
    {
        $this->seed([['failed', 'auth', 'Old', 'Old', '']]);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->backupPath());

        config()->set('laravel-translation.backup.path', false);
        $this->artisan('translations:push')
            ->expectsOutputToContain('Backups disabled')
            ->assertSuccessful();
    }

    #[Test]
    public function it_writes_nothing_on_a_dry_run(): void
    {
        $this->artisan('translations:push', ['--dry-run' => true])
            ->expectsOutputToContain('3 new key(s) added')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertArrayNotHasKey('Translations', $this->sheets->tabs);

        $this->seed([['failed', 'auth', 'Old', 'Old', '']]);
        $before = $this->rows();

        $this->artisan('translations:push', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->rows());
        $this->assertDirectoryDoesNotExist($this->backupPath());
    }

    #[Test]
    public function it_rebuilds_from_code_with_fresh(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, 'Sheet text', 'Feuille', 'note']], [...self::HEADER, 'notes']);

        $this->artisan('translations:push', ['--fresh' => true, '--no-backup' => true])->assertSuccessful();

        $this->assertSame(self::HEADER, $this->rows()[0]);
        $this->assertSame(['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'], $this->rows()[1]);
    }

    #[Test]
    public function it_stops_when_a_required_column_is_missing(): void
    {
        $this->sheets->seed('Translations', [['key', 'group', 'en', 'fr'], ['failed', 'auth', 'x', 'y']]);

        $this->artisan('translations:push')
            ->expectsOutputToContain('missing the column(s): default')
            ->assertFailed();

        $this->assertSame([['key', 'group', 'en', 'fr'], ['failed', 'auth', 'x', 'y']], $this->rows());
    }

    #[Test]
    public function it_dispatches_translations_pushed_after_writing(): void
    {
        Event::fake([TranslationsPushed::class]);

        $this->artisan('translations:push', ['--dry-run' => true])->assertSuccessful();
        Event::assertNotDispatched(TranslationsPushed::class);

        $this->artisan('translations:push')->assertSuccessful();
        Event::assertDispatched(TranslationsPushed::class, fn (TranslationsPushed $event): bool => $event->sheetName === 'Translations'
            && $event->sheetId === 1000
            && $event->headers === self::HEADER
            && count($event->rows) === 3);
    }

    #[Test]
    public function it_formats_the_sheet_only_when_enabled(): void
    {
        $this->artisan('translations:push')->assertSuccessful();
        $this->assertSame([], $this->sheets->batches);

        config()->set('laravel-translation.format', true);
        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertCount(1, $this->sheets->batches);
        $this->assertNotEmpty(array_filter($this->sheets->batches[0], fn (array $request): bool => isset($request['addProtectedRange'])));
    }

    #[Test]
    public function it_warns_about_duplicate_rows_and_keeps_the_first(): void
    {
        $this->seed([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Premier'],
            ['failed', 'auth', self::FAILED, self::FAILED, 'Second'],
        ]);

        $this->artisan('translations:push', ['--no-backup' => true])
            ->expectsOutputToContain('duplicate row(s)')
            ->assertSuccessful();

        $this->assertSame('Premier', $this->rows()[1][4]);
        $this->assertCount(4, $this->rows());
    }

    #[Test]
    public function it_keeps_newlines_and_spaces_in_cells(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, "Ligne un\nLigne deux\u{00A0}"]]);

        $this->artisan('translations:push', ['--no-backup' => true])->assertSuccessful();

        $this->assertSame("Ligne un\nLigne deux\u{00A0}", $this->rows()[1][4]);
    }
}
```

- [ ] **Step 3: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Feature/PushCommandTest.php tests/Unit/TranslationBackupManagerTest.php`
Expected: FAIL — `TranslationsPushed` not found, and backup tests failing on the old `backup(string $locale, array $rows)` signature.

- [ ] **Step 4: Rewrite the backup manager**

Replace the body of `src/Services/TranslationBackupManager.php` after `path()` (delete `localePath()`, replace `backup()` and `prune()`, keep `isEnabled()`, `path()`, `ensureGitignore()`):

```php
    /**
     * Save the sheet's cells as JSON before a push overwrites them.
     * Returns the file written, or null when backups are disabled.
     *
     * @param  list<list<string>>  $rows
     */
    public function backup(array $rows): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $root = $this->path();
        File::ensureDirectoryExists($root);
        $this->ensureGitignore($root);

        $filePath = $root.DIRECTORY_SEPARATOR.date('Y-m-d_His').'.json';

        File::put($filePath, json_encode([
            'sheet' => config('laravel-translation.sheet', 'Translations'),
            'created_at' => date(\DateTimeInterface::ATOM),
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $filePath;
    }

    /**
     * Keep the $keep newest backups (by mtime) and delete the rest. Per-locale
     * folders left by 0.4 are not touched. Returns how many were deleted.
     */
    public function prune(int $keep): int
    {
        if ($keep < 0) {
            throw new \InvalidArgumentException('Keep count must be a non-negative integer');
        }

        if (! $this->isEnabled() || ! File::isDirectory($this->path())) {
            return 0;
        }

        $toDelete = collect(File::files($this->path()))
            ->filter(fn ($file) => $file->getExtension() === 'json')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values()
            ->slice($keep);

        foreach ($toDelete as $file) {
            File::delete($file->getPathname());
        }

        return $toDelete->count();
    }
```

- [ ] **Step 5: Write the event**

Create `src/Events/TranslationsPushed.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Events;

use PaperleafTech\LaravelTranslation\Sheet\SheetRow;

/**
 * Fired after translations:push writes the sheet (never on a dry run), so an
 * app can add its own tabs, validation or formatting. Listeners reach the
 * Google client through app(GoogleSheetsService::class).
 */
final class TranslationsPushed
{
    /**
     * @param  list<string>  $headers  the header row as written
     * @param  list<SheetRow>  $rows  the rows as written, in sheet order
     */
    public function __construct(
        public readonly string $sheetName,
        public readonly int $sheetId,
        public readonly array $headers,
        public readonly array $rows,
    ) {}
}
```

- [ ] **Step 6: Rewrite the push command**

Replace `src/Commands/PushCommand.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Events\TranslationsPushed;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Services\TranslationBackupManager;
use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Sheet\ReconcileReport;
use PaperleafTech\LaravelTranslation\Sheet\SheetContents;
use PaperleafTech\LaravelTranslation\Sheet\SheetFormatter;
use PaperleafTech\LaravelTranslation\Sheet\SheetReconciler;
use PaperleafTech\LaravelTranslation\Sheet\TranslationSheet;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use RuntimeException;

class PushCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:push
        {--dry-run : Show what would change without writing to the sheet}
        {--no-backup : Skip the local backup of the sheet}
        {--fresh : Rebuild the sheet from code, ignoring its current contents}';

    protected $description = 'Push every locale\'s translations to the translation sheet.';

    public function handle(
        TranslationCatalogue $catalogue,
        TranslationSheet $sheet,
        SheetReconciler $reconciler,
        TranslationBackupManager $backups,
        SheetFormatter $formatter,
        GoogleSheetsService $sheets,
    ): int {
        $source = TranslationConventions::sourceLocale();
        $locales = $this->discoverLocales();

        if (! in_array($source, $locales, true)) {
            $this->error('No '.lang_path($source).' directory. Push needs the source locale.');

            return self::FAILURE;
        }

        try {
            $existing = $sheet->read($source, $locales);

            $code = [];
            foreach ($locales as $locale) {
                $code[$locale] = $catalogue->appGroups($locale);
            }

            if ($existing !== null && $existing->rows !== [] && ! $this->option('no-backup') && ! $this->option('dry-run')) {
                $this->backup($backups, $existing);
            }

            $result = $reconciler->reconcile($source, $code, $this->option('fresh') ? null : $existing);
            $this->report($result->report, count($result->rows), $source);

            if ($this->option('dry-run')) {
                $this->info('Dry run: the sheet was not changed.');

                return self::SUCCESS;
            }

            $sheet->write($result->headers, $result->rows);

            if (config('laravel-translation.format', true) && ($tab = $sheets->getSheet($sheet->name())) !== null) {
                $localeHeaders = array_values(array_filter($result->headers, fn (string $header): bool => in_array($header, $locales, true)));
                $sheets->batchUpdate($formatter->requests($tab, $result->headers, $localeHeaders));
            }

            event(new TranslationsPushed($sheet->name(), (int) $sheets->getSheetId($sheet->name()), $result->headers, $result->rows));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ Pushed '.count($result->rows).' row(s) to "'.$sheet->name().'".');
        $this->info('View sheet: '.$sheet->url());

        return self::SUCCESS;
    }

    protected function backup(TranslationBackupManager $backups, SheetContents $existing): void
    {
        if (! $backups->isEnabled()) {
            $this->info('Backups disabled via TRANSLATION_BACKUP_PATH.');

            return;
        }

        try {
            $this->info('✓ Backup saved: '.$backups->backup($existing->grid));

            if (config('laravel-translation.backup.auto_prune', true)) {
                $keep = (int) config('laravel-translation.backup.keep', 5);
                $deleted = $backups->prune($keep);

                if ($deleted > 0) {
                    $this->info("  Pruned {$deleted} old backup(s) (keeping {$keep} most recent)");
                }
            }
        } catch (\Exception $e) {
            $this->warn("Failed to create backup: {$e->getMessage()}");
            $this->warn('Continuing with push...');
        }
    }

    protected function report(ReconcileReport $report, int $rows, string $source): void
    {
        $this->info("{$rows} key(s) in code.");

        if ($report->new !== []) {
            $this->line('  - '.count($report->new).' new key(s) added');
        }

        if ($report->sourceChanged !== []) {
            $this->line('  - '.count($report->sourceChanged)." key(s) had their {$source} text changed in code");
        }

        if ($report->filledFromCode > 0) {
            $this->line("  - {$report->filledFromCode} empty cell(s) filled from code");
        }

        $this->listed('key(s) were removed from code; their rows were dropped (notes survive in the backup)', $report->removed);
        $this->listed("key(s) changed in both code and the sheet; kept the sheet's {$source} text", $report->conflicts);
        $this->listed("key(s) need their translations reviewed: the {$source} text changed", $report->needsReview);
        $this->listed("key(s) exist in other locales but not in {$source}", $report->sourceMissing);
        $this->listed('duplicate row(s) in the sheet were dropped; the first of each was kept', $report->duplicates);
    }

    /**
     * @param  list<string>  $labels
     */
    protected function listed(string $message, array $labels): void
    {
        if ($labels === []) {
            return;
        }

        $this->warn('  ⚠ '.count($labels).' '.$message.':');

        foreach ($labels as $label) {
            $this->line("      - {$label}");
        }
    }
}
```

- [ ] **Step 7: Remove the old reconciler**

```bash
git rm src/Services/TranslationReconciler.php tests/Unit/TranslationReconcilerTest.php
```

In `src/LaravelTranslationServiceProvider.php`, delete the `use ...\Services\TranslationReconciler;` import and the `$this->app->singleton(TranslationReconciler::class, ...)` line.

- [ ] **Step 8: Run the tests**

Run: `composer test`
Expected: all pass.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint src/Commands/PushCommand.php src/Services/TranslationBackupManager.php src/Events src/LaravelTranslationServiceProvider.php tests/Feature/PushCommandTest.php tests/Unit/TranslationBackupManagerTest.php
git add -A src tests
git commit -m "Push every locale to the single translation sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Single-sheet pull

**Files:**
- Rewrite: `src/Commands/PullCommand.php`, `tests/Feature/PullCommandTest.php`, `src/Support/TranslationConventions.php`, `tests/Unit/TranslationConventionsTest.php`

**Interfaces:**
- Consumes: `TranslationSheet`, `SheetContents`, `SheetRow` (Task 3); `TranslationCatalogue::appGroups()`, `TranslationCatalogue::JSON_GROUP` (Task 2 / existing); `Placeholders::match/in` (existing); `TranslationFileWriter::updateFile(string $filePath, array $updates): array{updated, skipped_missing, skipped_non_string}` (existing).
- Produces: command `translations:pull {locale?} [--dry-run]`; `TranslationConventions` reduced to `sourceLocale()` and `isSourceLocale()`.

- [ ] **Step 1: Write the failing pull tests**

Replace `tests/Feature/PullCommandTest.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Feature;

use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Tests\Support\FakeGoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PullCommandTest extends TestCase
{
    private const HEADER = ['key', 'group', 'default', 'en', 'fr'];

    private const FAILED = 'These credentials do not match our records.';

    private FakeGoogleSheetsService $sheets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryLangPath();
        $this->sheets = $this->fakeSheets();
        $this->writeLangFiles([
            'en/auth.php' => "<?php\n\nreturn [\n    // Shown when sign-in fails.\n    'failed' => '".self::FAILED."',\n    'greeting' => 'Hello, :name',\n];\n",
            'fr/auth.php' => "<?php\n\nreturn [\n    // Shown when sign-in fails.\n    'failed' => 'Ancienne valeur.',\n    'greeting' => '',\n];\n",
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function seed(array $rows, array $header = self::HEADER): void
    {
        $this->sheets->seed('Translations', [$header, ...$rows]);
    }

    private function lines(string $locale, string $group = 'auth'): array
    {
        return require lang_path("{$locale}/{$group}.php");
    }

    #[Test]
    public function it_writes_each_locale_column_into_its_lang_files(): void
    {
        $this->seed([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'],
            ['greeting', 'auth', 'Hello, :name', 'Hi, :name', 'Bonjour, :name'],
        ]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('Updated 2 key(s) in')
            ->assertSuccessful();

        $this->assertSame('Identifiants invalides.', $this->lines('fr')['failed']);
        $this->assertSame('Bonjour, :name', $this->lines('fr')['greeting']);
        $this->assertSame('Hi, :name', $this->lines('en')['greeting']);
    }

    #[Test]
    public function it_keeps_comments_in_the_files_it_updates(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertStringContainsString('// Shown when sign-in fails.', File::get(lang_path('fr/auth.php')));
    }

    #[Test]
    public function it_never_writes_a_blank_cell(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, '', '']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame(self::FAILED, $this->lines('en')['failed']);
        $this->assertSame('Ancienne valeur.', $this->lines('fr')['failed']);
    }

    #[Test]
    public function it_pulls_into_files_in_subdirectories(): void
    {
        $this->writeLangFiles([
            'en/resources/schools.php' => ['title' => 'Schools', 'form' => ['name' => 'Name']],
            'fr/resources/schools.php' => ['title' => '', 'form' => ['name' => '']],
        ]);
        $this->seed([
            ['form.name', 'resources/schools', 'Name', 'Name', 'Nom'],
            ['title', 'resources/schools', 'Schools', 'Schools', 'Écoles'],
        ]);

        $this->artisan('translations:pull', ['locale' => 'fr'])->assertSuccessful();

        $this->assertSame('Écoles', $this->lines('fr', 'resources/schools')['title']);
        $this->assertSame('Nom', $this->lines('fr', 'resources/schools')['form']['name']);
    }

    #[Test]
    public function it_rejects_values_whose_placeholders_differ_from_the_code_and_fails(): void
    {
        $this->seed([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.'],
            ['greeting', 'auth', 'Hello, :name', 'Hello, :name', 'Bonjour'],
        ]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('1 value(s) rejected')
            ->expectsOutputToContain('auth.greeting')
            ->assertFailed();

        $this->assertSame('Identifiants invalides.', $this->lines('fr')['failed']);
        $this->assertSame('', $this->lines('fr')['greeting']);
    }

    #[Test]
    public function it_rejects_an_english_edit_that_drops_a_placeholder(): void
    {
        $this->seed([['greeting', 'auth', 'Hello, :name', 'Hello there', '']]);

        $this->artisan('translations:pull')->assertFailed();

        $this->assertSame('Hello, :name', $this->lines('en')['greeting']);
    }

    #[Test]
    public function it_accepts_placeholders_whose_capitalisation_differs(): void
    {
        $this->seed([['greeting', 'auth', 'Hello, :name', 'Hello, :name', ':Name, bonjour']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame(':Name, bonjour', $this->lines('fr')['greeting']);
    }

    #[Test]
    public function it_skips_the_check_when_code_has_no_source_line(): void
    {
        $this->writeLangFiles(['fr/extra.php' => ['only' => '']]);
        $this->seed([['only', 'extra', '', '', 'Seulement :thing']]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame('Seulement :thing', $this->lines('fr', 'extra')['only']);
    }

    #[Test]
    public function it_pulls_only_the_named_locale(): void
    {
        $this->seed([['greeting', 'auth', 'Hello, :name', 'Hi, :name', 'Bonjour, :name']]);

        $this->artisan('translations:pull', ['locale' => 'fr'])->assertSuccessful();

        $this->assertSame('Bonjour, :name', $this->lines('fr')['greeting']);
        $this->assertSame('Hello, :name', $this->lines('en')['greeting']);
    }

    #[Test]
    public function it_fails_for_a_locale_without_a_column(): void
    {
        $this->seed([]);

        $this->artisan('translations:pull', ['locale' => 'es'])
            ->expectsOutputToContain('no "es" column')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_the_tab_is_missing(): void
    {
        $this->artisan('translations:pull')
            ->expectsOutputToContain('Run translations:push first')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_a_required_column_is_missing(): void
    {
        $this->seed([], ['key', 'group', 'en', 'fr']);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('missing the column(s): default')
            ->assertFailed();
    }

    #[Test]
    public function it_writes_nothing_on_a_dry_run(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, 'Identifiants invalides.']]);
        $before = File::get(lang_path('fr/auth.php'));

        $this->artisan('translations:pull', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('1 value(s) to apply')
            ->assertSuccessful();

        $this->assertSame($before, File::get(lang_path('fr/auth.php')));
    }

    #[Test]
    public function it_ignores_json_vendor_and_keyless_rows(): void
    {
        $this->seed([
            ['Hello!', '*', 'Hello!', 'Hello!', 'Bonjour !'],
            ['saved', 'filament::actions', 'Saved', 'Saved', 'Enregistré'],
            ['', 'auth', '', '', 'Orphelin'],
        ]);
        $before = File::get(lang_path('fr/auth.php'));

        $this->artisan('translations:pull')
            ->doesntExpectOutputToContain('not found')
            ->assertSuccessful();

        $this->assertSame($before, File::get(lang_path('fr/auth.php')));
    }

    #[Test]
    public function it_warns_about_keys_missing_from_code(): void
    {
        $this->seed([['captcha', 'auth', '', '', 'Captcha invalide.']]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('Skipped 1 key(s) not in')
            ->expectsOutputToContain('- auth.captcha')
            ->assertSuccessful();
    }

    #[Test]
    public function it_warns_when_the_file_is_missing(): void
    {
        $this->seed([['heading', 'welcome', 'Welcome', 'Welcome', 'Bienvenue']]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('welcome.php not found')
            ->assertSuccessful();

        $this->assertFileDoesNotExist(lang_path('fr/welcome.php'));
    }

    #[Test]
    public function it_keeps_newlines_and_surrounding_spaces_in_values(): void
    {
        $this->seed([['failed', 'auth', self::FAILED, self::FAILED, " Ligne un\nLigne deux\u{00A0}"]]);

        $this->artisan('translations:pull')->assertSuccessful();

        $this->assertSame(" Ligne un\nLigne deux\u{00A0}", $this->lines('fr')['failed']);
    }

    #[Test]
    public function it_uses_the_first_of_duplicate_rows_and_warns(): void
    {
        $this->seed([
            ['failed', 'auth', self::FAILED, self::FAILED, 'Premier'],
            ['failed', 'auth', self::FAILED, self::FAILED, 'Second'],
        ]);

        $this->artisan('translations:pull')
            ->expectsOutputToContain('duplicate row(s)')
            ->assertSuccessful();

        $this->assertSame('Premier', $this->lines('fr')['failed']);
    }
}
```

Replace `tests/Unit/TranslationConventionsTest.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TranslationConventionsTest extends TestCase
{
    #[Test]
    public function the_source_locale_defaults_to_english(): void
    {
        $this->assertSame('en', TranslationConventions::sourceLocale());
        $this->assertTrue(TranslationConventions::isSourceLocale('en'));
        $this->assertFalse(TranslationConventions::isSourceLocale('fr'));
    }

    #[Test]
    public function it_reads_the_source_locale_from_config(): void
    {
        config()->set('laravel-translation.source_locale', 'en_CA');

        $this->assertSame('en_CA', TranslationConventions::sourceLocale());
        $this->assertTrue(TranslationConventions::isSourceLocale('en_CA'));
        $this->assertFalse(TranslationConventions::isSourceLocale('en'));
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit tests/Feature/PullCommandTest.php`
Expected: FAIL — the old pull reads `Translations - {locale}` tabs, so e.g. `it_writes_each_locale_column_into_its_lang_files` warns `Sheet tab 'Translations - en' not found`.

- [ ] **Step 3: Rewrite the pull command**

Replace `src/Commands/PullCommand.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Concerns\DiscoversLocales;
use PaperleafTech\LaravelTranslation\Services\TranslationCatalogue;
use PaperleafTech\LaravelTranslation\Services\TranslationFileWriter;
use PaperleafTech\LaravelTranslation\Sheet\SheetContents;
use PaperleafTech\LaravelTranslation\Sheet\TranslationSheet;
use PaperleafTech\LaravelTranslation\Support\Placeholders;
use PaperleafTech\LaravelTranslation\Support\TranslationConventions;
use RuntimeException;

class PullCommand extends Command
{
    use DiscoversLocales;

    protected $signature = 'translations:pull {locale? : Pull only this locale}
        {--dry-run : Show what would change without writing files}';

    protected $description = 'Pull translations from the translation sheet into existing lang files.';

    public function handle(TranslationCatalogue $catalogue, TranslationSheet $sheet, TranslationFileWriter $writer): int
    {
        $source = TranslationConventions::sourceLocale();

        try {
            $contents = $sheet->read($source, $this->discoverLocales());
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($contents === null) {
            $this->error('The spreadsheet has no "'.$sheet->name().'" tab. Run translations:push first.');

            return self::FAILURE;
        }

        $locales = [$source, ...$contents->locales];
        $requested = $this->argument('locale');

        if ($requested !== null) {
            if (! in_array($requested, $locales, true)) {
                $this->error("The sheet has no \"{$requested}\" column with a matching ".lang_path($requested).' directory.');

                return self::FAILURE;
            }

            $locales = [$requested];
        }

        if ($contents->duplicates !== []) {
            $this->warn('⚠ '.count($contents->duplicates).' duplicate row(s) in the sheet; used the first of each:');
            foreach ($contents->duplicates as $label) {
                $this->line("    - {$label}");
            }
        }

        [$updates, $rejected] = $this->collect($contents, $locales, $catalogue->appGroups($source));

        if ($rejected !== []) {
            $this->warn('⚠ '.count($rejected)." value(s) rejected: their :placeholders differ from the {$source} line in code. Fix them in the sheet, then pull again.");
            $this->table(['Locale', 'Key', 'Expected', 'Found'], $rejected);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: no files were changed.');

            foreach ($updates as $locale => $groups) {
                foreach ($groups as $group => $values) {
                    $this->line('  '.$this->relativePath(lang_path("{$locale}/{$group}.php")).' — '.count($values).' value(s) to apply');
                }
            }

            return $rejected === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($updates as $locale => $groups) {
            foreach ($groups as $group => $values) {
                $this->apply($writer, $locale, (string) $group, $values);
            }
        }

        $this->info('✓ Pulled from "'.$sheet->name().'".');

        return $rejected === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Non-blank cells to write, by locale and group, and the ones whose
     * :placeholders differ from the source line in code, which is what the
     * app passes replacements to.
     *
     * @param  list<string>  $locales
     * @param  array<string, array<string, string>>  $sourceLines  group => key => line, from code
     * @return array{0: array<string, array<string, array<string, string>>>, 1: list<array{0: string, 1: string, 2: string, 3: string}>}
     */
    protected function collect(SheetContents $contents, array $locales, array $sourceLines): array
    {
        $updates = [];
        $rejected = [];

        foreach ($contents->rows as $row) {
            if ($row->group === '' || $row->key === '' || $row->group === TranslationCatalogue::JSON_GROUP || str_contains($row->group, '::')) {
                continue;
            }

            $reference = $sourceLines[$row->group][$row->key] ?? null;

            foreach ($locales as $locale) {
                $value = $row->value($locale);

                if ($value === '') {
                    continue;
                }

                if ($reference !== null && ! Placeholders::match($reference, $value)) {
                    $rejected[] = [$locale, $row->label(), $this->placeholderList($reference), $this->placeholderList($value)];

                    continue;
                }

                $updates[$locale][$row->group][$row->key] = $value;
            }
        }

        return [$updates, $rejected];
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function apply(TranslationFileWriter $writer, string $locale, string $group, array $values): void
    {
        $filePath = lang_path("{$locale}/{$group}.php");
        $relPath = $this->relativePath($filePath);
        $stats = $writer->updateFile($filePath, $values);

        if ($stats['updated'] !== []) {
            $this->info('  ✓ Updated '.count($stats['updated'])." key(s) in {$relPath}");
        }

        if ($stats['skipped_missing'] !== [] && ! File::exists($filePath)) {
            $this->warn("  ⚠ {$relPath} not found; ".count($stats['skipped_missing']).' key(s) skipped (add the file with starter keys, then pull again).');
        } elseif ($stats['skipped_missing'] !== []) {
            $this->warn('  ⚠ Skipped '.count($stats['skipped_missing'])." key(s) not in {$relPath} (add them in code first, then pull again):");
            foreach ($stats['skipped_missing'] as $key) {
                $this->line("      - {$group}.{$key}");
            }
        }

        if ($stats['skipped_non_string'] !== []) {
            $this->warn('  ⚠ Skipped '.count($stats['skipped_non_string'])." key(s) in {$relPath} whose value in code is not a plain string:");
            foreach ($stats['skipped_non_string'] as $key) {
                $this->line("      - {$group}.{$key}");
            }
        }
    }

    protected function placeholderList(string $line): string
    {
        return '['.implode(', ', Placeholders::in($line)).']';
    }

    protected function relativePath(string $absolutePath): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $base) ? substr($absolutePath, strlen($base)) : $absolutePath;
    }
}
```

- [ ] **Step 4: Reduce `TranslationConventions` to the source locale**

Replace `src/Support/TranslationConventions.php` with:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Support;

/**
 * The source locale every other locale is translated from.
 */
final class TranslationConventions
{
    public static function sourceLocale(): string
    {
        return (string) config('laravel-translation.source_locale', 'en');
    }

    public static function isSourceLocale(string $locale): bool
    {
        return $locale === self::sourceLocale();
    }
}
```

Then confirm nothing references the removed members:

Run: `grep -rn "SOURCE_LOCALE\|sheetNameFor\|headersFor\|HEADER_" src tests`
Expected: no output.

- [ ] **Step 5: Run the tests**

Run: `composer test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint src/Commands/PullCommand.php src/Support/TranslationConventions.php tests/Feature/PullCommandTest.php tests/Unit/TranslationConventionsTest.php
git add -A src tests
git commit -m "Pull every locale column from the single translation sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Config, docs and version

**Files:**
- Rewrite: `config/laravel-translation.php`
- Modify: `README.md`, `CHANGELOG.md`, `composer.json`
- Test: `tests/Unit/ConfigTest.php` (create)

**Interfaces:**
- Consumes: every config key read in Tasks 2–7: `credentials_path`, `spreadsheet_id`, `scopes`, `sheet`, `source_locale`, `format`, `backup.path`, `backup.keep`, `backup.auto_prune`.

- [ ] **Step 1: Write the failing config test**

Create `tests/Unit/ConfigTest.php`:

```php
<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ConfigTest extends TestCase
{
    #[Test]
    public function the_published_config_has_the_single_sheet_keys_only(): void
    {
        $config = require __DIR__.'/../../config/laravel-translation.php';

        $this->assertSame(
            ['credentials_path', 'spreadsheet_id', 'scopes', 'sheet', 'source_locale', 'format', 'backup'],
            array_keys($config),
        );
        $this->assertSame('Translations', $config['sheet']);
        $this->assertSame('en', $config['source_locale']);
        $this->assertTrue($config['format']);
        $this->assertSame(['https://www.googleapis.com/auth/spreadsheets'], $config['scopes']);
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/Unit/ConfigTest.php`
Expected: FAIL — keys include `key_column`, `original_value_column`, `updated_value_column`, `header_row`.

- [ ] **Step 3: Rewrite the config**

Replace `config/laravel-translation.php` with:

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Service Account Credentials
    |--------------------------------------------------------------------------
    |
    | Path to the Google service account JSON file with Sheets API access.
    |
    */
    'credentials_path' => env('TRANSLATION_CREDENTIALS_PATH', storage_path('app/laravel-translation-credentials.json')),

    /*
    |--------------------------------------------------------------------------
    | Google Spreadsheet ID
    |--------------------------------------------------------------------------
    |
    | Found in the spreadsheet URL:
    | https://docs.google.com/spreadsheets/d/{SPREADSHEET_ID}/edit
    |
    */
    'spreadsheet_id' => env('TRANSLATION_SPREADSHEET_ID'),

    /*
    |--------------------------------------------------------------------------
    | Google API Scopes
    |--------------------------------------------------------------------------
    |
    | A literal URL rather than Google\Service\Sheets::SPREADSHEETS, so a
    | published config doesn't fatal when the package is a dev dependency
    | and production installs with --no-dev.
    |
    */
    'scopes' => [
        'https://www.googleapis.com/auth/spreadsheets',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sheet Tab
    |--------------------------------------------------------------------------
    |
    | The tab push writes and pull reads. It holds every locale: key, group,
    | default, then one column per locale. Other columns you add (status,
    | notes, ...) are kept with their row on every push.
    |
    */
    'sheet' => env('TRANSLATION_SHEET', 'Translations'),

    /*
    |--------------------------------------------------------------------------
    | Source Locale
    |--------------------------------------------------------------------------
    |
    | The locale every other locale is translated from. Its lang directory
    | must exist for push, and translations:check compares the others to it.
    |
    */
    'source_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Format the Sheet
    |--------------------------------------------------------------------------
    |
    | Apply the package's formatting after each push: frozen header, filter,
    | column widths, shaded read-only columns, red empty translations and a
    | warning on edits to key, group and default.
    |
    */
    'format' => env('TRANSLATION_FORMAT_SHEET', true),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | A JSON snapshot of the tab is written before each push. Set
    | TRANSLATION_BACKUP_PATH to false or null to disable backups.
    |
    */
    'backup' => [
        // Relative paths resolve via storage_path(). Absolute paths are used verbatim.
        'path' => env('TRANSLATION_BACKUP_PATH', 'app/translation-backups'),

        // Number of backups to keep.
        'keep' => env('TRANSLATION_BACKUP_KEEP', 5),

        // Delete older backups after writing a new one.
        'auto_prune' => env('TRANSLATION_BACKUP_AUTO_PRUNE', true),
    ],
];
```

- [ ] **Step 4: Run the tests**

Run: `composer test`
Expected: all pass.

- [ ] **Step 5: Update the README**

In `README.md`:

1. Under `## Upgrading`, add above `### From 0.2.x to 0.3.0`:

```markdown
### From 0.4.x to 0.5.0

- One `Translations` tab replaces the `Translations - {locale}` tabs. Before upgrading, run `php artisan translations:pull` on 0.4 so code holds the latest translations. After upgrading, run `php artisan translations:push` to build the new tab, then delete the old tabs.
- `translations:push` no longer takes a locale; it always writes every locale. New options: `--dry-run` and `--fresh` (replaces `--clear` and `--force-initial`).
- `translations:pull {locale?}` reads the locale's column from the single tab.
- Config: `key_column`, `original_value_column`, `updated_value_column` and `header_row` are gone; `sheet`, `source_locale` and `format` are new. Update a published config to match `config/laravel-translation.php`.
```

2. Replace the whole `## Sheet layout` section with:

```markdown
## Sheet layout

Every locale lives in one tab, `Translations` (configurable via `TRANSLATION_SHEET`):

| key | group | default | en | fr | status | notes |
|---|---|---|---|---|---|---|
| `failed` | `auth` | These credentials do not match our records. | These credentials do not match our records. | Identifiants invalides. | reviewed | |

- **key / group** — the line's key and its file (`auth`, `resources/schools`). Pull writes to `lang/{locale}/{group}.php`.
- **default** — the English in code as of the last push. Push compares it with code and the `en` column to tell a developer's change from an editor's. It is protected (with a warning) and can be hidden.
- **en** — editable English. Edit the wording here and pull it into code.
- **one column per locale** — columns are found by header, and a header counts as a locale when `lang/` has a directory with that name. Push adds a column when a new locale appears.
- **anything else** (`status`, `notes`, …) — kept with its row on every push.

When code and the sheet both change a line's English, push keeps the sheet's text and lists the key, so an editor's work is never overwritten silently. A blank cell never erases anything on pull.
```

3. Replace the whole `### Push translations to Google Sheets` subsection with:

````markdown
### Push translations to Google Sheets

```bash
# Push every locale under lang/ to the Translations tab
php artisan translations:push

# See what would change without writing
php artisan translations:push --dry-run

# Rebuild the tab from code, ignoring what is in it (a backup is still taken)
php artisan translations:push --fresh

# Skip the backup for this run
php artisan translations:push --no-backup
```

Push reports new and removed keys, English changed in code (and which translations need review), conflicts, and empty cells it filled from code. Rows whose key is gone from code are dropped; their notes survive in the backup.
````

4. Replace the whole `### Pull translations from Google Sheets` subsection's code block and the paragraph after it (keep `#### Surgical updates` and below) with:

````markdown
### Pull translations from Google Sheets

```bash
# Pull every locale column
php artisan translations:pull

# Pull one locale
php artisan translations:pull fr

# Preview without writing files
php artisan translations:pull --dry-run
```

Pull rejects a value whose `:placeholders` differ from the English line in code, lists it, and exits with a failure after writing the rest. Rows for JSON (`*`) and package (`package::file`) groups are ignored; edit those files directly.
````

5. Replace the `## Backups` section's tree and paragraph with:

````markdown
## Backups

Before each push, the tab's cells are saved as JSON:

```
storage/app/translation-backups/
├── .gitignore           ← written on first run ("*\n!.gitignore")
├── 2026-10-05_143012.json
└── 2026-10-05_152244.json
```

`TRANSLATION_BACKUP_KEEP` controls how many are kept (default 5). `TRANSLATION_BACKUP_AUTO_PRUNE` toggles pruning. Set `TRANSLATION_BACKUP_PATH=false` to disable backups; `--no-backup` skips one run.
````

6. Add a section before `## Backups`:

````markdown
## Customizing the sheet

Push formats the tab after writing it (turn this off with `TRANSLATION_FORMAT_SHEET=false`) and then fires `PaperleafTech\LaravelTranslation\Events\TranslationsPushed`. Listen for it to add your own tabs, dropdowns or colours:

```php
use Illuminate\Support\Facades\Event;
use PaperleafTech\LaravelTranslation\Events\TranslationsPushed;
use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;

Event::listen(function (TranslationsPushed $event) {
    app(GoogleSheetsService::class)->batchUpdate([
        ['setDataValidation' => [
            'range' => ['sheetId' => $event->sheetId, 'startRowIndex' => 1, 'startColumnIndex' => array_search('status', $event->headers), 'endColumnIndex' => array_search('status', $event->headers) + 1],
            'rule' => ['condition' => ['type' => 'ONE_OF_LIST', 'values' => [['userEnteredValue' => 'draft'], ['userEnteredValue' => 'approved']]], 'showCustomUi' => true],
        ]],
    ]);
});
```

The event carries `sheetName`, `sheetId`, `headers` and the written `rows`. The package's own conditional rules contain `N("laravel-translation")=0` in their formula and are replaced on every push; rules you add are left alone.
````

7. Replace the `## Workflow` section with:

```markdown
## Workflow

1. **Push** — `php artisan translations:push` builds or updates the `Translations` tab from every locale under `lang/`.
2. **Review in the sheet** — editors adjust English in the `en` column; translators fill the locale columns.
3. **Pull** — `php artisan translations:pull` writes non-blank cells back into existing `lang/{locale}/*.php` keys.
4. **Check** — `php artisan translations:check` in CI catches keys or placeholders that drifted.
5. **Re-push** after adding keys in code. Sheet edits and translations are kept.
```

8. Run `grep -n "Translations - \|Original Value\|Updated Value\|English (Source)\|--clear\|--force-initial\|Column C\|Column B" README.md` and rewrite any remaining line so it describes the single tab (only the "From 0.1.x"/"From 0.2.x" upgrade notes may still mention old names).

- [ ] **Step 6: Update the changelog and version**

In `CHANGELOG.md`, replace `## [Unreleased]` with:

```markdown
## [Unreleased]

## [0.5.0] - 2026-10-05

### Breaking Changes
- One `Translations` tab replaces the `Translations - {locale}` tabs. Columns are `key`, `group`, `default`, the source locale, one column per locale, then any columns you add, found by header name.
- `translations:push` takes no locale and always writes every locale. `--clear` and `--force-initial` are replaced by `--fresh`.
- Config: removed `key_column`, `original_value_column`, `updated_value_column` and `header_row`; added `sheet`, `source_locale` and `format`.
- Backups are one JSON file per push in the backup directory instead of one per locale.

### Added
- The sheet's English is editable. `default` holds the English from code as of the last push; when code and the sheet both change a line, push keeps the sheet's text and lists the conflict.
- Columns you add to the tab (`status`, `notes`, …) are kept with their row on every push.
- `translations:push --dry-run`.
- Sheet formatting after each push (frozen header, filter, widths, red empty translations, protected key/group/default), switchable with `TRANSLATION_FORMAT_SHEET`.
- `TranslationsPushed` event for app-specific tabs and formatting.
- `source_locale` config, used by push, pull and `translations:check`.
- `translations:check` treats each JSON key as its own English when there is no `lang/{source}.json`, the way Laravel renders it.
- Laravel 12 and 13 support in the test suite.

### Changed
- `translations:pull` exits with a failure when it rejects a value for mismatched `:placeholders` (the other values are still written).
- A malformed `lang/{locale}.json` now fails with the file's path instead of being read as empty.

### Upgrading
1. On 0.4.0, run `php artisan translations:pull` so code holds the latest translations.
2. Upgrade to 0.5.0 and update a published config to the new keys.
3. Run `php artisan translations:push` to build the `Translations` tab.
4. Delete the old `Translations - {locale}` tabs.
```

Update the link footer: change the `[Unreleased]` line to compare `v0.5.0...HEAD` and add `[0.5.0]: https://github.com/paper-leaf-tech/laravel-translation/compare/v0.4.0...v0.5.0` above `[0.4.0]`.

In `composer.json`, set `"version": "0.5.0"`.

- [ ] **Step 7: Final verification**

Run: `composer test`
Expected: all pass, `PHPUnit Deprecations: 0`.

Run: `grep -rn "TranslationReconciler\|Translations - \|key_column\|header_row" src config tests`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint config tests/Unit/ConfigTest.php
git add config README.md CHANGELOG.md composer.json tests/Unit/ConfigTest.php
git commit -m "Release 0.5.0: single translation sheet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Tagging `v0.5.0` and pushing are not part of this plan; confirm with the user first.
