<?php

declare(strict_types=1);

namespace AIGateway\Standalone\Command;

use const PHP_BINARY;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'serve', description: 'Start the AIGateway standalone server')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to listen on', '8080')
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'Host to bind to', '0.0.0.0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $port = (int) $input->getOption('port');
        $host = (string) $input->getOption('host');

        $io->title('AIGateway Standalone Server');
        $io->text(sprintf('Starting server on http://%s:%d', $host, $port));
        $io->text('Press Ctrl+C to stop');
        $io->newLine();

        passthru(sprintf(
            '%s -S %s:%d %s',
            PHP_BINARY,
            $host,
            $port,
            escapeshellarg(__DIR__.'/../../public/index.php'),
        ));

        return Command::SUCCESS;
    }
}
