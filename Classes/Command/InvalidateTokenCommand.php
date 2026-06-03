<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsCommand(
    name: 'oauth-service:invalidate',
    description: 'Drop the cached client_credentials token for the given provider so the next request fetches a fresh one.',
)]
final class InvalidateTokenCommand extends Command
{
    public function __construct(
        private readonly TokenAcquisitionService $tokenAcquisitionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::REQUIRED, 'Provider identifier (e.g. microsoft_graph)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = (string)$input->getArgument('provider');

        $this->tokenAcquisitionService->invalidate($provider);
        $io->success(sprintf('Cached token for provider "%s" cleared.', $provider));
        return Command::SUCCESS;
    }
}
