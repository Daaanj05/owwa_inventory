<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OllamaTestCommand extends Command
{
    protected $signature = 'ollama:test';

    protected $description = 'Test connection to Ollama and show the actual error if it fails.';

    public function handle(): int
    {
        $baseUrl = rtrim(config('services.ollama.url', 'http://localhost:11434'), '/');
        $baseUrl = preg_replace('#^https?://localhost(?=:\d+|/|$)#i', 'http://127.0.0.1', $baseUrl);
        $chatModel = config('services.ollama.chat_model', 'deepseek-r1:7b');

        $this->info("Testing Ollama at: {$baseUrl}");
        $this->info("Chat model: {$chatModel}");
        $this->newLine();

        // 1. Try simple GET (some Ollama versions respond on root)
        $this->info('1. GET ' . $baseUrl . ' ...');
        try {
            $r = Http::connectTimeout(5)->timeout(10)->get($baseUrl);
            if ($r->successful()) {
                $this->info('   OK (status ' . $r->status() . ')');
            } else {
                $this->warn('   Response: ' . $r->status() . ' ' . $r->body());
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('   Connection failed: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Make sure Ollama is running (taskbar icon) and nothing is blocking PHP from reaching ' . $baseUrl);
            $this->warn('On Windows, try OLLAMA_URL=http://127.0.0.1:11434 in .env and run: php artisan config:clear');
            return self::FAILURE;
        }

        // 2. Try /api/tags (what isAvailable() uses)
        $this->info('2. GET ' . $baseUrl . '/api/tags ...');
        try {
            $r = Http::connectTimeout(5)->timeout(10)->get($baseUrl . '/api/tags');
            if ($r->successful()) {
                $data = $r->json();
                $models = $data['models'] ?? [];
                $names = array_column($models, 'name');
                $this->info('   OK. Models: ' . (count($names) ? implode(', ', $names) : 'none'));
                if ($chatModel && ! in_array($chatModel, $names, true)) {
                    $this->warn("   Your OLLAMA_CHAT_MODEL ({$chatModel}) is not in the list. Run: ollama pull {$chatModel}");
                }
            } else {
                $this->error('   Failed: ' . $r->status() . ' ' . substr($r->body(), 0, 200));
            }
        } catch (\Throwable $e) {
            $this->error('   Error: ' . $e->getMessage());
        }

        $this->newLine();
        $this->info('If both requests succeeded, "Generate recommendation" in the app should work.');
        return self::SUCCESS;
    }
}
