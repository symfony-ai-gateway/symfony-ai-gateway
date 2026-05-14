<?php

declare(strict_types=1);

namespace AIGateway\Cli;

use AIGateway\Config\ConfigStore;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'provider:delete', description: 'Delete a provider and its models')]
final class ProviderDeleteCommand extends Command
{
    public function __construct(
        private readonly ConfigStore $configStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Provider name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        $existing = $this->configStore->getProvider($name);
        if (null === $existing) {
            $io->error(sprintf('Provider "%s" not found.', $name));

            return Command::FAILURE;
        }

        if (!$io->confirm(sprintf('Delete provider "%s" and all its models?', $name), false)) {
            $io->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->configStore->deleteProvider($name);
        $io->success(sprintf('Provider "%s" deleted.', $name));

        return Command::SUCCESS;
    }
}
