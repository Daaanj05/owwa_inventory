<?php

declare(strict_types=1);

namespace App\Neuron;

use NeuronAI\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Ollama\Ollama;

class ProcurementAgent extends Agent
{
    public function provider(): AIProviderInterface
    {
        $url = rtrim(config('services.ollama.url', 'http://localhost:11434'), '/');
        // Use 127.0.0.1 so PHP can connect to Ollama reliably on Windows
        $url = preg_replace('#^https?://localhost(?=:\d+|/|$)#i', 'http://127.0.0.1', $url) . '/api';
        $model = config('services.ollama.chat_model', 'deepseek-r1:7b');

        return new Ollama(
            url: $url,
            model: $model,
        );
    }

    public function instructions(): string
    {
        return 'You are a procurement advisor for OWWA Regional Office IV-A. Use only the provided inventory and stock data. Give short, evidence-based reorder recommendations in the format requested by the user message. Do not invent data or additional items that are not supported by the context.';
    }

    /**
     * Get a one-off recommendation given context (no conversation history).
     */
    public function recommend(string $context, string $query = null): ?string
    {
        $query ??= 'Based on the inventory data, follow the instructions in the question to first summarize the situation and then output a markdown table of at-risk items as specified.';

        $message = "Context:\n" . $context . "\n\nQuestion: " . $query;

        try {
            $response = $this->chat(new UserMessage($message));

            return $response?->getContent();
        } catch (\Throwable) {
            return null;
        }
    }
}
