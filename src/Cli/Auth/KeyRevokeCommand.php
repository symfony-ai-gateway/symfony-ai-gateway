<?php

declare(strict_types=1);

namespace AIGateway\Cli\Auth;

use AIGateway\Auth\Entity\ApiKey;
use AIGateway\Auth\Store\KeyStoreInterface;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'key:revoke', description: 'Revoke (disable) an API key')]
final class KeyRevokeCommand extends Command
{
    public function __construct(
        private readonly KeyStoreInterface $keyStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Key ID to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = $input->getArgument('id');

        $existing = $this->keyStore->findKeyById($id);

        if (null === $existing) {
            $io->error(sprintf('Key "%s" not found.', $id));

            return Command::FAILURE;
        }

        $disabled = new ApiKey(
            id: $existing->id,
            name: $existing->name,
            keyHash: $existing->keyHash,
            tokenPrefix: $existing->tokenPrefix,
            teamId: $existing->teamId,
            overrides: $existing->overrides,
            enabled: false,
            expiresAt: $existing->expiresAt,
            createdAt: $existing->createdAt,
        );

        $this->keyStore->saveKey($disabled);

        $io->success(sprintf('Key "%s" (%s) has been revoked.', $existing->name, substr($id, 0, 12).'...'));

        return Command::SUCCESS;
    }
}
