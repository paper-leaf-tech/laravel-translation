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
