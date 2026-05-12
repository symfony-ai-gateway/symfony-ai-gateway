<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Store\KeyStoreInterface;

use function date;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'key:info', description: 'Show detailed info about an API key')]
final class KeyInfoCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Key ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        $key = $this->keyStore->findKeyById($id);

        if (null === $key) {
            $io->error(sprintf('Key "%s" not found.', $id));

            return Command::FAILURE;
        }

        $io->title(sprintf('API Key: %s', $key->name));
        $io->definitionList(
            ['ID' => $key->id],
            ['Prefix' => $key->tokenPrefix.'...'],
            ['Team' => $key->teamId ?? 'none'],
            ['Enabled' => $key->enabled ? 'yes' : 'no'],
            ['Expired' => $key->isExpired() ? 'yes' : 'no'],
            ['Expires' => null !== $key->expiresAt ? date('Y-m-d H:i:s', $key->expiresAt) : 'never'],
            ['Created' => date('Y-m-d H:i:s', $key->createdAt)],
        );

        if (null !== $key->overrides) {
            $io->section('Key Overrides');
            $io->definitionList(
                ['Budget/Day' => null !== $key->overrides->budgetPerDay ? sprintf('$%.2f', $key->overrides->budgetPerDay) : 'none'],
                ['Budget/Month' => null !== $key->overrides->budgetPerMonth ? sprintf('$%.2f', $key->overrides->budgetPerMonth) : 'none'],
                ['Rate Limit/min' => null !== $key->overrides->rateLimitPerMinute ? (string) $key->overrides->rateLimitPerMinute : 'none'],
                ['Models' => null !== $key->overrides->models ? implode(', ', $key->overrides->models) : 'all'],
            );
        }

        if (null !== $key->teamId) {
            $team = $this->keyStore->findTeamById($key->teamId);

            if (null !== $team) {
                $io->section(sprintf('Team: %s', $team->name));
                $io->definitionList(
                    ['ID' => $team->id],
                    ['Parent' => $team->parentId ?? 'root'],
                    ['Budget/Day' => null !== $team->rules->budgetPerDay ? sprintf('$%.2f', $team->rules->budgetPerDay) : 'none'],
                    ['Budget/Month' => null !== $team->rules->budgetPerMonth ? sprintf('$%.2f', $team->rules->budgetPerMonth) : 'none'],
                    ['Rate Limit/min' => null !== $team->rules->rateLimitPerMinute ? (string) $team->rules->rateLimitPerMinute : 'none'],
                    ['Models' => null !== $team->rules->models ? implode(', ', $team->rules->models) : 'all'],
                );
            }
        }

        $today = date('Y-m-d');
        $usage = $this->keyStore->getKeyUsage($key->id, $today, $today);
        $io->section('Usage Today');
        $io->definitionList(
            ['Requests' => (string) $usage->requests],
            ['Tokens' => (string) $usage->tokens],
            ['Cost' => sprintf('$%.6f', $usage->costUsd)],
        );

        return Command::SUCCESS;
    }
}
