# AIGateway Bundle

> Symfony bundle that turns any project into an AI gateway — unified LLM access, per-key auth, budget enforcement, cost tracking, and a dashboard.

[![Packagist](https://img.shields.io/packagist/v/ai-gateway/ai-gateway-bundle.svg)](https://packagist.org/packages/ai-gateway/ai-gateway-bundle)

## Why?

Every project that calls LLMs reinvents the same plumbing: provider SDKs, API key management, retry logic, cost tracking, budget limits. AIGateway bundles all of this into one Symfony package powered by the **official Symfony AI** library.

**One `composer require`, one config file, all models.**

## What it does

- **Unified API** — Expose `/v1/chat/completions`, `/v1/models`, `/v1/health` in your Symfony app
- **Multi-provider** — OpenAI, Anthropic, Gemini, Ollama, Azure, any OpenAI-compatible endpoint
- **Per-key auth** — Hierarchical teams with restrictive rule inheritance
- **Budget enforcement** — Daily/monthly per-key budget limits in USD
- **Rate limiting** — Sliding window per key
- **Fallback pipelines** — Try model A → model B → model C automatically
- **Response caching** — Deterministic SHA-256 cache
- **Cost tracking** — Token counting and cost per request
- **Streaming SSE** — Server-sent events for chat completions
- **Prometheus metrics** — `ai_gateway_requests_total`, `ai_gateway_cost_dollars_total`, etc.
- **Web dashboard** — Dark-themed UI at `/dashboard` with Chart.js analytics
- **Route prefix** — Optional `routes.prefix` to mount under `/ai-gateway/` or any prefix

## Install

```bash
composer require ai-gateway/ai-gateway-bundle
```

Symfony Flex auto-registers the bundle. If not:

```php
// config/bundles.php
return [
    AIGateway\Bundle\AIGatewayBundle::class => ['all' => true],
];
```

## Configure

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
        # Any OpenAI-compatible provider
        deepseek:
            format: openai
            api_key: '%env(DEEPSEEK_API_KEY)%'
            base_url: 'https://api.deepseek.com'

    models:
        gpt_4o:
            provider: openai
            model: gpt-4o
            pricing: { input: 2.50, output: 10.00 }
        claude_sonnet:
            provider: anthropic
            model: claude-sonnet-4-20250514
            pricing: { input: 3.00, output: 15.00 }
        local_llama:
            provider: ollama
            model: llama3
            pricing: { input: 0.0, output: 0.0 }
        deepseek_chat:
            provider: deepseek
            model: deepseek-chat
            pricing: { input: 0.27, output: 1.10 }

    aliases:
        smart: gpt_4o
        fast: deepseek_chat

    auth:
        enabled: false
```

## Load routes

Add to your `config/routes.yaml`:

```yaml
ai_gateway:
    resource: .
    type: ai_gateway
```

All routes are now available: `/v1/chat/completions`, `/v1/models`, `/v1/health`, `/dashboard`, etc.

### With a prefix

```yaml
ai_gateway:
    providers: { ... }
    routes:
        prefix: /ai-gateway
```

Routes become `/ai-gateway/v1/chat/completions`, `/ai-gateway/dashboard`, etc.

## Use in your code

Inject `GatewayInterface` wherever you need AI:

```php
use AIGateway\Core\GatewayInterface;
use AIGateway\Core\NormalizedRequest;

final class MyService
{
    public function __construct(
        private readonly GatewayInterface $gateway,
    ) {}

    public function ask(string $prompt): string
    {
        $request = new NormalizedRequest(
            model: 'smart',
            messages: [['role' => 'user', 'content' => $prompt]],
        );

        $response = $this->gateway->chat($request);

        return $response->content;
    }
}
```

You control the routes, auth, and middleware. AIGateway handles the provider communication via Symfony AI.

## Dashboard Protection

The dashboard (`/dashboard/*`) exposes sensitive data: API keys, teams, budgets, usage stats. You have **three options** to protect it:

### Option 1: Symfony Firewall (recommended for existing projects)

If your project already has authentication, put the dashboard behind your firewall:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        dashboard:
            pattern: ^/dashboard
            # your existing auth config...
```

This is the standard Symfony approach — the dashboard inherits your app's login system, roles, etc.

### Option 2: Dashboard Token (built-in)

For projects without a full auth system, enable the built-in token protection:

```yaml
# config/packages/ai_gateway.yaml
ai_gateway:
    dashboard:
        auth:
            enabled: true
            token: '%env(DASHBOARD_TOKEN)%'
```

```dotenv
# .env.local
DASHBOARD_TOKEN=your-secret-token-here
```

When enabled, accessing `/dashboard` without a valid token shows a login form. Once authenticated, the token is passed in all links (`?token=...`).

API routes (`/v1/*`) are **not** affected — only the dashboard UI.

### Option 3: Both

You can combine both: the firewall handles the main auth, and the dashboard token adds an extra layer. Or use the firewall for the main app and the token for a separate standalone deployment.

### Disable the dashboard entirely

```yaml
ai_gateway:
    dashboard:
        enabled: false
```

## Auth & Teams

Enable API key auth to require authentication for all `/v1/*` requests:

```yaml
ai_gateway:
    auth:
        enabled: true
        required: true
```

### CLI — Create a Team

```bash
php bin/console ai-gateway:team:create \
    --name "Engineering" \
    --budget-per-day 100 \
    --models "gpt_4o,claude_sonnet"
```

### CLI — Create an API Key

```bash
php bin/console ai-gateway:key:create \
    --name "Frontend App" \
    --team <team-id> \
    --budget-per-day 20 \
    --models "gpt_4o"
```

### Hierarchy

A **team** defines maximum limits. A **key** inherits team rules and can only restrict further:

```
Team "Engineering" ($100/day, gpt_4o + claude_sonnet)
  └── Key "Mathieu" ($20/day, gpt_4o only)
  └── Key "CI Bot" ($5/day, claude_sonnet only, 10 req/min)
```

## Supported Providers

| Provider | Format | Notes |
|----------|--------|-------|
| **OpenAI** | `openai` | Native Symfony AI bridge |
| **Anthropic** | `anthropic` | Claude models |
| **Google Gemini** | `gemini` | Gemini Pro, Flash |
| **Ollama** | `ollama` | Local models |
| **Azure OpenAI** | `azure` | Enterprise Azure |
| **Any OpenAI-compatible** | `openai` + `base_url` | DeepSeek, Groq, OpenRouter, Mistral... |

### Custom Provider Example

```yaml
ai_gateway:
    providers:
        groq:
            format: openai
            api_key: '%env(GROQ_API_KEY)%'
            base_url: 'https://api.groq.com/openai/v1'
        openrouter:
            format: openai
            api_key: '%env(OPENROUTER_API_KEY)%'
            base_url: 'https://openrouter.ai/api/v1'
```

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/v1/chat/completions` | Chat completion (supports `stream: true`) |
| `GET` | `/v1/models` | List available models |
| `GET` | `/v1/health` | Health check |
| `GET` | `/v1/metrics` | Prometheus metrics |
| `GET` | `/v1/stats` | JSON usage statistics |
| `GET` | `/dashboard` | Web dashboard |
| `GET` | `/dashboard/keys` | API key management |
| `GET` | `/dashboard/teams` | Team management |
| `GET` | `/dashboard/analytics` | Charts and analytics |

## CLI Commands

| Command | Description |
|---------|-------------|
| `ai-gateway:key:create` | Create an API key with optional overrides |
| `ai-gateway:key:list` | List all API keys |
| `ai-gateway:key:info <id>` | Show key details + usage |
| `ai-gateway:key:revoke <id>` | Disable an API key |
| `ai-gateway:team:create` | Create a team with rules |
| `ai-gateway:team:list` | List all teams |
| `ai-gateway:team:info <id>` | Show team details + keys |
| `ai-gateway:stats` | Show gateway usage statistics |

## Configuration Reference

```yaml
ai_gateway:
    routes:
        enabled: true              # Enable/disable route loading
        prefix: ''                 # Route prefix (e.g. /ai-gateway)

    dashboard:
        enabled: true              # Show/hide dashboard routes
        auth:
            enabled: false         # Enable built-in token protection
            token: null            # Token string or env var (e.g. '%env(DASHBOARD_TOKEN)%')

    providers:
        <name>:
            format: openai|anthropic|gemini|ollama|azure
            api_key: string
            base_url: string|null  # Required for ollama, azure, and custom providers
            completions_path: '/v1/chat/completions'
            streaming: true
            vision: false
            function_calling: true
            max_tokens_per_request: 128000

    models:
        <alias>:
            provider: string
            model: string
            pricing: { input: float, output: float }
            max_tokens: 128000

    pipelines:
        <name>:
            models: [model1, model2, ...]

    aliases:
        <alias>: <model-alias>
        <alias>: 'pipeline:<name>'

    retry:
        max_attempts: 2
        delay_ms: 1000
        backoff: fixed|exponential

    auth:
        enabled: false
        required: true
```

## Want a standalone server?

If you just want to run AIGateway as a dedicated server without integrating it into an existing project, check out **[symfony-ai-gateway-standalone](https://github.com/symfony-ai-gateway/symfony-ai-gateway-standalone)** — a pre-configured Symfony project with Docker, auth setup script, and ready-to-run config.

## Requirements

- PHP >= 8.2
- Symfony ^7.0 || ^8.0
- Doctrine DBAL ^4.4 (for auth storage, SQLite or any DBAL-supported DB)

## License

MIT
