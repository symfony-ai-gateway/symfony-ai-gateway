<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Store\KeyStoreInterface;

use function array_map;
use function count;
use function date;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'key:list', description: 'List all API keys')]
final class KeyListCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keys = $this->keyStore->listKeys();

        if ([] === $keys) {
            $io->note('No API keys found.');

            return Command::SUCCESS;
        }

        $teams = $this->keyStore->listTeams();
        $teamNames = [];
        foreach ($teams as $team) {
            $teamNames[$team->id] = $team->name;
        }

        $io->title('API Keys');
        $io->table(
            ['ID', 'Name', 'Prefix', 'Team', 'Enabled', 'Expires', 'Created'],
            array_map(static fn ($key): array => [
                substr($key->id, 0, 12).'...',
                $key->name,
                $key->tokenPrefix.'...',
                null !== $key->teamId && isset($teamNames[$key->teamId])
                    ? $teamNames[$key->teamId]
                    : ($key->teamId ?? 'none'),
                $key->enabled ? 'yes' : 'no',
                null !== $key->expiresAt ? date('Y-m-d', $key->expiresAt) : 'never',
                date('Y-m-d H:i', $key->createdAt),
            ], $keys),
        );

        $io->note(sprintf('Total: %d key(s)', count($keys)));

        return Command::SUCCESS;
    }
}
