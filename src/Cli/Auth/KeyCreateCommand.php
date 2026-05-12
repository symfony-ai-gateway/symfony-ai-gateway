<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Entity\KeyRules;
use AIGateway\Auth\Store\KeyStoreInterface;

use function bin2hex;
use function explode;
use function hash;
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

#[AsCommand(name: 'key:create', description: 'Create a new API key')]
final class KeyCreateCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human-readable key name')
            ->addOption('team', null, InputOption::VALUE_OPTIONAL, 'Team ID to assign')
            ->addOption('budget-per-day', null, InputOption::VALUE_OPTIONAL, 'Daily budget limit (USD)')
            ->addOption('budget-per-month', null, InputOption::VALUE_OPTIONAL, 'Monthly budget limit (USD)')
            ->addOption('rate-limit', null, InputOption::VALUE_OPTIONAL, 'Rate limit per minute')
            ->addOption('models', null, InputOption::VALUE_OPTIONAL, 'Comma-separated model whitelist')
            ->addOption('expires', null, InputOption::VALUE_OPTIONAL, 'Expiration timestamp (unix)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getOption('name');

        if (null === $name || '' === $name) {
            $io->error('The --name option is required.');

            return Command::FAILURE;
        }

        $rawToken = 'aig_'.bin2hex(random_bytes(24));
        $keyHash = hash('sha256', $rawToken);
        $prefix = substr($rawToken, 0, 8);
        $id = bin2hex(random_bytes(16));

        $overrides = $this->buildOverrides($input);

        $teamId = $input->getOption('team');
        $expiresOption = $input->getOption('expires');
        $expiresAt = null !== $expiresOption && '' !== $expiresOption ? (int) $expiresOption : null;

        $apiKey = new ApiKey(
            id: $id,
            name: $name,
            keyHash: $keyHash,
            tokenPrefix: $prefix,
            teamId: $teamId,
            overrides: $overrides,
            enabled: true,
            expiresAt: $expiresAt,
            createdAt: time(),
        );

        $this->keyStore->saveKey($apiKey);

        $io->title('API Key Created');
        $io->writeln(sprintf('<info>%s</info>', $rawToken));
        $io->warning('Copy this key now. It will NOT be shown again.');
        $io->definitionList(
            ['ID' => $id],
            ['Name' => $name],
            ['Prefix' => $prefix],
            ['Team' => $teamId ?? 'none'],
            ['Expires' => null !== $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : 'never'],
        );

        return Command::SUCCESS;
    }

    private function buildOverrides(InputInterface $input): KeyRules|null
    {
        $budgetDay = $input->getOption('budget-per-day');
        $budgetMonth = $input->getOption('budget-per-month');
        $rateLimit = $input->getOption('rate-limit');
        $modelsStr = $input->getOption('models');

        $budgetPerDay = null !== $budgetDay && '' !== $budgetDay ? (float) $budgetDay : null;
        $budgetPerMonth = null !== $budgetMonth && '' !== $budgetMonth ? (float) $budgetMonth : null;
        $rateLimitPerMinute = null !== $rateLimit && '' !== $rateLimit ? (int) $rateLimit : null;
        $models = null !== $modelsStr && '' !== $modelsStr
            ? array_map(trim(...), explode(',', $modelsStr))
            : null;

        if (null === $budgetPerDay && null === $budgetPerMonth && null === $rateLimitPerMinute && null === $models) {
            return null;
        }

        return new KeyRules(
            budgetPerDay: $budgetPerDay,
            budgetPerMonth: $budgetPerMonth,
            rateLimitPerMinute: $rateLimitPerMinute,
            models: $models,
        );
    }
}
