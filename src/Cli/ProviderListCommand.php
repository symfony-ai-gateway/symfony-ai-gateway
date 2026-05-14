<?php

declare(strict_types=1);

namespace AIGateway\Cli;

use AIGateway\Config\ConfigStore;

use function array_map;
use function count;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'provider:list', description: 'List all configured providers')]
final class ProviderListCommand extends Command
{
    public function __construct(
        private readonly ConfigStore $configStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $providers = $this->configStore->listProviders();

        if ([] === $providers) {
            $io->note('No providers configured.');

            return Command::SUCCESS;
        }

        $io->title('Providers');

        $io->table(
            ['Name', 'Format', 'Base URL', 'Completions Path', 'Enabled'],
            array_map(static fn (array $p): array => [
                $p['name'],
                $p['format'],
                $p['base_url'] ?? '(default)',
                $p['completions_path'],
                $p['enabled'] ? 'yes' : 'no',
            ], $providers),
        );

        $io->note(sprintf('Total: %d provider(s)', count($providers)));

        return Command::SUCCESS;
    }
}
