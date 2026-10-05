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
