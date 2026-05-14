<?php

declare(strict_types=1);

namespace AIGateway\Cli;

use AIGateway\Config\ConfigStore;

use function implode;
use function in_array;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'provider:create', description: 'Create a new LLM provider')]
final class ProviderCreateCommand extends Command
{
    public function __construct(
        private readonly ConfigStore $configStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Unique provider name (e.g. openai)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Provider format: openai, anthropic, gemini, ollama, azure')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for the provider')
            ->addOption('base-url', null, InputOption::VALUE_OPTIONAL, 'Custom base URL (leave empty for provider default)')
            ->addOption('completions-path', null, InputOption::VALUE_OPTIONAL, 'Completions endpoint path', '/v1/chat/completions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getOption('name');
        $format = $input->getOption('format');

        if (null === $name || '' === $name) {
            $io->error('The --name option is required.');

            return Command::FAILURE;
        }

        $allowedFormats = ['openai', 'anthropic', 'gemini', 'ollama', 'azure'];
        if (!in_array($format, $allowedFormats, true)) {
            $io->error(sprintf('Invalid format "%s". Allowed: %s', $format, implode(', ', $allowedFormats)));

            return Command::FAILURE;
        }

        $apiKey = $input->getOption('api-key');
        if (null === $apiKey || '' === $apiKey) {
            $io->error('The --api-key option is required.');

            return Command::FAILURE;
        }

        $baseUrl = $input->getOption('base-url');
        $completionsPath = $input->getOption('completions-path');

        $this->configStore->saveProvider(
            name: $name,
            format: $format,
            apiKey: $apiKey,
            baseUrl: '' !== $baseUrl ? $baseUrl : null,
            completionsPath: $completionsPath ?? '/v1/chat/completions',
        );

        $io->success(sprintf('Provider "%s" created (%s format).', $name, $format));

        return Command::SUCCESS;
    }
}
