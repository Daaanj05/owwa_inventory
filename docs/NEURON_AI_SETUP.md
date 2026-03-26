# Neuron AI in OWWA Inventory

This project can use **[Neuron AI](https://github.com/neuron-core/neuron-ai)** (PHP agent framework) for procurement recommendations, with **Ollama** (e.g. DeepSeek) as the LLM provider.

## Install the package

From your project root:

```bash
composer update
```

Or install only Neuron AI:

```bash
composer require neuron-core/neuron-ai
```

This installs `neuron-core/neuron-ai` (already added to `composer.json`).

## What’s in the project

- **`app/Neuron/ProcurementAgent.php`**  
  Neuron agent that uses Ollama (DeepSeek) and is configured from `config/services.php` (`ollama.url`, `ollama.chat_model`). It has a `recommend(context, query)` method for one-off procurement suggestions.

- **`app/Services/RagService.php`**  
  Uses `ProcurementAgent` when the Neuron AI package is present; otherwise it falls back to the direct `OllamaClient`. No change needed in the Filament page or UI.

## Configuration

Neuron’s procurement agent reads Laravel config:

- **`config/services.php`** → `ollama.url`, `ollama.chat_model`
- **`.env`**:
  - `OLLAMA_URL` (e.g. `http://localhost:11434`)
  - `OLLAMA_CHAT_MODEL` (e.g. `deepseek-r1:7b`)

Ollama must be running and the chat model must be pulled (e.g. `ollama pull deepseek-r1:7b`).

## Flow

1. User opens **AI Procurement Recommendations** in the admin panel.
2. The app builds inventory context (stock, issuances, reorder levels) and sends it plus the query to the LLM.
3. If Neuron AI is installed, `RagService::getRecommendation()` uses `ProcurementAgent::make()->recommend($context, $query)`.
4. If not, it uses the existing `OllamaClient` to call Ollama directly.

## Extending with Neuron

You can add more agents (e.g. for reports or other analytics) under `app/Neuron/` by extending `NeuronAI\Agent`, implementing `provider()` (e.g. with `NeuronAI\Providers\Ollama\Ollama`), and calling them from your services or controllers. See [Neuron AI docs](https://docs.neuron-ai.dev/) and the [Ollama provider](https://docs.neuron-ai.dev/components/ai-provider#ollama) for details.
