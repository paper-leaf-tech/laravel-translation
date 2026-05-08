<?php

namespace PaperleafTech\LaravelTranslation\Services;

use PaperleafTech\LaravelTranslation\Support\TranslationConventions;

class TranslationReconciler
{
    /**
     * Build sheet rows for a locale push.
     *
     * @param  string  $locale  Target locale being pushed
     * @param  array<string,string>  $sourceTranslations  Flat key=>value of source-locale (English) strings
     * @param  array<string,string>  $targetTranslations  Flat key=>value of target-locale strings (locale's own files)
     * @param  array<string,array{original:string,updated:string}>  $existingSheetRows  Existing sheet rows, keyed by translation key. May be empty.
     * @return array{rows: array<int, array{0:string,1:string,2:string}>, stats: array<string,int>}
     */
    public function reconcile(
        string $locale,
        array $sourceTranslations,
        array $targetTranslations,
        array $existingSheetRows,
    ): array {
        $stats = [
            'new' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'stale' => 0,
            'target_only' => 0,
            'untranslated' => 0,
        ];

        if (TranslationConventions::isSourceLocale($locale)) {
            $rows = $this->reconcileSource($sourceTranslations, $existingSheetRows, $stats);
        } else {
            $rows = $this->reconcileTarget($sourceTranslations, $targetTranslations, $existingSheetRows, $stats);
        }

        return ['rows' => $rows, 'stats' => $stats];
    }

    /**
     * Source-locale rows: code value drives both Column B and (when changed) Column C.
     */
    protected function reconcileSource(array $sourceTranslations, array $existingSheetRows, array &$stats): array
    {
        $rows = [];

        foreach ($sourceTranslations as $key => $codeValue) {
            if (isset($existingSheetRows[$key])) {
                $sheetOriginal = $existingSheetRows[$key]['original'];
                $sheetUpdated = $existingSheetRows[$key]['updated'];

                if ($codeValue === $sheetOriginal) {
                    $rows[] = [$key, $sheetOriginal, $sheetUpdated];
                    $stats['unchanged']++;
                } elseif ($codeValue === $sheetUpdated) {
                    // Code matches what an editor previously updated — likely after a pull.
                    $rows[] = [$key, $sheetOriginal, $sheetUpdated];
                    $stats['unchanged']++;
                } else {
                    // Original changed in code; keep sheet's Original as baseline, update Updated to reflect code.
                    $rows[] = [$key, $sheetOriginal, $codeValue];
                    $stats['changed']++;
                }
            } else {
                $rows[] = [$key, $codeValue, ''];
                $stats['new']++;
            }
        }

        $stats['removed'] = count(array_diff(array_keys($existingSheetRows), array_keys($sourceTranslations)));

        return $rows;
    }

    /**
     * Non-source rows: English drives Column B; existing translations in Column C are preserved.
     */
    protected function reconcileTarget(
        array $sourceTranslations,
        array $targetTranslations,
        array $existingSheetRows,
        array &$stats,
    ): array {
        $rows = [];

        $keys = array_keys($sourceTranslations + $targetTranslations);

        foreach ($keys as $key) {
            $sourceVal = $sourceTranslations[$key] ?? null;
            $targetVal = $targetTranslations[$key] ?? null;
            $newB = $sourceVal ?? $targetVal ?? '';

            if (isset($existingSheetRows[$key])) {
                $sheetB = $existingSheetRows[$key]['original'];
                $sheetC = $existingSheetRows[$key]['updated'];

                $rows[] = [$key, $newB, $sheetC];

                if ($newB !== $sheetB) {
                    $stats['stale']++;
                } else {
                    $stats['unchanged']++;
                }

                if ($sourceVal === null) {
                    $stats['target_only']++;
                }
            } else {
                $newC = $targetVal ?? '';
                $rows[] = [$key, $newB, $newC];
                $stats['new']++;

                if ($sourceVal === null) {
                    $stats['target_only']++;
                } elseif ($targetVal === null) {
                    $stats['untranslated']++;
                }
            }
        }

        $stats['removed'] = count(array_diff(array_keys($existingSheetRows), $keys));

        return $rows;
    }
}
