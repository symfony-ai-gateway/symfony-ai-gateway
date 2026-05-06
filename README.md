# PhiGateway

> PHP AI Gateway — Unified LLM proxy with fallback, caching, rate limiting and cost tracking.

A self-hostable AI gateway in PHP that unifies access to all LLM providers behind a single OpenAI-compatible API.

**One request, one format, all models.**

## Install

```bash
composer require phi-gateway/core
```

## Quick Start (Symfony Bundle)

### 1. Configure

```yaml
# config/packages/phi_gateway.yaml
phi_gateway:
    providers:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'

    models:
        gpt-4o:
            provider: openai
            model: gpt-4o
            pricing:
                input: 2.50
                output: 10.00
        gpt-4o-mini:
            provider: openai
            model: gpt-4o-mini
            pricing:
                input: 0.15
                output: 0.60
        claude-sonnet:
            provider: anthropic
            model: claude-sonnet-4-20250514
            pricing:
                input: 3.00
                output: 15.00

    pipelines:
        default:
            models: [gpt-4o, claude-sonnet, gpt-4o-mini]

    aliases:
        smart: gpt-4o
        fast: gpt-4o-mini
        reliable: 'pipeline:default'
```

### 2. Use

```php
use PhiGateway\Core\GatewayInterface;
use PhiGateway\Core\NormalizedRequest;

class ChatService
{
    public function __construct(
        private GatewayInterface $gateway,
    ) {}

    public function ask(string $question): string
    {
        $request = NormalizedRequest::fromArray([
            'model' => 'reliable',      // Uses the pipeline: tries gpt-4o → claude-sonnet → gpt-4o-mini
            'messages' => [
                ['role' => 'user', 'content' => $question],
            ],
            'temperature' => 0.7,
        ]);

        $response = $this->gateway->chat($request);

        return $response->getContent();      // "Docker est un outil..."
        // $response->provider;              // "openai" (the provider that actually responded)
        // $response->usage->totalTokens;   // 380
        // $response->costUsd;              // 0.00285
        // $response->durationMs;           // 1234
    }
}
```

## Supported Providers

| Provider | Status | Auth | Notes |
|----------|--------|------|-------|
| **OpenAI** | ✅ | Bearer token | Reference implementation |
| **Anthropic** | ✅ | x-api-key | System message extracted, max_tokens added |
| *Mistral* | 🔜 | Bearer token | Drop-in OpenAI |
| *Ollama* | 🔜 | None | Local inference |
| *DeepSeek* | 🔜 | Bearer token | Drop-in OpenAI |
| *Groq* | 🔜 | Bearer token | Drop-in OpenAI |
| *Google Gemini* | 🔜 | API key | Full translation needed |

## Features

- **Unified API** — OpenAI-compatible format regardless of provider
- **Fallback pipelines** — Try gpt-4o → claude-sonnet → local model automatically
- **Cost tracking** — Real-time token counting and cost calculation per request
- **Provider adapters** — Clean translation layer for each LLM provider's API format
- **Symfony Bundle** — Native integration with autowiring and YAML configuration
- **Standalone mode** — Run as a standalone proxy server (coming in v0.2)

## Status

**v0.1 (current)** — Core engine + OpenAI/Anthropic adapters + Symfony Bundle

See [CDC.md](../ai-gateway-cdc/CDC.md) for the full product roadmap (77 user stories across 6 phases).

## License

MIT
