<?php

declare(strict_types=1);

namespace AIGateway\Controller;

use AIGateway\Auth\Store\KeyStoreInterface;
use AIGateway\Logging\RequestLogger;

use function count;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class DashboardController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly KeyStoreInterface|null $keyStore = null,
        private readonly RequestLogger|null $requestLogger = null,
    ) {
    }

    #[Route('/dashboard', name: 'ai_gateway_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        $keys = $this->keyStore?->listKeys() ?? [];
        $teams = $this->keyStore?->listTeams() ?? [];
        $activeKeys = count(array_filter($keys, static fn ($k): bool => $k->enabled));

        $totalCost = 0.0;
        $totalTokens = 0;

        if (null !== $this->requestLogger) {
            foreach ($this->requestLogger->getLogs() as $log) {
                $totalCost += $log->costUsd;
                $totalTokens += $log->totalTokens;
            }
        }

        return new Response($this->twig->render('@AIGateway/dashboard/index.html.twig', [
            'total_keys' => count($keys),
            'active_keys' => $activeKeys,
            'total_teams' => count($teams),
            'total_requests' => $this->requestLogger?->getTotalRequests() ?? 0,
            'total_errors' => $this->requestLogger?->getTotalErrors() ?? 0,
            'total_cost' => $totalCost,
            'total_tokens' => $totalTokens,
            'avg_duration' => $this->requestLogger?->getAverageDurationMs() ?? 0.0,
        ]));
    }

    #[Route('/dashboard/keys', name: 'ai_gateway_dashboard_keys', methods: ['GET'])]
    public function keys(): Response
    {
        $keys = $this->keyStore?->listKeys() ?? [];
        $teams = $this->keyStore?->listTeams() ?? [];

        $teamNames = [];
        foreach ($teams as $team) {
            $teamNames[$team->id] = $team->name;
        }

        return new Response($this->twig->render('@AIGateway/dashboard/keys.html.twig', [
            'keys' => $keys,
            'team_names' => $teamNames,
        ]));
    }

    #[Route('/dashboard/keys/{id}', name: 'ai_gateway_dashboard_key_detail', methods: ['GET'])]
    public function keyDetail(string $id): Response
    {
        $key = $this->keyStore?->findKeyById($id);

        if (null === $key) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', [
                'message' => 'API key not found.',
            ]), 404);
        }

        $team = null !== $key->teamId ? $this->keyStore?->findTeamById($key->teamId) : null;
        $today = date('Y-m-d');
        $usage = $this->keyStore?->getKeyUsage($key->id, $today, $today) ?? null;

        return new Response($this->twig->render('@AIGateway/dashboard/keys_detail.html.twig', [
            'key' => $key,
            'team' => $team,
            'usage_today' => $usage,
        ]));
    }

    #[Route('/dashboard/teams', name: 'ai_gateway_dashboard_teams', methods: ['GET'])]
    public function teams(): Response
    {
        $teams = $this->keyStore?->listTeams() ?? [];
        $keys = $this->keyStore?->listKeys() ?? [];

        $teamKeyCounts = [];
        foreach ($keys as $key) {
            if (null !== $key->teamId) {
                $teamKeyCounts[$key->teamId] = ($teamKeyCounts[$key->teamId] ?? 0) + 1;
            }
        }

        return new Response($this->twig->render('@AIGateway/dashboard/teams.html.twig', [
            'teams' => $teams,
            'team_key_counts' => $teamKeyCounts,
        ]));
    }

    #[Route('/dashboard/teams/{id}', name: 'ai_gateway_dashboard_team_detail', methods: ['GET'])]
    public function teamDetail(string $id): Response
    {
        $team = $this->keyStore?->findTeamById($id);

        if (null === $team) {
            return new Response($this->twig->render('@AIGateway/dashboard/error.html.twig', [
                'message' => 'Team not found.',
            ]), 404);
        }

        $ancestry = $this->keyStore?->findTeamAncestry($id) ?? [];
        $keys = $this->keyStore?->listKeys() ?? [];
        $teamKeys = array_filter($keys, static fn ($k): bool => $k->teamId === $id);

        $parentTeam = null !== $team->parentId ? $this->keyStore?->findTeamById($team->parentId) : null;

        return new Response($this->twig->render('@AIGateway/dashboard/teams_detail.html.twig', [
            'team' => $team,
            'parent_team' => $parentTeam,
            'ancestry' => $ancestry,
            'team_keys' => $teamKeys,
        ]));
    }

    #[Route('/dashboard/analytics', name: 'ai_gateway_dashboard_analytics', methods: ['GET'])]
    public function analytics(): Response
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

        return new Response($this->twig->render('@AIGateway/dashboard/analytics.html.twig', [
            'logs' => $logs,
            'by_provider' => $byProvider,
            'by_model' => $byModel,
            'total_logs' => count($logs),
        ]));
    }
}
