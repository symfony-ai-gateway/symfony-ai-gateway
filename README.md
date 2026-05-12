# AIGateway

> PHP AI Gateway — Unified LLM proxy with multi-provider fallback, caching, rate limiting, cost tracking, and per-key auth.

A self-hostable AI gateway in PHP/Symfony that unifies access to all LLM providers behind a single **OpenAI-compatible API**. Built on top of the official **Symfony AI** library.

**One endpoint, one format, all models.**

## Features

- **OpenAI-compatible API** — Drop-in replacement for any OpenAI client
- **Multi-provider** — OpenAI, Anthropic, Gemini, Ollama, Azure, and any OpenAI-compatible endpoint
- **Symfony AI powered** — Uses the official `symfony/ai-platform` bridges
- **Fallback pipelines** — Try gpt-4o → claude-sonnet → deepseek automatically
- **Per-key auth** — Hierarchical teams with restrictive rule inheritance
- **Budget enforcement** — Daily/monthly per-key budget limits (USD)
- **Rate limiting** — Global, per-model, and per-key sliding window
- **Response caching** — SHA-256 deterministic cache (in-memory or file)
- **Cost tracking** — Real-time token counting and cost per request
- **Streaming** — SSE streaming for chat completions
- **Observability** — Prometheus metrics, request logging, cost reports
- **Web dashboard** — Dark-themed UI with Chart.js analytics
- **CLI** — Key/team management, stats, model listing
- **Docker** — Multi-stage build, non-root, healthcheck
- **Bundle + Standalone** — Use as a Symfony bundle or standalone server

## Quick Start

### Docker (recommended)

```bash
# Clone
git clone https://github.com/symfony-ai-gateway/symfony-ai-gateway.git
cd symfony-ai-gateway

# Configure
cp .env.example .env
# Edit .env with your API keys

# Run
docker compose up -d

# Test
curl http://localhost:8080/v1/health
```

### Symfony Bundle

```bash
composer require ai-gateway/core
```

```yaml
# config/packages/ai_gateway.yaml
ai_gateway:
    providers:
        openai:
            format: openai
            api_key: '%env(OPENAI_API_KEY)%'
        anthropic:
            format: anthropic
            api_key: '%env(ANTHROPIC_API_KEY)%'
        ollama:
            format: ollama
            base_url: 'http://localhost:11434'

    models:
        gpt-4o:
            provider: openai
            model: gpt-4o
            pricing: { input: 2.50, output: 10.00 }
        claude-sonnet:
            provider: anthropic
            model: claude-sonnet-4-20250514
            pricing: { input: 3.00, output: 15.00 }
        local-llama:
            provider: ollama
            model: llama3
            pricing: { input: 0.0, output: 0.0 }

    pipelines:
        default:
            models: [gpt-4o, claude-sonnet, local-llama]

    aliases:
        smart: gpt-4o
        reliable: 'pipeline:default'

    auth:
        enabled: true
        required: true
```

### Chat Completion

```bash
curl http://localhost:8080/v1/chat/completions \
  -H "Authorization: Bearer aig_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}]
  }'
```

### Streaming

```bash
curl http://localhost:8080/v1/chat/completions \
  -H "Authorization: Bearer aig_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": true
  }'
```

## Auth & API Keys

### CLI — Create a Team

```bash
php bin/ai-gateway team:create \
    --name "Engineering" \
    --budget-per-day 100 \
    --budget-per-month 2000 \
    --rate-limit 60 \
    --models "gpt-4o,claude-sonnet"
```

### CLI — Create an API Key

```bash
php bin/ai-gateway key:create \
    --name "Frontend App" \
    --team <team-id> \
    --budget-per-day 20 \
    --models "gpt-4o"
```

### Hierarchy Rules

- A **team** defines maximum limits (budget, rate limit, models whitelist)
- A **key** inherits its team's rules and can only **restrict** them further
- Keys without a team are unrestricted (unless global limits apply)

```
Team "Engineering" ($100/day, gpt-4o + claude-sonnet)
  └── Key "Mathieu" ($20/day, gpt-4o only)
  └── Key "CI Bot" ($5/day, claude-sonnet only)
```

### CLI Commands

| Command | Description |
|---------|-------------|
| `key:create` | Create an API key with optional overrides |
| `key:list` | List all API keys |
| `key:info <id>` | Show key details + usage |
| `key:revoke <id>` | Disable an API key |
| `team:create` | Create a team with rules |
| `team:list` | List all teams |
| `team:info <id>` | Show team details + keys |
| `stats` | Show gateway usage statistics |
| `serve` | Start the standalone server |

## Supported Providers

| Provider | Format | Auth |
|----------|--------|------|
| **OpenAI** | `openai` | Bearer token |
| **Anthropic** | `anthropic` | x-api-key |
| **Google Gemini** | `gemini` | API key |
| **Ollama** | `ollama` | None |
| **Azure OpenAI** | `azure` | API key |
| **Any OpenAI-compatible** | `openai` | Bearer token |

### Custom Provider Example (DeepSeek, Groq, OpenRouter, Mistral)

```yaml
ai_gateway:
    providers:
        deepseek:
            format: openai
            api_key: '%env(DEEPSEEK_API_KEY)%'
            base_url: 'https://api.deepseek.com'
        groq:
            format: openai
            api_key: '%env(GROQ_API_KEY)%'
            base_url: 'https://api.groq.com/openai/v1'
```

Any provider with an OpenAI-compatible API works by setting `format: openai` and `base_url`.

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/v1/chat/completions` | Chat completion (supports streaming) |
| `GET` | `/v1/models` | List available models |
| `GET` | `/v1/health` | Health check |
| `GET` | `/v1/metrics` | Prometheus metrics |
| `GET` | `/v1/stats` | JSON usage statistics |
| `GET` | `/dashboard` | Web dashboard |
| `GET` | `/dashboard/keys` | API keys management |
| `GET` | `/dashboard/teams` | Teams management |
| `GET` | `/dashboard/analytics` | Charts and analytics |

Full API reference: [docs/openapi.yaml](docs/openapi.yaml)

## Architecture

```
Request
  → Bearer Auth (SHA-256 hash → key lookup → team ancestry → merged rules)
  → Model Whitelist (403 if not allowed)
  → Budget Check (daily/monthly, 429 if exceeded)
  → Per-Key Rate Limit (sliding window, 429 if exceeded)
  → Global Rate Limit
  → Cache Lookup (SHA-256 deterministic)
  → Provider (Symfony AI Platform)
  → Cache Store
  → Budget Increment
  → Cost Tracking
  → Per-Key Usage Tracking
  → Prometheus Metrics
  → Response
```

## Configuration Reference

```yaml
ai_gateway:
    default_model: null

    providers:
        <name>:
            format: openai|anthropic|gemini|ollama|azure
            api_key: string
            base_url: string|null
            streaming: bool (default: true)
            vision: bool (default: false)
            function_calling: bool (default: true)
            max_tokens_per_request: int (default: 128000)

    models:
        <alias>:
            provider: string (required)
            model: string (required)
            pricing: { input: float, output: float }
            max_tokens: int (default: 128000)

    pipelines:
        <name>:
            models: [model1, model2, ...]

    aliases:
        <alias>: <model-alias>
        <alias>: 'pipeline:<name>'

    retry:
        max_attempts: int (default: 2)
        delay_ms: int (default: 1000)
        backoff: fixed|exponential (default: exponential)

    auth:
        enabled: bool (default: false)
        required: bool (default: true)
```

## Requirements

- PHP >= 8.2
- ext-curl, ext-json, ext-mbstring
- Symfony ^7.0 (or standalone)
- Doctrine DBAL ^4.4 (for auth storage)
- SQLite or any DBAL-supported database

## License

MIT
