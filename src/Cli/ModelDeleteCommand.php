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

#[AsCommand(name: 'model:delete', description: 'Delete a model alias')]
final class ModelDeleteCommand extends Command
{
    public function __construct(
        private readonly ConfigStore $configStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('alias', InputArgument::REQUIRED, 'Model alias to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $alias = $input->getArgument('alias');

        $existing = $this->configStore->getModel($alias);
        if (null === $existing) {
            $io->error(sprintf('Model "%s" not found.', $alias));

            return Command::FAILURE;
        }

        if (!$io->confirm(sprintf('Delete model "%s"?', $alias), false)) {
            $io->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->configStore->deleteModel($alias);
        $io->success(sprintf('Model "%s" deleted.', $alias));

        return Command::SUCCESS;
    }
}
