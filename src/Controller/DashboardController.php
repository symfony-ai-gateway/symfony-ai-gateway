<?php

declare(strict_types=1);

namespace AIGateway\Controller;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use AIGateway\Auth\Store\KeyStoreInterface;
use AIGateway\Config\ConfigStore;
use AIGateway\Logging\RequestLogger;

use function count;
use function time;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly KeyStoreInterface|null $keyStore = null,
        private readonly RequestLogger|null $requestLogger = null,
        private readonly ConfigStore|null $configStore = null,
    ) {
    }

    #[Route('/dashboard', name: 'ai_gateway_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $keys = $this->keyStore?->listKeys() ?? [];
        $teams = $this->keyStore?->listTeams() ?? [];
        $providers = $this->configStore?->listProviders() ?? [];
        $models = $this->configStore?->listModels() ?? [];
        $activeKeys = count(array_filter($keys, static fn ($k): bool => $k->enabled));

        $totalCost = 0.0;
        $totalTokens = 0;

        if (null !== $this->requestLogger) {
            foreach ($this->requestLogger->getLogs() as $log) {
                $totalCost += $log->costUsd;
                $totalTokens += $log->totalTokens;
            }
        }

        return new Response($this->twig->render('@AIGateway/dashboard/index.html.twig', $this->params($request, [
            'total_keys' => count($keys),
            'active_keys' => $activeKeys,
            'total_teams' => count($teams),
            'total_providers' => count($providers),
            'total_models' => count($models),
            'total_requests' => $this->requestLogger?->getTotalRequests() ?? 0,
            'total_errors' => $this->requestLogger?->getTotalErrors() ?? 0,
            'total_cost' => $totalCost,
            'total_tokens' => $totalTokens,
            'avg_duration' => $this->requestLogger?->getAverageDurationMs() ?? 0.0,
        ])));
    }

    #[Route('/dashboard/providers', name: 'ai_gateway_dashboard_providers', methods: ['GET'])]
    public function providers(Request $request): Response
    {
        return new Response($this->twig->render('@AIGateway/dashboard/providers.html.twig', $this->params($request, [
            'providers' => $this->configStore?->listProviders() ?? [],
        ])));
    }

    #[Route('/dashboard/providers/new', name: 'ai_gateway_dashboard_provider_new', methods: ['GET', 'POST'])]
    public function providerNew(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->configStore?->saveProvider(
                name: $this->post($request, 'name'),
                format: $this->post($request, 'format', 'openai'),
                apiKey: $this->post($request, 'api_key', ''),
                baseUrl: $this->post($request, 'base_url') ?: null,
                completionsPath: $this->post($request, 'completions_path', '/v1/chat/completions'),
            );

            return new RedirectResponse($this->url($request, '/dashboard/providers'));
        }

        return new Response($this->twig->render('@AIGateway/dashboard/provider_form.html.twig', $this->params($request, [
            'provider' => null,
            'action' => 'new',
        ])));
    }

    #[Route('/dashboard/providers/{name}/edit', name: 'ai_gateway_dashboard_provider_edit', methods: ['GET', 'POST'])]
    public function providerEdit(Request $request, string $name): Response
    {
        $provider = $this->configStore?->getProvider($name);

        if (null === $provider) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'Provider not found.',
            ])), 404);
        }

        if ($request->isMethod('POST')) {
            $this->configStore?->saveProvider(
                name: $name,
                format: $this->post($request, 'format', 'openai'),
                apiKey: $this->post($request, 'api_key', ''),
                baseUrl: $this->post($request, 'base_url') ?: null,
                completionsPath: $this->post($request, 'completions_path', '/v1/chat/completions'),
            );

            return new RedirectResponse($this->url($request, '/dashboard/providers'));
        }

        return new Response($this->twig->render('@AIGateway/dashboard/provider_form.html.twig', $this->params($request, [
            'provider' => $provider,
            'action' => 'edit',
        ])));
    }

    #[Route('/dashboard/providers/{name}/delete', name: 'ai_gateway_dashboard_provider_delete', methods: ['POST'])]
    public function providerDelete(Request $request, string $name): Response
    {
        $this->configStore?->deleteProvider($name);

        return new RedirectResponse($this->url($request, '/dashboard/providers'));
    }

    #[Route('/dashboard/models', name: 'ai_gateway_dashboard_models', methods: ['GET'])]
    public function models(Request $request): Response
    {
        $providers = $this->configStore?->listProviders() ?? [];
        $providerNames = [];
        foreach ($providers as $p) {
            $providerNames[$p['name']] = $p['name'];
        }

        return new Response($this->twig->render('@AIGateway/dashboard/models.html.twig', $this->params($request, [
            'models' => $this->configStore?->listModels() ?? [],
            'provider_names' => $providerNames,
        ])));
    }

    #[Route('/dashboard/models/new', name: 'ai_gateway_dashboard_model_new', methods: ['GET', 'POST'])]
    public function modelNew(Request $request): Response
    {
        $providers = $this->configStore?->listProviders() ?? [];

        if ($request->isMethod('POST')) {
            $this->configStore?->saveModel(
                alias: $this->post($request, 'alias'),
                providerName: $this->post($request, 'provider_name'),
                model: $this->post($request, 'model'),
                pricingInput: (float) $this->post($request, 'pricing_input', '0'),
                pricingOutput: (float) $this->post($request, 'pricing_output', '0'),
            );

            return new RedirectResponse($this->url($request, '/dashboard/models'));
        }

        return new Response($this->twig->render('@AIGateway/dashboard/model_form.html.twig', $this->params($request, [
            'model' => null,
            'providers' => $providers,
            'action' => 'new',
        ])));
    }

    #[Route('/dashboard/models/{alias}/edit', name: 'ai_gateway_dashboard_model_edit', methods: ['GET', 'POST'])]
    public function modelEdit(Request $request, string $alias): Response
    {
        $model = $this->configStore?->getModel($alias);
        $providers = $this->configStore?->listProviders() ?? [];

        if (null === $model) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'Model not found.',
            ])), 404);
        }

        if ($request->isMethod('POST')) {
            $this->configStore?->saveModel(
                alias: $alias,
                providerName: $this->post($request, 'provider_name'),
                model: $this->post($request, 'model'),
                pricingInput: (float) $this->post($request, 'pricing_input', '0'),
                pricingOutput: (float) $this->post($request, 'pricing_output', '0'),
            );

            return new RedirectResponse($this->url($request, '/dashboard/models'));
        }

        return new Response($this->twig->render('@AIGateway/dashboard/model_form.html.twig', $this->params($request, [
            'model' => $model,
            'providers' => $providers,
            'action' => 'edit',
        ])));
    }

    #[Route('/dashboard/models/{alias}/delete', name: 'ai_gateway_dashboard_model_delete', methods: ['POST'])]
    public function modelDelete(Request $request, string $alias): Response
    {
        $this->configStore?->deleteModel($alias);

        return new RedirectResponse($this->url($request, '/dashboard/models'));
    }

    #[Route('/dashboard/keys', name: 'ai_gateway_dashboard_keys', methods: ['GET'])]
    public function keys(Request $request): Response
    {
        $keys = $this->keyStore?->listKeys() ?? [];
        $teams = $this->keyStore?->listTeams() ?? [];

        $teamNames = [];
        foreach ($teams as $team) {
            $teamNames[$team->id] = $team->name;
        }

        return new Response($this->twig->render('@AIGateway/dashboard/keys.html.twig', $this->params($request, [
            'keys' => $keys,
            'team_names' => $teamNames,
        ])));
    }

    #[Route('/dashboard/keys/new', name: 'ai_gateway_dashboard_key_new', methods: ['GET', 'POST'])]
    public function keyNew(Request $request): Response
    {
        $teams = $this->keyStore?->listTeams() ?? [];
        $models = $this->configStore?->listModels() ?? [];
        $modelAliases = array_map(static fn ($m): string => $m['alias'], $models);

        if ($request->isMethod('POST')) {
            $selectedModels = array_filter(explode(',', $this->post($request, 'models')));
            $teamId = $this->post($request, 'team_id') ?: null;

            $rawToken = 'aigw_'.bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $rawToken);
            $prefix = substr($rawToken, 0, 8);

            $key = new ApiKey(
                id: bin2hex(random_bytes(16)),
                name: $this->post($request, 'name'),
                keyHash: $tokenHash,
                tokenPrefix: $prefix,
                teamId: $teamId,
                overrides: new KeyRules(
                    models: [] !== $selectedModels ? $selectedModels : null,
                    budgetPerDay: '' !== $this->post($request, 'budget_per_day') ? (float) $this->post($request, 'budget_per_day') : null,
                ),
                enabled: true,
                expiresAt: null,
                createdAt: time(),
            );

            $this->keyStore?->saveKey($key);

            return new Response($this->twig->render('@AIGateway/dashboard/key_created.html.twig', $this->params($request, [
                'key' => $key,
                'raw_token' => $rawToken,
            ])));
        }

        return new Response($this->twig->render('@AIGateway/dashboard/key_form.html.twig', $this->params($request, [
            'teams' => $teams,
            'model_aliases' => $modelAliases,
        ])));
    }

    #[Route('/dashboard/keys/{id}', name: 'ai_gateway_dashboard_key_detail', methods: ['GET'])]
    public function keyDetail(Request $request, string $id): Response
    {
        $key = $this->keyStore?->findKeyById($id);

        if (null === $key) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'API key not found.',
            ])), 404);
        }

        $team = null !== $key->teamId ? $this->keyStore?->findTeamById($key->teamId) : null;
        $today = date('Y-m-d');
        $usage = $this->keyStore?->getKeyUsage($key->id, $today, $today) ?? null;

        return new Response($this->twig->render('@AIGateway/dashboard/keys_detail.html.twig', $this->params($request, [
            'key' => $key,
            'team' => $team,
            'usage_today' => $usage,
        ])));
    }

    #[Route('/dashboard/keys/{id}/edit', name: 'ai_gateway_dashboard_key_edit', methods: ['GET', 'POST'])]
    public function keyEdit(Request $request, string $id): Response
    {
        $key = $this->keyStore?->findKeyById($id);

        if (null === $key) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'API key not found.',
            ])), 404);
        }

        if ($request->isMethod('POST')) {
            $selectedModels = array_filter(explode(',', $this->post($request, 'models')));
            $teamId = $this->post($request, 'team_id') ?: null;

            $updated = new ApiKey(
                id: $key->id,
                name: $this->post($request, 'name', $key->name),
                keyHash: $key->keyHash,
                tokenPrefix: $key->tokenPrefix,
                teamId: $teamId,
                overrides: new KeyRules(
                    models: [] !== $selectedModels ? $selectedModels : null,
                    budgetPerDay: '' !== $this->post($request, 'budget_per_day') ? (float) $this->post($request, 'budget_per_day') : null,
                ),
                enabled: $key->enabled,
                expiresAt: $key->expiresAt,
                createdAt: $key->createdAt,
            );

            $this->keyStore?->saveKey($updated);

            return new RedirectResponse($this->url($request, '/dashboard/keys'));
        }

        $teams = $this->keyStore?->listTeams() ?? [];
        $models = $this->configStore?->listModels() ?? [];
        $modelAliases = array_map(static fn ($m): string => $m['alias'], $models);

        return new Response($this->twig->render('@AIGateway/dashboard/key_edit.html.twig', $this->params($request, [
            'key' => $key,
            'teams' => $teams,
            'model_aliases' => $modelAliases,
        ])));
    }

    #[Route('/dashboard/keys/{id}/revoke', name: 'ai_gateway_dashboard_key_revoke', methods: ['POST'])]
    public function keyRevoke(Request $request, string $id): Response
    {
        $key = $this->keyStore?->findKeyById($id);
        if (null === $key) {
            return new RedirectResponse($this->url($request, '/dashboard/keys'));
        }

        $revoked = new ApiKey(
            id: $key->id,
            name: $key->name,
            keyHash: $key->keyHash,
            tokenPrefix: $key->tokenPrefix,
            teamId: $key->teamId,
            overrides: $key->overrides,
            enabled: false,
            expiresAt: $key->expiresAt,
            createdAt: $key->createdAt,
        );

        $this->keyStore?->saveKey($revoked);

        return new RedirectResponse($this->url($request, '/dashboard/keys'));
    }

    #[Route('/dashboard/teams', name: 'ai_gateway_dashboard_teams', methods: ['GET'])]
    public function teams(Request $request): Response
    {
        $teams = $this->keyStore?->listTeams() ?? [];
        $keys = $this->keyStore?->listKeys() ?? [];

        $teamKeyCounts = [];
        foreach ($keys as $key) {
            if (null !== $key->teamId) {
                $teamKeyCounts[$key->teamId] = ($teamKeyCounts[$key->teamId] ?? 0) + 1;
            }
        }

        return new Response($this->twig->render('@AIGateway/dashboard/teams.html.twig', $this->params($request, [
            'teams' => $teams,
            'team_key_counts' => $teamKeyCounts,
        ])));
    }

    #[Route('/dashboard/teams/new', name: 'ai_gateway_dashboard_team_new', methods: ['GET', 'POST'])]
    public function teamNew(Request $request): Response
    {
        $models = $this->configStore?->listModels() ?? [];
        $modelAliases = array_map(static fn ($m): string => $m['alias'], $models);

        if ($request->isMethod('POST')) {
            $selectedModels = array_filter(explode(',', $this->post($request, 'models')));

            $team = new Team(
                id: bin2hex(random_bytes(16)),
                name: $this->post($request, 'name'),
                parentId: $this->post($request, 'parent_id') ?: null,
                rules: new KeyRules(
                    budgetPerDay: '' !== $this->post($request, 'budget_per_day') ? (float) $this->post($request, 'budget_per_day') : null,
                    budgetPerMonth: '' !== $this->post($request, 'budget_per_month') ? (float) $this->post($request, 'budget_per_month') : null,
                    rateLimitPerMinute: '' !== $this->post($request, 'rate_limit') ? (int) $this->post($request, 'rate_limit') : null,
                    models: [] !== $selectedModels ? $selectedModels : null,
                ),
                createdAt: time(),
            );

            $this->keyStore?->saveTeam($team);

            return new RedirectResponse($this->url($request, '/dashboard/teams'));
        }

        $teams = $this->keyStore?->listTeams() ?? [];

        return new Response($this->twig->render('@AIGateway/dashboard/team_form.html.twig', $this->params($request, [
            'team' => null,
            'teams' => $teams,
            'model_aliases' => $modelAliases,
            'action' => 'new',
        ])));
    }

    #[Route('/dashboard/teams/{id}', name: 'ai_gateway_dashboard_team_detail', methods: ['GET'])]
    public function teamDetail(Request $request, string $id): Response
    {
        $team = $this->keyStore?->findTeamById($id);

        if (null === $team) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'Team not found.',
            ])), 404);
        }

        $ancestry = $this->keyStore?->findTeamAncestry($id) ?? [];
        $keys = $this->keyStore?->listKeys() ?? [];
        $teamKeys = array_filter($keys, static fn ($k): bool => $k->teamId === $id);
        $parentTeam = null !== $team->parentId ? $this->keyStore?->findTeamById($team->parentId) : null;

        return new Response($this->twig->render('@AIGateway/dashboard/teams_detail.html.twig', $this->params($request, [
            'team' => $team,
            'parent_team' => $parentTeam,
            'ancestry' => $ancestry,
            'team_keys' => $teamKeys,
        ])));
    }

    #[Route('/dashboard/teams/{id}/edit', name: 'ai_gateway_dashboard_team_edit', methods: ['GET', 'POST'])]
    public function teamEdit(Request $request, string $id): Response
    {
        $team = $this->keyStore?->findTeamById($id);

        if (null === $team) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'Team not found.',
            ])), 404);
        }

        if ($request->isMethod('POST')) {
            $selectedModels = array_filter(explode(',', $this->post($request, 'models')));

            $updated = new Team(
                id: $team->id,
                name: $this->post($request, 'name', $team->name),
                parentId: $team->parentId,
                rules: new KeyRules(
                    budgetPerDay: '' !== $this->post($request, 'budget_per_day') ? (float) $this->post($request, 'budget_per_day') : null,
                    budgetPerMonth: '' !== $this->post($request, 'budget_per_month') ? (float) $this->post($request, 'budget_per_month') : null,
                    rateLimitPerMinute: '' !== $this->post($request, 'rate_limit') ? (int) $this->post($request, 'rate_limit') : null,
                    models: [] !== $selectedModels ? $selectedModels : null,
                ),
                createdAt: $team->createdAt,
            );

            $this->keyStore?->saveTeam($updated);

            return new RedirectResponse($this->url($request, '/dashboard/teams'));
        }

        $allTeams = $this->keyStore?->listTeams() ?? [];
        $models = $this->configStore?->listModels() ?? [];
        $modelAliases = array_map(static fn ($m): string => $m['alias'], $models);

        return new Response($this->twig->render('@AIGateway/dashboard/team_form.html.twig', $this->params($request, [
            'team' => $team,
            'teams' => $allTeams,
            'model_aliases' => $modelAliases,
            'action' => 'edit',
        ])));
    }

    #[Route('/dashboard/analytics', name: 'ai_gateway_dashboard_analytics', methods: ['GET'])]
    public function analytics(Request $request): Response
    {
        $logs = $this->requestLogger?->getLogs() ?? [];

        $byProvider = [];
        $byModel = [];

        foreach ($logs as $log) {
            $byProvider[$log->provider] = ($byProvider[$log->provider] ?? 0) + 1;
            $byModel[$log->model] ??= ['requests' => 0, 'cost' => 0.0];
            ++$byModel[$log->model]['requests'];
            $byModel[$log->model]['cost'] += $log->costUsd;
        }

        return new Response($this->twig->render('@AIGateway/dashboard/analytics.html.twig', $this->params($request, [
            'logs' => $logs,
            'by_provider' => $byProvider,
            'by_model' => $byModel,
            'total_logs' => count($logs),
        ])));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function params(Request $request, array $data): array
    {
        $data['dashboard_token'] = $request->attributes->get('dashboard_token', '');
        $data['has_dashboard_token'] = '' !== $data['dashboard_token'];

        return $data;
    }

    private function url(Request $request, string $path): string
    {
        $token = $request->attributes->get('dashboard_token', '');
        $base = $request->getSchemeAndHttpHost();

        return $base.$path.('' !== $token ? '?token='.urlencode($token) : '');
    }

    private function post(Request $request, string $key, string $default = ''): string
    {
        $value = $request->request->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
