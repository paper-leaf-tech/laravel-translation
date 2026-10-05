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
