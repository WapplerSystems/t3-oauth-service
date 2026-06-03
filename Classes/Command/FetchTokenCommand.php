<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'oauth-service:fetch-token',
    description: 'Acquire a client_credentials token for the given provider (uses cache unless --force).',
)]
final class FetchTokenCommand extends Command
{
    public function __construct(
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Provider identifier (e.g. microsoft_graph)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Invalidate the cache first so a fresh token is fetched');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = (string)$input->getArgument('provider');

        if ((bool)$input->getOption('force')) {
            $this->tokenAcquisitionService->invalidate($provider);
        }

        try {
            $token = $this->tokenAcquisitionService->getClientCredentialsToken($provider);
        } catch (\Throwable $e) {
            $io->error('Token acquisition failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($token === null || $token === '') {
            $io->error('No access token returned.');
            return Command::FAILURE;
        }

        $status = $this->tokenAcquisitionService->getCachedTokenStatus($provider);
        $expiresAt = $status['expiresAt'] ?? null;
        $io->success(sprintf(
            'Token cached for provider "%s"%s.',
            $provider,
            $expiresAt !== null ? ' (valid until ' . date('Y-m-d H:i:s', $expiresAt) . ')' : ''
        ));
        return Command::SUCCESS;
    }
}
