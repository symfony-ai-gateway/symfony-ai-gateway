<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Store\KeyStoreInterface;

use function array_map;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'team:list', description: 'List all teams')]
final class TeamListCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $teams = $this->keyStore->listTeams();

        if ([] === $teams) {
            $io->note('No teams found.');

            return Command::SUCCESS;
        }

        $io->title('Teams');
        $io->table(
            ['ID', 'Name', 'Parent', 'Budget/Day', 'Budget/Month', 'Rate Limit', 'Models'],
            array_map(static fn ($team): array => [
                substr($team->id, 0, 12).'...',
                $team->name,
                $team->parentId ?? 'root',
                null !== $team->rules->budgetPerDay ? sprintf('$%.2f', $team->rules->budgetPerDay) : 'none',
                null !== $team->rules->budgetPerMonth ? sprintf('$%.2f', $team->rules->budgetPerMonth) : 'none',
                null !== $team->rules->rateLimitPerMinute ? (string) $team->rules->rateLimitPerMinute : 'none',
                null !== $team->rules->models ? implode(', ', $team->rules->models) : 'all',
            ], $teams),
        );

        return Command::SUCCESS;
    }
}
