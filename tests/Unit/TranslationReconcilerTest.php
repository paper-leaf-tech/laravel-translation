<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Services\TranslationReconciler;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class TranslationReconcilerTest extends TestCase
{
    protected TranslationReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reconciler = new TranslationReconciler();
    }

    // ===== Source locale =====

    /** @test */
    public function source_initial_push_creates_rows_with_empty_updated_column(): void
    {
        $result = $this->reconciler->reconcile(
            'en',
            ['auth.failed' => 'Failed', 'auth.throttle' => 'Throttled'],
            ['auth.failed' => 'Failed', 'auth.throttle' => 'Throttled'],
            [],
        );

        $this->assertSame([
            ['auth.failed', 'Failed', ''],
            ['auth.throttle', 'Throttled', ''],
        ], $result['rows']);
        $this->assertSame(2, $result['stats']['new']);
    }

    /** @test */
    public function source_unchanged_keys_preserve_updated_value(): void
    {
        $result = $this->reconciler->reconcile(
            'en',
            ['auth.failed' => 'Failed'],
            ['auth.failed' => 'Failed'],
            ['auth.failed' => ['original' => 'Failed', 'updated' => 'Edited']],
        );

        $this->assertSame([
            ['auth.failed', 'Failed', 'Edited'],
        ], $result['rows']);
        $this->assertSame(1, $result['stats']['unchanged']);
    }

    /** @test */
    public function source_changed_in_code_updates_updated_column(): void
    {
        $result = $this->reconciler->reconcile(
            'en',
            ['auth.failed' => 'New text'],
            ['auth.failed' => 'New text'],
            ['auth.failed' => ['original' => 'Old text', 'updated' => 'Editor text']],
        );

        $this->assertSame([
            ['auth.failed', 'Old text', 'New text'],
        ], $result['rows']);
        $this->assertSame(1, $result['stats']['changed']);
    }

    /** @test */
    public function source_removed_keys_are_dropped_and_counted(): void
    {
        $result = $this->reconciler->reconcile(
            'en',
            ['auth.failed' => 'Failed'],
            ['auth.failed' => 'Failed'],
            [
                'auth.failed' => ['original' => 'Failed', 'updated' => ''],
                'auth.gone' => ['original' => 'Gone', 'updated' => ''],
            ],
        );

        $this->assertCount(1, $result['rows']);
        $this->assertSame('auth.failed', $result['rows'][0][0]);
        $this->assertSame(1, $result['stats']['removed']);
    }

    // ===== Non-source locale =====

    /** @test */
    public function non_source_initial_push_uses_english_in_b_and_target_in_c(): void
    {
        $result = $this->reconciler->reconcile(
            'fr',
            ['auth.failed' => 'Failed', 'auth.throttle' => 'Throttled'],
            ['auth.failed' => 'Échec'],
            [],
        );

        $this->assertSame([
            ['auth.failed', 'Failed', 'Échec'],
            ['auth.throttle', 'Throttled', ''],
        ], $result['rows']);
        $this->assertSame(2, $result['stats']['new']);
        $this->assertSame(1, $result['stats']['untranslated']);
    }

    /** @test */
    public function non_source_existing_translation_preserved_when_english_unchanged(): void
    {
        $result = $this->reconciler->reconcile(
            'fr',
            ['auth.failed' => 'Failed'],
            ['auth.failed' => 'unused-on-diff-push'],
            ['auth.failed' => ['original' => 'Failed', 'updated' => 'Échec']],
        );

        $this->assertSame([['auth.failed', 'Failed', 'Échec']], $result['rows']);
        $this->assertSame(1, $result['stats']['unchanged']);
        $this->assertSame(0, $result['stats']['stale']);
    }

    /** @test */
    public function non_source_english_change_marks_row_stale_but_preserves_translation(): void
    {
        $result = $this->reconciler->reconcile(
            'fr',
            ['auth.failed' => 'New English'],
            [],
            ['auth.failed' => ['original' => 'Old English', 'updated' => 'Existing FR']],
        );

        $this->assertSame([['auth.failed', 'New English', 'Existing FR']], $result['rows']);
        $this->assertSame(1, $result['stats']['stale']);
    }

    /** @test */
    public function non_source_target_only_key_is_pushed_with_target_in_b(): void
    {
        $result = $this->reconciler->reconcile(
            'fr',
            [],
            ['extra.key' => 'Bonjour'],
            [],
        );

        $this->assertSame([['extra.key', 'Bonjour', 'Bonjour']], $result['rows']);
        $this->assertSame(1, $result['stats']['target_only']);
    }

    /** @test */
    public function non_source_new_english_key_with_no_translation_is_untranslated(): void
    {
        $result = $this->reconciler->reconcile(
            'fr',
            ['new.key' => 'Hello'],
            [],
            [],
        );

        $this->assertSame([['new.key', 'Hello', '']], $result['rows']);
        $this->assertSame(1, $result['stats']['untranslated']);
    }

    /** @test */
    public function non_source_does_not_overwrite_existing_translation_with_local_target_value(): void
    {
        $result = $this->reconciler->reconcile(
            'fr',
            ['auth.failed' => 'Failed'],
            ['auth.failed' => 'Local seed should be ignored'],
            ['auth.failed' => ['original' => 'Failed', 'updated' => 'Sheet translation']],
        );

        $this->assertSame([['auth.failed', 'Failed', 'Sheet translation']], $result['rows']);
    }
}
