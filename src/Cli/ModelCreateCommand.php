<?php

declare(strict_types=1);

namespace AIGateway\Cli;

use AIGateway\Config\ConfigStore;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'model:create', description: 'Create a new model alias')]
final class ModelCreateCommand extends Command
{
    public function __construct(
        private readonly ConfigStore $configStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('alias', null, InputOption::VALUE_REQUIRED, 'Gateway-facing alias (e.g. gpt_4o)')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Provider name')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, "Provider's model ID (e.g. gpt-4o)")
            ->addOption('pricing-input', null, InputOption::VALUE_OPTIONAL, 'Price per million input tokens (USD)', 0.0)
            ->addOption('pricing-output', null, InputOption::VALUE_OPTIONAL, 'Price per million output tokens (USD)', 0.0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $alias = $input->getOption('alias');
        $provider = $input->getOption('provider');
        $model = $input->getOption('model');

        if (null === $alias || '' === $alias) {
            $io->error('The --alias option is required.');

            return Command::FAILURE;
        }

        if (null === $provider || '' === $provider) {
            $io->error('The --provider option is required.');

            return Command::FAILURE;
        }

        if (null === $model || '' === $model) {
            $io->error('The --model option is required.');

            return Command::FAILURE;
        }

        $providerExists = $this->configStore->getProvider($provider);
        if (null === $providerExists) {
            $io->warning(sprintf('Provider "%s" does not exist. Create it first with provider:create.', $provider));
        }

        $pricingInput = (float) ($input->getOption('pricing-input') ?? 0.0);
        $pricingOutput = (float) ($input->getOption('pricing-output') ?? 0.0);

        $this->configStore->saveModel(
            alias: $alias,
            providerName: $provider,
            model: $model,
            pricingInput: $pricingInput,
            pricingOutput: $pricingOutput,
        );

        $io->success(sprintf('Model "%s" created (→ %s / %s).', $alias, $provider, $model));

        return Command::SUCCESS;
    }
}
