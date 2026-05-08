<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use Illuminate\Support\Facades\File;
use PaperleafTech\LaravelTranslation\Services\TranslationFileWriter;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class TranslationFileWriterTest extends TestCase
{
    protected TranslationFileWriter $writer;

    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new TranslationFileWriter();
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lt-writer-'.uniqid();
        File::makeDirectory($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tmpDir)) {
            File::deleteDirectory($this->tmpDir);
        }
        parent::tearDown();
    }

    protected function fixture(string $contents): string
    {
        $path = $this->tmpDir.'/auth.php';
        File::put($path, $contents);

        return $path;
    }

    /** @test */
    public function it_preserves_inline_comments(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    // The login error
    'failed' => 'These credentials do not match our records.', // legacy copy
    'throttle' => 'Too many login attempts.',
];
PHP);

        $this->writer->updateFile($path, ['failed' => 'Invalid credentials.']);

        $contents = File::get($path);
        $this->assertStringContainsString('// The login error', $contents);
        $this->assertStringContainsString('// legacy copy', $contents);
        $this->assertStringContainsString("'failed' => 'Invalid credentials.'", $contents);
    }

    /** @test */
    public function it_preserves_block_and_doc_comments(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

/**
 * Auth strings.
 */
return [
    /* Block comment */
    'failed' => 'X',
];
PHP);

        $this->writer->updateFile($path, ['failed' => 'Y']);

        $contents = File::get($path);
        $this->assertStringContainsString('Auth strings.', $contents);
        $this->assertStringContainsString('/* Block comment */', $contents);
        $this->assertStringContainsString("'failed' => 'Y'", $contents);
    }

    /** @test */
    public function it_preserves_blank_lines(): void
    {
        $original = <<<'PHP'
<?php

return [
    'failed' => 'X',

    'throttle' => 'Y',


    'logout' => 'Z',
];
PHP;
        $path = $this->fixture($original);

        $this->writer->updateFile($path, ['failed' => 'A', 'logout' => 'C']);

        $contents = File::get($path);
        // Two blank lines before logout should still be there.
        $this->assertStringContainsString("'throttle' => 'Y',\n\n\n    'logout'", $contents);
    }

    /** @test */
    public function it_preserves_two_space_indentation(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
  'failed' => 'X',
  'throttle' => 'Y',
];
PHP);

        $this->writer->updateFile($path, ['failed' => 'A']);

        $contents = File::get($path);
        $this->assertStringContainsString("  'failed' => 'A'", $contents);
        $this->assertStringContainsString("  'throttle' => 'Y'", $contents);
    }

    /** @test */
    public function it_preserves_single_quoted_strings(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'failed' => 'old',
];
PHP);

        $this->writer->updateFile($path, ['failed' => 'new']);

        $this->assertStringContainsString("'failed' => 'new'", File::get($path));
    }

    /** @test */
    public function it_preserves_double_quoted_strings(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    "greeting" => "hello",
];
PHP);

        $this->writer->updateFile($path, ['greeting' => 'It\'s a new day']);

        $contents = File::get($path);
        // Apostrophe stays unescaped inside double quotes.
        $this->assertStringContainsString('"greeting" => "It\'s a new day"', $contents);
    }

    /** @test */
    public function it_escapes_apostrophe_when_target_is_single_quoted(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'failed' => 'old',
];
PHP);

        $this->writer->updateFile($path, ['failed' => "It's broken"]);

        $contents = File::get($path);
        $this->assertStringContainsString("'failed' => 'It\\'s broken'", $contents);
    }

    /** @test */
    public function it_handles_nested_arrays(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'email' => [
        'format' => 'old format',
        'domain' => 'old domain',
    ],
];
PHP);

        $stats = $this->writer->updateFile($path, [
            'email.format' => 'new format',
        ]);

        $this->assertSame(['email.format'], $stats['updated']);

        $contents = File::get($path);
        $this->assertStringContainsString("'format' => 'new format'", $contents);
        $this->assertStringContainsString("'domain' => 'old domain'", $contents);
    }

    /** @test */
    public function it_does_not_modify_file_when_no_updates_apply(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'failed' => 'X',
];
PHP);

        $originalMtime = filemtime($path);
        clearstatcache();

        // Wait long enough that an inadvertent rewrite would change mtime.
        sleep(1);

        $stats = $this->writer->updateFile($path, ['nonexistent' => 'Y']);

        clearstatcache();
        $this->assertSame($originalMtime, filemtime($path));
        $this->assertSame(['nonexistent'], $stats['skipped_missing']);
        $this->assertEmpty($stats['updated']);
    }

    /** @test */
    public function it_skips_keys_with_non_string_values(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'count' => 5,
    'concat' => 'a' . 'b',
    'callable' => fn () => 'x',
    'failed' => 'X',
];
PHP);

        $stats = $this->writer->updateFile($path, [
            'count' => 'six',
            'concat' => 'c',
            'callable' => 'y',
            'failed' => 'Y',
        ]);

        $this->assertSame(['failed'], $stats['updated']);
        $this->assertEqualsCanonicalizing(
            ['count', 'concat', 'callable'],
            $stats['skipped_non_string']
        );
        $this->assertEmpty($stats['skipped_missing']);
    }

    /** @test */
    public function it_reports_truly_absent_keys_as_skipped_missing(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'failed' => 'X',
];
PHP);

        $stats = $this->writer->updateFile($path, [
            'failed' => 'Y',
            'new_key' => 'Z',
        ]);

        $this->assertSame(['failed'], $stats['updated']);
        $this->assertSame(['new_key'], $stats['skipped_missing']);
    }

    /** @test */
    public function it_returns_skipped_missing_for_all_updates_when_file_does_not_exist(): void
    {
        $stats = $this->writer->updateFile($this->tmpDir.'/missing.php', [
            'a' => 'A',
            'b' => 'B',
        ]);

        $this->assertEqualsCanonicalizing(['a', 'b'], $stats['skipped_missing']);
        $this->assertEmpty($stats['updated']);
    }

    /** @test */
    public function it_skips_when_file_does_not_return_an_array(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

$x = 1;
PHP);

        $stats = $this->writer->updateFile($path, ['failed' => 'Y']);

        $this->assertEqualsCanonicalizing(['failed'], $stats['skipped_missing']);
        $this->assertEmpty($stats['updated']);
    }

    /** @test */
    public function it_reports_non_string_when_descending_into_non_array_parent(): void
    {
        $path = $this->fixture(<<<'PHP'
<?php

return [
    'count' => 5,
];
PHP);

        $stats = $this->writer->updateFile($path, ['count.sub' => 'X']);

        $this->assertSame(['count.sub'], $stats['skipped_non_string']);
    }
}
