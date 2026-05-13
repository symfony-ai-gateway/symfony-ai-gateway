<?php

declare(strict_types=1);

namespace AIGateway\Cli;

use AIGateway\Config\ModelRegistry;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'model:list', description: 'List all configured models')]
final class ModelListCommand extends Command
{
    public function __construct(
        private readonly ModelRegistry $modelRegistry,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $models = $this->modelRegistry->getAvailableModels();

        if ([] === $models) {
            $io->warning('No models configured.');

            return Command::SUCCESS;
        }

        $io->title('Configured Models');

        $rows = [];
        foreach ($models as $alias) {
            $resolution = $this->modelRegistry->resolve($alias);
            $rows[] = [
                $alias,
                $resolution->provider,
                $resolution->model,
                sprintf('$%.2f / $%.2f per 1M tok', $resolution->pricing->inputPerMillion, $resolution->pricing->outputPerMillion),
                (string) $resolution->maxTokens,
            ];
        }

        $io->table(['Alias', 'Provider', 'Model', 'Pricing (in/out)', 'Max Tokens'], $rows);

        return Command::SUCCESS;
    }
}
