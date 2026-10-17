<?php

namespace Tests\Feature;

use Tests\TestCase;

class SearchEngineIndexingTest extends TestCase
{
    public function test_responses_send_noindex_header(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_robots_txt_disallows_crawling(): void
    {
        $contents = file_get_contents(public_path('robots.txt'));

        $this->assertIsString($contents);
        $this->assertStringContainsString('User-agent: *', $contents);
        $this->assertMatchesRegularExpression('/^Disallow:\s*\/\s*$/m', $contents);
    }
}
