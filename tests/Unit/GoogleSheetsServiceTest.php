<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Services\GoogleSheetsService;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class GoogleSheetsServiceTest extends TestCase
{
    protected GoogleSheetsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GoogleSheetsService();
    }

    /** @test */
    public function it_can_get_service_account_email(): void
    {
        $email = $this->service->getServiceAccountEmail();

        $this->assertNotNull($email);
        $this->assertEquals('test@test-project.iam.gserviceaccount.com', $email);
    }

    /** @test */
    public function it_can_generate_spreadsheet_url(): void
    {
        $url = $this->service->getSpreadsheetUrl();

        $this->assertEquals('https://docs.google.com/spreadsheets/d/test-spreadsheet-id/edit', $url);
    }

    /** @test */
    public function it_validates_configuration_on_initialization(): void
    {
        config()->set('laravel-translation.spreadsheet_id', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Sheets spreadsheet ID is not configured');

        $this->service->getSpreadsheetUrl();
    }

    /** @test */
    public function it_validates_credentials_file_exists(): void
    {
        config()->set('laravel-translation.credentials_path', '/non/existent/path.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google Sheets credentials file not found');

        $this->service->getSpreadsheetUrl();
    }

    /** @test */
    public function it_validates_credentials_json_format(): void
    {
        $invalidJsonPath = __DIR__.'/../fixtures/invalid.json';
        file_put_contents($invalidJsonPath, 'not valid json');
        config()->set('laravel-translation.credentials_path', $invalidJsonPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contains invalid JSON');

        try {
            $this->service->getSpreadsheetUrl();
        } finally {
            unlink($invalidJsonPath);
        }
    }

    /** @test */
    public function it_validates_service_account_type(): void
    {
        $wrongTypePath = __DIR__.'/../fixtures/wrong-type.json';
        file_put_contents($wrongTypePath, json_encode(['type' => 'not_service_account']));
        config()->set('laravel-translation.credentials_path', $wrongTypePath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a valid service account');

        try {
            $this->service->getSpreadsheetUrl();
        } finally {
            unlink($wrongTypePath);
        }
    }

    /** @test */
    public function it_throws_exception_for_invalid_keep_count_in_prune_backups(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Keep count must be a positive integer');

        $this->service->pruneBackups(-1);
    }
}
