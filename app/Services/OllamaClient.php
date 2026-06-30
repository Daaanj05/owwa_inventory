<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaClient
{
    protected string $baseUrl;

    protected string $embedModel;

    protected string $chatModel;

    public function __construct()
    {
        $url = rtrim(config('services.ollama.url', 'http://localhost:11434'), '/');
        // Use 127.0.0.1 instead of localhost so PHP/HTTP can connect reliably on Windows
        $this->baseUrl = preg_replace('#^https?://localhost(?=:\d+|/|$)#i', 'http://127.0.0.1', $url);
        $this->embedModel = config('services.ollama.embed_model', 'nomic-embed-text');
        $this->chatModel = config('services.ollama.chat_model', 'deepseek-r1:7b');
    }

    /**
     * Get embedding vector for text via Ollama.
     *
     * @return array<float>|null
     */
    public function embed(string $text): ?array
    {
        try {
            $response = Http::timeout(60)->post("{$this->baseUrl}/api/embeddings", [
                'model' => $this->embedModel,
                'prompt' => $text,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return $data['embedding'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send a chat request and return the assistant message content.
     */
    public function chat(string $systemPrompt, string $userMessage): ?string
    {
        try {
            $response = Http::timeout(120)->post("{$this->baseUrl}/api/chat", [
                'model' => $this->chatModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'stream' => false,
            ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            return $data['message']['content'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = Http::timeout(10)->connectTimeout(5)->get("{$this->baseUrl}/api/tags");
            if ($response->successful()) {
                return true;
            }

            $fallback = Http::timeout(5)->connectTimeout(3)->get($this->baseUrl);

            return $fallback->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
