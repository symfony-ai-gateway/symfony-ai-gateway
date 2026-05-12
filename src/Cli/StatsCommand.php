<?php

declare(strict_types=1);

namespace AIGateway\Cli;

use AIGateway\Cost\CostReporter;
use AIGateway\Logging\RequestLogger;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'stats', description: 'Show gateway usage statistics')]
final class StatsCommand extends Command
{
    public function __construct(
        private readonly RequestLogger $requestLogger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $logs = $this->requestLogger->getLogs();

        $io->title('AIGateway Statistics');

        $io->section('Overview');
        $io->definitionList(
            ['Total Requests' => (string) $this->requestLogger->getTotalRequests()],
            ['Total Errors' => (string) $this->requestLogger->getTotalErrors()],
            ['Avg Duration' => sprintf('%.1f ms', $this->requestLogger->getAverageDurationMs())],
        );

        $byProvider = CostReporter::byProvider($logs);
        if ([] !== $byProvider) {
            $io->section('Cost by Provider');
            $io->table(
                ['Provider', 'Requests', 'Tokens', 'Cost (USD)'],
                array_map(static fn (array $r): array => [
                    $r['provider'],
                    (string) $r['requests'],
                    (string) $r['tokens'],
                    sprintf('$%.6f', $r['cost']),
                ], $byProvider),
            );
        }

        $byModel = CostReporter::byModel($logs);
        if ([] !== $byModel) {
            $io->section('Cost by Model');
            $io->table(
                ['Model', 'Requests', 'Tokens', 'Cost (USD)', 'Avg ms'],
                array_map(static fn (array $r): array => [
                    $r['model'],
                    (string) $r['requests'],
                    (string) $r['tokens'],
                    sprintf('$%.6f', $r['cost']),
                    sprintf('%.1f', $r['avg_ms']),
                ], $byModel),
            );
        }

        return Command::SUCCESS;
    }
}
