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
