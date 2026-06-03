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
    name: 'oauth-service:test-connection',
    description: 'Acquire a client_credentials token for the given provider and dump the decoded JWT payload (roles, aud, tid, expiry).',
)]
final class TestConnectionCommand extends Command
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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Invalidate the token cache first so a fresh token is fetched');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = (string)$input->getArgument('provider');

        if ((bool)$input->getOption('force')) {
            $this->tokenAcquisitionService->invalidate($provider);
            $io->note('Token cache invalidated.');
        }

        try {
            $token = $this->tokenAcquisitionService->getClientCredentialsToken($provider);
        } catch (\Throwable $e) {
            $io->error('Token acquisition failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($token === null || $token === '') {
            $io->error('No access token returned. Check that an active client for the provider exists and credentials are correct.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Access token acquired (%d chars).', strlen($token)));

        $segments = explode('.', $token);
        if (count($segments) < 2) {
            $io->warning('Token is not a JWT, cannot decode payload.');
            return Command::SUCCESS;
        }

        $padded = $segments[1] . str_repeat('=', (4 - strlen($segments[1]) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            $io->warning('Token JWT payload could not be base64-decoded.');
            return Command::SUCCESS;
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            $io->warning('Token JWT payload is not valid JSON.');
            return Command::SUCCESS;
        }

        $io->section('JWT payload');
        $io->definitionList(
            ['aud' => (string)($payload['aud'] ?? 'n/a')],
            ['iss' => (string)($payload['iss'] ?? 'n/a')],
            ['tid' => (string)($payload['tid'] ?? 'n/a')],
            ['appid' => (string)($payload['appid'] ?? 'n/a')],
            ['app_displayname' => (string)($payload['app_displayname'] ?? 'n/a')],
            ['exp' => isset($payload['exp']) ? date('Y-m-d H:i:s', (int)$payload['exp']) : 'n/a'],
            ['roles' => json_encode($payload['roles'] ?? [], JSON_UNESCAPED_SLASHES)],
            ['scp' => json_encode($payload['scp'] ?? null, JSON_UNESCAPED_SLASHES)],
        );

        if (empty($payload['roles'])) {
            $io->warning('Token carries no application roles. For Microsoft Graph this typically means the Mail.Send (Application) permission has not been admin-consented.');
        }

        return Command::SUCCESS;
    }
}
