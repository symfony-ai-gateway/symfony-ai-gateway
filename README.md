# AIGateway Bundle

> Symfony bundle that turns any project into an AI gateway — unified LLM access, per-key auth, budget enforcement, cost tracking, and a dashboard.

[![Packagist](https://img.shields.io/packagist/v/ai-gateway/ai-gateway-bundle.svg)](https://packagist.org/packages/ai-gateway/ai-gateway-bundle)

## Why?

Every project that calls LLMs reinvents the same plumbing: provider SDKs, API key management, retry logic, cost tracking, budget limits. AIGateway bundles all of this into one Symfony package powered by the **official Symfony AI** library.

**One `composer require`, all models.**

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
- **CLI management** — Full provider, model, key, and team management from the command line
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
    # Everything is managed via the dashboard or CLI.
    # See "CLI Commands" and "Dashboard" sections below.

    aliases:
        smart: gpt_4o
        fast: deepseek_chat

    dashboard:
        tokenRequired: true
        token: '%env(DASHBOARD_TOKEN)%'
```

```dotenv
# .env.local
DASHBOOT_TOKEN=your-secret-token-here
```

> **Providers and models** are stored in the database and managed via the dashboard or CLI. No YAML editing required after initial setup.

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
    routes:
        prefix: /ai-gateway
    dashboard:
        token: '%env(DASHBOARD_TOKEN)%'
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
        tokenRequired: true
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

## Dashboard CRUD

The dashboard lets you manage everything from the browser — no YAML editing required after initial setup. Tables are auto-created on first dashboard access.

### Providers

Add, edit, or delete LLM providers directly from `/dashboard/providers`. Each provider has:

| Field | Description |
|-------|-------------|
| **Name** | Unique identifier (used in model config) |
| **Format** | `openai`, `anthropic`, `gemini`, `ollama`, or `azure` |
| **API Key** | Provider credential |
| **Base URL** | Custom endpoint (leave empty for provider default) |
| **Completions Path** | Defaults to `/v1/chat/completions` |

### Models

Create model aliases at `/dashboard/models` that map to a provider's model ID:

| Field | Description |
|-------|-------------|
| **Alias** | Gateway-facing name (e.g. `gpt_4o`) |
| **Provider** | Links to a provider (from above) |
| **Model** | Provider's model ID (e.g. `gpt-4o`) |
| **Input Price** | Cost per million input tokens (USD) |
| **Output Price** | Cost per million output tokens (USD) |

### Keys & Teams

Create API keys and teams from `/dashboard/keys/new` and `/dashboard/teams/new`. See the [Auth & Teams](#auth--teams) section for the hierarchy model.

## CLI Commands

All management can be done via CLI — ideal for automation, CI/CD, or headless setups.

### Provider commands

```bash
# Create a provider
php bin/console provider:create \
    --name openai \
    --format openai \
    --api-key 'sk-...'

# Custom endpoint (OpenAI-compatible)
php bin/console provider:create \
    --name deepseek \
    --format openai \
    --api-key 'sk-...' \
    --base-url 'https://api.deepseek.com'

# Anthropic
php bin/console provider:create \
    --name anthropic \
    --format anthropic \
    --api-key 'sk-ant-...'

# Ollama (local)
php bin/console provider:create \
    --name ollama \
    --format ollama \
    --base-url 'http://localhost:11434'

# List providers
php bin/console provider:list

# Delete a provider (removes its models too)
php bin/console provider:delete openai
```

### Model commands

```bash
# Create a model
php bin/console model:create \
    --alias gpt_4o \
    --provider openai \
    --model gpt-4o \
    --pricing-input 2.50 \
    --pricing-output 10.00

# List models
php bin/console model:list

# Delete a model
php bin/console model:delete gpt_4o
```

### Team commands

```bash
# Create a team
php bin/console team:create \
    --name "Engineering" \
    --budget-per-day 100 \
    --budget-per-month 2000 \
    --rate-limit 60 \
    --models "gpt_4o,claude_sonnet"

# List teams
php bin/console team:list

# Show team details
php bin/console team:info <team-id>
```

### API Key commands

```bash
# Create a key
php bin/console key:create \
    --name "Frontend App" \
    --team <team-id> \
    --budget-per-day 20 \
    --budget-per-month 400 \
    --rate-limit 30 \
    --models "gpt_4o" \
    --expires 1767225600

# List keys
php bin/console key:list

# Show key details
php bin/console key:info <key-id>

# Revoke a key
php bin/console key:revoke <key-id>
```

### Stats

```bash
php bin/console stats
```

| Command | Description |
|---------|-------------|
| `provider:create` | Create a provider (openai, anthropic, gemini, ollama, azure) |
| `provider:list` | List all providers |
| `provider:delete` | Delete a provider and its models |
| `model:create` | Create a model alias |
| `model:list` | List all models |
| `model:delete` | Delete a model alias |
| `key:create` | Create an API key with optional overrides |
| `key:list` | List all API keys |
| `key:info` | Show key details + usage |
| `key:revoke` | Disable an API key |
| `team:create` | Create a team with rules |
| `team:list` | List all teams |
| `team:info` | Show team details + keys |
| `stats` | Show gateway usage statistics |

## Auth & Teams

API key authentication is **always enabled**. Every request to `/v1/*` must include a valid `Authorization: Bearer <key>` header. This ensures all usage is tracked and billable.

### Hierarchy

A **team** defines maximum limits. A **key** inherits team rules and can only restrict further:

```
Team "Engineering" ($100/day, gpt_4o + claude_sonnet)
  └── Key "Mathieu" ($20/day, gpt_4o only)
  └── Key "CI Bot" ($5/day, claude_sonnet only, 10 req/min)
```

Keys without a team have their own independent limits.

### Key override validation

When a key belongs to a team, its overrides are validated against the team's limits at creation and edit time:
- Budget/day must be ≤ team limit
- Budget/month must be ≤ team limit
- Rate limit must be ≤ team limit
- Model whitelist must be a subset of the team's allowed models

## Supported Providers

| Provider | Format | Notes |
|----------|--------|-------|
| **OpenAI** | `openai` | Native or custom base URL |
| **Anthropic** | `anthropic` | Claude models |
| **Google Gemini** | `gemini` | Gemini Pro, Flash |
| **Ollama** | `ollama` | Local models |
| **Azure OpenAI** | `azure` | Enterprise Azure |
| **Any OpenAI-compatible** | `openai` + `base_url` | DeepSeek, Groq, OpenRouter, Mistral... |

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/v1/chat/completions` | Chat completion (supports `stream: true`) |
| `GET` | `/v1/models` | List available models |
| `GET` | `/v1/health` | Health check |
| `GET` | `/v1/metrics` | Prometheus metrics |
| `GET` | `/v1/stats` | JSON usage statistics |
| `GET` | `/dashboard` | Web dashboard — overview |
| `GET` | `/dashboard/providers` | List providers |
| `GET/POST` | `/dashboard/providers/new` | Add a provider |
| `GET/POST` | `/dashboard/providers/{name}/edit` | Edit a provider |
| `POST` | `/dashboard/providers/{name}/delete` | Delete a provider |
| `GET` | `/dashboard/models` | List models |
| `GET/POST` | `/dashboard/models/new` | Add a model |
| `GET/POST` | `/dashboard/models/{alias}/edit` | Edit a model |
| `POST` | `/dashboard/models/{alias}/delete` | Delete a model |
| `GET` | `/dashboard/keys` | List API keys |
| `GET/POST` | `/dashboard/keys/new` | Create an API key |
| `GET` | `/dashboard/keys/{id}` | Key details + usage |
| `GET/POST` | `/dashboard/keys/{id}/edit` | Edit key overrides + team |
| `POST` | `/dashboard/keys/{id}/revoke` | Revoke an API key |
| `GET` | `/dashboard/teams` | List teams |
| `GET/POST` | `/dashboard/teams/new` | Create a team |
| `GET/POST` | `/dashboard/teams/{id}/edit` | Edit a team |
| `GET` | `/dashboard/teams/{id}` | Team details + keys |
| `GET` | `/dashboard/analytics` | Charts and analytics |

## Configuration Reference

```yaml
ai_gateway:
    routes:
        enabled: true              # Enable/disable route loading
        prefix: ''                 # Route prefix (e.g. /ai-gateway)

    dashboard:
        enabled: true              # Show/hide dashboard routes
        tokenRequired: false       # Require token to access dashboard
        token: null                # Token string or env var (e.g. '%env(DASHBOARD_TOKEN)%')

    # Optional: static routing aliases
    aliases:
        <alias>: <model-alias>
        <alias>: 'pipeline:<name>'

    # Optional: fallback pipelines
    pipelines:
        <name>:
            models: [model1, model2, ...]

    # Optional: retry configuration
    retry:
        max_attempts: 2
        delay_ms: 1000
        backoff: fixed|exponential
```

> **Note:** `providers` and `models` are no longer configured in YAML. They are stored in the database and managed via the dashboard or CLI commands (`provider:create`, `model:create`, etc.).

## Want a standalone server?

If you just want to run AIGateway as a dedicated server without integrating it into an existing project, check out **[symfony-ai-gateway-standalone](https://github.com/symfony-ai-gateway/symfony-ai-gateway-standalone)** — a pre-configured Symfony project with Docker and ready-to-run config.

## Requirements

- PHP >= 8.2
- Symfony ^7.0 || ^8.0
- Doctrine DBAL ^4.4 (for auth/rate-limit storage, SQLite or any DBAL-supported DB)

## License

MIT
