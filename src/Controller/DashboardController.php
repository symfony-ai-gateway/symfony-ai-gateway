<?php

declare(strict_types=1);

namespace AIGateway\Controller;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use AIGateway\Auth\Store\KeyStoreInterface;
use AIGateway\Config\ConfigStore;
use AIGateway\Logging\RequestLogger;
use AIGateway\Logging\RequestLogStore;

use function count;
use function is_string;
use function sprintf;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function strtotime;
use function time;

use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly KeyStoreInterface|null $keyStore = null,
        private readonly RequestLogger|null $requestLogger = null,
        private readonly ConfigStore|null $configStore = null,
        private readonly RequestLogStore|null $requestLogStore = null,
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

        $globalStats = $this->requestLogStore?->getGlobalStats() ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'errors' => 0];
        $dailyUsage = $this->requestLogStore?->getDailyUsage(30) ?? [];
        $topModels = $this->requestLogStore?->getTopModels(5) ?? [];
        $topKeys = $this->requestLogStore?->getTopKeys(5) ?? [];

        $avgDuration = 0.0;
        if (null !== $this->requestLogger) {
            $avgDuration = $this->requestLogger->getAverageDurationMs();
        }

        return new Response($this->twig->render('@AIGateway/dashboard/index.html.twig', $this->params($request, [
            'total_keys' => count($keys),
            'active_keys' => $activeKeys,
            'total_teams' => count($teams),
            'total_providers' => count($providers),
            'total_models' => count($models),
            'total_requests' => $globalStats['requests'],
            'total_errors' => $globalStats['errors'],
            'total_cost' => $globalStats['cost'],
            'total_tokens' => $globalStats['tokens'],
            'avg_duration' => $avgDuration,
            'daily_usage' => $dailyUsage,
            'top_models' => $topModels,
            'top_keys' => $topKeys,
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

    #[Route('/dashboard/providers/{name}', name: 'ai_gateway_dashboard_provider_detail', methods: ['GET'])]
    public function providerDetail(Request $request, string $name): Response
    {
        $provider = $this->configStore?->getProvider($name);

        if (null === $provider) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'Provider not found.',
            ])), 404);
        }

        $providerStats = $this->requestLogStore?->getProviderStats($name) ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'errors' => 0];
        $modelBreakdown = $this->requestLogStore?->getProviderModelBreakdown($name) ?? [];
        $topKeys = $this->requestLogStore?->getProviderTopKeys($name, 10) ?? [];
        $dailyUsage = $this->requestLogStore?->getProviderDailyUsage($name, 60) ?? [];
        $recentLogs = $this->requestLogStore?->getProviderLogs($name, 20) ?? [];

        return new Response($this->twig->render('@AIGateway/dashboard/providers_detail.html.twig', $this->params($request, [
            'provider' => $provider,
            'provider_stats' => $providerStats,
            'model_breakdown' => $modelBreakdown,
            'top_keys' => $topKeys,
            'daily_usage' => $dailyUsage,
            'recent_logs' => $recentLogs,
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
            $newAlias = $this->post($request, 'alias', $alias);
            $providerName = $this->post($request, 'provider_name');
            $providerModel = $this->post($request, 'model');
            $pricingInput = (float) $this->post($request, 'pricing_input', '0');
            $pricingOutput = (float) $this->post($request, 'pricing_output', '0');

            if ($newAlias !== $alias) {
                $this->configStore?->deleteModel($alias);
            }

            $this->configStore?->saveModel(
                alias: $newAlias,
                providerName: $providerName,
                model: $providerModel,
                pricingInput: $pricingInput,
                pricingOutput: $pricingOutput,
            );

            return new RedirectResponse($this->url($request, '/dashboard/models'));
        }

        return new Response($this->twig->render('@AIGateway/dashboard/model_form.html.twig', $this->params($request, [
            'model' => $model,
            'providers' => $providers,
            'action' => 'edit',
        ])));
    }

    #[Route('/dashboard/models/{alias}', name: 'ai_gateway_dashboard_model_detail', methods: ['GET'])]
    public function modelDetail(Request $request, string $alias): Response
    {
        $model = $this->configStore?->getModel($alias);

        if (null === $model) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', $this->params($request, [
                'message' => 'Model not found.',
            ])), 404);
        }

        $modelStats = $this->requestLogStore?->getModelStats($alias) ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'errors' => 0];
        $teamBreakdown = $this->requestLogStore?->getModelTeamBreakdown($alias) ?? [];
        $keyBreakdown = $this->requestLogStore?->getModelKeyBreakdown($alias) ?? [];
        $dailyUsage = $this->requestLogStore?->getModelDailyUsage($alias, 60) ?? [];
        $recentLogs = $this->requestLogStore?->getModelLogs($alias, 20) ?? [];
        $deployments = $this->configStore?->getDeployments($alias) ?? [];
        $providers = $this->configStore?->listProviders() ?? [];
        $routingStrategy = $this->configStore?->getModelRoutingStrategy($alias) ?? 'weighted';

        return new Response($this->twig->render('@AIGateway/dashboard/models_detail.html.twig', $this->params($request, [
            'model' => $model,
            'model_stats' => $modelStats,
            'team_breakdown' => $teamBreakdown,
            'key_breakdown' => $keyBreakdown,
            'daily_usage' => $dailyUsage,
            'recent_logs' => $recentLogs,
            'deployments' => $deployments,
            'providers' => $providers,
            'routing_strategy' => $routingStrategy,
        ])));
    }

    #[Route('/dashboard/models/{alias}/delete', name: 'ai_gateway_dashboard_model_delete', methods: ['POST'])]
    public function modelDelete(Request $request, string $alias): Response
    {
        $this->configStore?->deleteModel($alias);

        return new RedirectResponse($this->url($request, '/dashboard/models'));
    }

    #[Route('/dashboard/models/{alias}/deployments/add', name: 'ai_gateway_dashboard_deployment_add', methods: ['POST'])]
    public function deploymentAdd(Request $request, string $alias): Response
    {
        $providerName = (string) ($request->request->get('provider_name') ?? '');
        $model = (string) ($request->request->get('model') ?? '');
        $priority = (int) ($request->request->get('priority') ?? 1);
        $weight = (int) ($request->request->get('weight') ?? 100);
        $rpmLimit = $request->request->get('rpm_limit');
        $rpmLimit = '' !== $rpmLimit ? (int) $rpmLimit : null;

        if ('' !== $providerName && '' !== $model) {
            $this->configStore?->addDeployment($alias, $providerName, $model, $priority, $weight, $rpmLimit);
        }

        return new RedirectResponse($this->url($request, '/dashboard/models/' . $alias));
    }

    #[Route('/dashboard/deployments/{id}/remove', name: 'ai_gateway_dashboard_deployment_remove', methods: ['POST'])]
    public function deploymentRemove(Request $request, int $id): Response
    {
        $alias = (string) ($request->request->get('alias') ?? '');
        $this->configStore?->removeDeployment($id);

        return new RedirectResponse($this->url($request, '/dashboard/models/' . $alias));
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

            $budgetPerDay = '' !== $this->post($request, 'budget_per_day') ? (float) $this->post($request, 'budget_per_day') : null;
            $budgetPerMonth = '' !== $this->post($request, 'budget_per_month') ? (float) $this->post($request, 'budget_per_month') : null;
            $rateLimit = '' !== $this->post($request, 'rate_limit') ? (int) $this->post($request, 'rate_limit') : null;

            $teamForValidation = null;
            if (null !== $teamId) {
                $teamForValidation = $this->keyStore?->findTeamById($teamId);
            }
            if (null !== $teamForValidation) {
                $errors = $this->validateKeyOverrides($teamForValidation, $budgetPerDay, $budgetPerMonth, $rateLimit, $selectedModels);
                if ([] !== $errors) {
                    return new Response($this->twig->render('@AIGateway/dashboard/key_form.html.twig', $this->params($request, [
                        'teams' => $teams,
                        'model_aliases' => $modelAliases,
                        'errors' => $errors,
                        'submitted' => ['name' => $this->post($request, 'name'), 'team_id' => $teamId, 'models' => $this->post($request, 'models'), 'budget_per_day' => $this->post($request, 'budget_per_day'), 'budget_per_month' => $this->post($request, 'budget_per_month'), 'rate_limit' => $this->post($request, 'rate_limit')],
                    ])), 422);
                }
            }

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
                    budgetPerDay: $budgetPerDay,
                    budgetPerMonth: $budgetPerMonth,
                    rateLimitPerMinute: $rateLimit,
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

        $effectiveRules = null !== $team
            ? $key->resolveRules($team)
            : ($key->overrides ?? new KeyRules());

        $hasOwnOverrides = false;
        if (null !== $key->overrides) {
            $r = $key->overrides;
            $hasOwnOverrides = null !== $r->budgetPerDay || null !== $r->budgetPerMonth || null !== $r->rateLimitPerMinute || null !== $r->models;
        }

        $keyStats = $this->requestLogStore?->getKeyStats($id) ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'errors' => 0];
        $modelBreakdown = $this->requestLogStore?->getKeyModelBreakdown($id) ?? [];
        $recentLogs = $this->requestLogStore?->getKeyLogs($id, 20) ?? [];
        $keyDailyUsage = $this->requestLogStore?->getKeyDailyUsage($id, 30) ?? [];

        // Build stacked bar data: requests per day, stacked by model
        $dailyByModel = $this->requestLogStore?->getKeyDailyUsageByModel($id, 30) ?? [];
        $dailyDates = [];
        $dailyModelBuckets = [];
        foreach ($dailyByModel as $row) {
            $date = $row['date'];
            $model = $row['model_alias'];
            $count = (int) $row['requests'];
            if (!in_array($date, $dailyDates, true)) {
                $dailyDates[] = $date;
            }
            $dailyModelBuckets[$model][$date] = ($dailyModelBuckets[$model][$date] ?? 0) + $count;
        }
        $dailyModelDatasets = [];
        foreach ($dailyModelBuckets as $model => $buckets) {
            $aligned = [];
            foreach ($dailyDates as $date) {
                $aligned[] = $buckets[$date] ?? 0;
            }
            $dailyModelDatasets[] = ['label' => $model, 'data' => $aligned];
        }

        // Get team-relative stats
        $teamStats = null !== $key->teamId ? ($this->requestLogStore?->getTeamStats($key->teamId) ?? null) : null;
        $keySharePct = null;
        if (null !== $teamStats && $teamStats['cost'] > 0) {
            $keySharePct = ($keyStats['cost'] / $teamStats['cost']) * 100;
        }

        // Extract unique providers from model breakdown
        $providers = [];
        foreach ($modelBreakdown as $mb) {
            $modelData = $this->configStore?->getModel($mb['model_alias']);
            if (null !== $modelData) {
                $providers[$modelData['provider_name']] = ($providers[$modelData['provider_name']] ?? 0) + $mb['cost'];
            }
        }

        return new Response($this->twig->render('@AIGateway/dashboard/keys_detail.html.twig', $this->params($request, [
            'key' => $key,
            'team' => $team,
            'usage_today' => $usage,
            'effective_rules' => $effectiveRules,
            'has_own_overrides' => $hasOwnOverrides,
            'key_stats' => $keyStats,
            'model_breakdown' => $modelBreakdown,
            'recent_logs' => $recentLogs,
            'key_daily_usage' => $keyDailyUsage,
            'daily_dates' => $dailyDates,
            'daily_model_datasets' => $dailyModelDatasets,
            'team_stats' => $teamStats,
            'key_share_pct' => $keySharePct,
            'providers' => $providers,
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

            $budgetPerDay = '' !== $this->post($request, 'budget_per_day') ? (float) $this->post($request, 'budget_per_day') : null;
            $budgetPerMonth = '' !== $this->post($request, 'budget_per_month') ? (float) $this->post($request, 'budget_per_month') : null;
            $rateLimit = '' !== $this->post($request, 'rate_limit') ? (int) $this->post($request, 'rate_limit') : null;

            $teamForValidation = null;
            if (null !== $teamId) {
                $teamForValidation = $this->keyStore?->findTeamById($teamId);
            }
            if (null !== $teamForValidation) {
                $errors = $this->validateKeyOverrides($teamForValidation, $budgetPerDay, $budgetPerMonth, $rateLimit, $selectedModels);
                if ([] !== $errors) {
                    $allTeams = $this->keyStore?->listTeams() ?? [];
                    $models = $this->configStore?->listModels() ?? [];

                    return new Response($this->twig->render('@AIGateway/dashboard/key_edit.html.twig', $this->params($request, [
                        'key' => $key,
                        'teams' => $allTeams,
                        'current_team' => $teamForValidation,
                        'model_aliases' => array_map(static fn ($m): string => $m['alias'], $models),
                        'errors' => $errors,
                    ])), 422);
                }
            }

            $updated = new ApiKey(
                id: $key->id,
                name: $this->post($request, 'name', $key->name),
                keyHash: $key->keyHash,
                tokenPrefix: $key->tokenPrefix,
                teamId: $teamId,
                overrides: new KeyRules(
                    models: [] !== $selectedModels ? $selectedModels : null,
                    budgetPerDay: $budgetPerDay,
                    budgetPerMonth: $budgetPerMonth,
                    rateLimitPerMinute: $rateLimit,
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
        $currentTeam = null !== $key->teamId ? $this->keyStore?->findTeamById($key->teamId) : null;

        return new Response($this->twig->render('@AIGateway/dashboard/key_edit.html.twig', $this->params($request, [
            'key' => $key,
            'teams' => $teams,
            'current_team' => $currentTeam,
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

        $teamStats = $this->requestLogStore?->getTeamStats($id) ?? ['requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'errors' => 0];
        $teamDailyUsage = $this->requestLogStore?->getTeamDailyUsage($id, 30) ?? [];
        $modelBreakdown = $this->requestLogStore?->getTeamModelBreakdown($id) ?? [];
        $providerBreakdown = $this->requestLogStore?->getTeamProviderBreakdown($id) ?? [];
        $memberUsage = $this->requestLogStore?->getTeamMemberUsage($id) ?? [];
        $recentLogs = $this->requestLogStore?->getTeamLogs($id, 20) ?? [];

        return new Response($this->twig->render('@AIGateway/dashboard/teams_detail.html.twig', $this->params($request, [
            'team' => $team,
            'parent_team' => $parentTeam,
            'ancestry' => $ancestry,
            'team_keys' => $teamKeys,
            'team_stats' => $teamStats,
            'team_daily_usage' => $teamDailyUsage,
            'model_breakdown' => $modelBreakdown,
            'provider_breakdown' => $providerBreakdown,
            'member_usage' => $memberUsage,
            'recent_logs' => $recentLogs,
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

    #[Route('/dashboard/requests', name: 'ai_gateway_dashboard_requests', methods: ['GET'])]
    public function requests(Request $request): Response
    {
        $filters = [];

        $keyName = $request->query->get('key_name', '');
        $teamName = $request->query->get('team_name', '');
        $provider = $request->query->get('provider', '');
        $modelAlias = $request->query->get('model_alias', '');
        $status = $request->query->get('status', '');
        $errorFilter = $request->query->get('error', '');
        $dateFrom = $request->query->get('date_from', '');
        $dateTo = $request->query->get('date_to', '');
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = 50;

        if ('' !== $keyName) {
            $filters['key_name'] = $keyName;
        }
        if ('' !== $teamName) {
            $filters['team_name'] = $teamName;
        }
        if ('' !== $provider) {
            $filters['provider'] = $provider;
        }
        if ('' !== $modelAlias) {
            $filters['model_alias'] = $modelAlias;
        }
        if ('' !== $status) {
            if ('error' === $status) {
                $filters['status_code_min'] = 400;
            } elseif ('success' === $status) {
                $filters['status_code_max'] = 399;
            }
        }
        if ('' !== $errorFilter) {
            $filters['error'] = $errorFilter;
        }
        if ('' !== $dateFrom) {
            $filters['date_from'] = strtotime($dateFrom) ?: null;
        }
        if ('' !== $dateTo) {
            $filters['date_to'] = strtotime($dateTo) ?: null;
        }

        $result = $this->requestLogStore?->searchRequests($filters, $perPage, ($page - 1) * $perPage)
            ?? ['rows' => [], 'total' => 0, 'summary' => ['requests' => 0, 'tokens' => 0, 'cost' => 0.0, 'errors' => 0]];

        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        // Get provider/model lists for filter dropdowns
        $allProviders = $this->configStore?->listProviders() ?? [];
        $allModels = $this->configStore?->listModels() ?? [];

        // Build team name lookup (team_name is not stored in log, display from key store)
        $allTeams = $this->keyStore?->listTeams() ?? [];
        $teamNames = [];
        foreach ($allTeams as $t) {
            $teamNames[$t->id] = $t->name;
        }

        $summary = $result['summary'];

        // Get 9 filtered breakdowns for charts (tokens/cost/requests × model/key/team)
        $breakdowns = $this->requestLogStore?->getFilteredBreakdowns($filters, 10)
            ?? [
                'tokens_by_model' => [], 'tokens_by_key' => [], 'tokens_by_team' => [],
                'cost_by_model' => [], 'cost_by_key' => [], 'cost_by_team' => [],
                'requests_by_model' => [], 'requests_by_key' => [], 'requests_by_team' => [],
            ];

        // Resolve team IDs to names in team breakdowns
        foreach (['tokens_by_team', 'cost_by_team', 'requests_by_team'] as $key) {
            foreach ($breakdowns[$key] as $i => $item) {
                $label = $item['label'];
                if (isset($teamNames[$label])) {
                    $breakdowns[$key][$i]['label'] = $teamNames[$label];
                }
            }
        }

        return new Response($this->twig->render('@AIGateway/dashboard/requests.html.twig', $this->params($request, [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'total_pages' => $totalPages,
            'page' => $page,
            'summary' => $summary,
            'breakdowns' => $breakdowns,
            'filter_key_name' => $keyName,
            'filter_team_name' => $teamName,
            'filter_provider' => $provider,
            'filter_model_alias' => $modelAlias,
            'filter_status' => $status,
            'filter_error' => $errorFilter,
            'filter_date_from' => $dateFrom,
            'filter_date_to' => $dateTo,
            'all_providers' => $allProviders,
            'all_models' => $allModels,
            'team_names' => $teamNames,
        ])));
    }

    #[Route('/dashboard/analytics', name: 'ai_gateway_dashboard_analytics', methods: ['GET'])]
    public function analytics(Request $request): Response
    {
        return new RedirectResponse($this->url($request, '/dashboard/requests'));
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
        /** @var string $token */
        $token = $request->attributes->get('dashboard_token', '');
        $base = $request->getSchemeAndHttpHost();

        return $base.$path.('' !== $token ? '?token='.urlencode($token) : '');
    }

    private function post(Request $request, string $key, string $default = ''): string
    {
        $value = $request->request->get($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Validates that key overrides don't exceed team limits.
     *
     * @param list<string> $selectedModels
     *
     * @return list<string> List of validation error messages
     */
    private function validateKeyOverrides(Team $team, float|null $budgetPerDay, float|null $budgetPerMonth, int|null $rateLimit, array $selectedModels): array
    {
        $errors = [];

        if (null !== $budgetPerDay && null !== $team->rules->budgetPerDay && $budgetPerDay > $team->rules->budgetPerDay) {
            $errors[] = sprintf('Budget/day ($%.2f) exceeds team limit ($%.02f).', $budgetPerDay, $team->rules->budgetPerDay);
        }

        if (null !== $budgetPerMonth && null !== $team->rules->budgetPerMonth && $budgetPerMonth > $team->rules->budgetPerMonth) {
            $errors[] = sprintf('Budget/month ($%.2f) exceeds team limit ($%.02f).', $budgetPerMonth, $team->rules->budgetPerMonth);
        }

        if (null !== $rateLimit && null !== $team->rules->rateLimitPerMinute && $rateLimit > $team->rules->rateLimitPerMinute) {
            $errors[] = sprintf('Rate limit (%d req/min) exceeds team limit (%d req/min).', $rateLimit, $team->rules->rateLimitPerMinute);
        }

        if ([] !== $selectedModels && null !== $team->rules->models) {
            $invalid = array_diff($selectedModels, $team->rules->models);
            if ([] !== $invalid) {
                $errors[] = sprintf('Models not allowed by team: %s. Team allows: %s', implode(', ', $invalid), implode(', ', $team->rules->models));
            }
        }

        return $errors;
    }
}
