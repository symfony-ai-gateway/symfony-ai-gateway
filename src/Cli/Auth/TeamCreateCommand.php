<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Entity\Team;
use AIGateway\Auth\Store\KeyStoreInterface;

use function bin2hex;
use function explode;
use function random_bytes;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function time;
use function trim;

#[AsCommand(name: 'team:create', description: 'Create a new team')]
final class TeamCreateCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Team name')
            ->addOption('parent', null, InputOption::VALUE_OPTIONAL, 'Parent team ID')
            ->addOption('budget-per-day', null, InputOption::VALUE_OPTIONAL, 'Daily budget limit (USD)')
            ->addOption('budget-per-month', null, InputOption::VALUE_OPTIONAL, 'Monthly budget limit (USD)')
            ->addOption('rate-limit', null, InputOption::VALUE_OPTIONAL, 'Rate limit per minute')
            ->addOption('models', null, InputOption::VALUE_OPTIONAL, 'Comma-separated model whitelist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getOption('name');

        if (null === $name || '' === $name) {
            $io->error('The --name option is required.');

            return Command::FAILURE;
        }

        $id = bin2hex(random_bytes(16));
        $parentId = $input->getOption('parent');

        $rules = $this->buildRules($input);

        $team = new Team(
            id: $id,
            name: $name,
            parentId: $parentId,
            rules: $rules,
            createdAt: time(),
        );

        $this->keyStore->saveTeam($team);

        $io->success(sprintf('Team "%s" created (ID: %s)', $name, $id));

        return Command::SUCCESS;
    }

    private function buildRules(InputInterface $input): KeyRules
    {
        $budgetDay = $input->getOption('budget-per-day');
        $budgetMonth = $input->getOption('budget-per-month');
        $rateLimit = $input->getOption('rate-limit');
        $modelsStr = $input->getOption('models');

        return new KeyRules(
            budgetPerDay: null !== $budgetDay && '' !== $budgetDay ? (float) $budgetDay : null,
            budgetPerMonth: null !== $budgetMonth && '' !== $budgetMonth ? (float) $budgetMonth : null,
            rateLimitPerMinute: null !== $rateLimit && '' !== $rateLimit ? (int) $rateLimit : null,
            models: null !== $modelsStr && '' !== $modelsStr
                ? array_map(trim(...), explode(',', $modelsStr))
                : null,
        );
    }
}
