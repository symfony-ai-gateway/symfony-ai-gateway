<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Store\KeyStoreInterface;

use function array_map;
use function count;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'team:info', description: 'Show detailed info about a team')]
final class TeamInfoCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Team ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        $team = $this->keyStore->findTeamById($id);

        if (null === $team) {
            $io->error(sprintf('Team "%s" not found.', $id));

            return Command::FAILURE;
        }

        $io->title(sprintf('Team: %s', $team->name));
        $io->definitionList(
            ['ID' => $team->id],
            ['Parent' => $team->parentId ?? 'root'],
            ['Created' => date('Y-m-d H:i:s', $team->createdAt)],
            ['Budget/Day' => null !== $team->rules->budgetPerDay ? sprintf('$%.2f', $team->rules->budgetPerDay) : 'none'],
            ['Budget/Month' => null !== $team->rules->budgetPerMonth ? sprintf('$%.2f', $team->rules->budgetPerMonth) : 'none'],
            ['Rate Limit/min' => null !== $team->rules->rateLimitPerMinute ? (string) $team->rules->rateLimitPerMinute : 'none'],
            ['Models' => null !== $team->rules->models ? implode(', ', $team->rules->models) : 'all'],
        );

        $ancestry = $this->keyStore->findTeamAncestry($id);

        if (count($ancestry) > 1) {
            $io->section('Ancestry (root → this team)');
            $io->listing(array_map(
                static fn (int $i, $t) => sprintf('%d. %s', $i + 1, $t->name),
                array_keys($ancestry),
                $ancestry,
            ));
        }

        $keys = $this->keyStore->listKeys();
        $teamKeys = array_filter($keys, static fn ($k): bool => $k->teamId === $id);

        if ([] !== $teamKeys) {
            $io->section(sprintf('Keys in this team (%d)', count($teamKeys)));
            $io->listing(array_map(
                static fn ($k): string => sprintf('%s (%s) - %s', $k->name, $k->tokenPrefix.'...', $k->enabled ? 'active' : 'revoked'),
                $teamKeys,
            ));
        }

        return Command::SUCCESS;
    }
}
