<?php

namespace PaperleafTech\LaravelTranslation\Tests\Unit;

use PaperleafTech\LaravelTranslation\Support\Placeholders;
use PaperleafTech\LaravelTranslation\Tests\TestCase;

class PlaceholdersTest extends TestCase
{
    /** @test */
    public function it_finds_each_placeholder_once_lower_cased_and_sorted(): void
    {
        $this->assertSame(['name', 'role'], Placeholders::in(':Role was given to :name; :role is now active.'));
    }

    /** @test */
    public function it_ignores_colons_that_are_not_placeholders(): void
    {
        $this->assertSame([], Placeholders::in('Opens at 10:30.'));
        $this->assertSame([], Placeholders::in('See https://example.com for details.'));
        $this->assertSame([], Placeholders::in("Statut\u{00A0}: actif"));
        $this->assertSame([], Placeholders::in('Use the ratio a:b here.'));
    }

    /** @test */
    public function it_finds_a_placeholder_that_follows_another_colon(): void
    {
        $this->assertSame(['email'], Placeholders::in('Write to mailto::email'));
    }

    /** @test */
    public function it_matches_lines_whose_placeholders_differ_only_in_case(): void
    {
        $this->assertTrue(Placeholders::match('You are now :role.', ':Role vous a été attribué.'));
        $this->assertFalse(Placeholders::match(':name holds :role.', ':name occupe ce rôle.'));
    }
}
