<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use WapplerSystems\OauthService\Domain\Repository\ConnectionRepository;
use WapplerSystems\OauthService\Service\NotificationService;

#[AsCommand(
    name: 'oauth-service:monitor-connections',
    description: 'Prüft OAuth-Verbindungen auf ablaufende Tokens und versendet Warn-Emails.',
)]
final class ConnectionMonitorCommand extends Command
{
    public function __construct(
        private readonly ConnectionRepository $connectionRepository,
        private readonly NotificationService $notificationService,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription($this->translate('monitor.description'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $extConf = $this->extensionConfiguration->get('oauth_service') ?? [];

        $warningEmail    = trim((string)($extConf['warningEmail'] ?? ''));
        $thresholdDaysRaw = (string)($extConf['warningThresholdDays'] ?? '7,3,1');
        $debounceHours   = max(1, (int)($extConf['debounceHours'] ?? 20));

        if ($warningEmail === '') {
            $io->warning($this->translate('monitor.warning.noEmail'));
            return Command::SUCCESS;
        }

        $recipients = array_filter(array_map('trim', explode(',', $warningEmail)));

        $thresholdDays = array_filter(
            array_map('intval', explode(',', $thresholdDaysRaw)),
            static fn(int $d): bool => $d > 0
        );
        rsort($thresholdDays); // absteigend: 7, 3, 1

        if (empty($thresholdDays)) {
            $io->warning($this->translate('monitor.warning.noThresholds'));
            return Command::SUCCESS;
        }

        $maxThresholdSeconds = max($thresholdDays) * 86400;
        $connections = $this->connectionRepository->findExpiringWithinSeconds($maxThresholdSeconds);

        if (empty($connections)) {
            $io->success($this->translate('monitor.success.noExpiring'));
            return Command::SUCCESS;
        }

        $now             = time();
        $debounceSeconds = $debounceHours * 3600;
        $sentCount       = 0;

        foreach ($connections as $conn) {
            $expiresAt      = (int)($conn['access_token_expires_at'] ?? 0);
            $lastNotifiedAt = (int)($conn['last_notified_at'] ?? 0);
            $secondsLeft    = $expiresAt - $now;

            // Token bereits abgelaufen: Status auf expired setzen
            if ($secondsLeft <= 0) {
                $this->connectionRepository->updateFields((int)$conn['uid'], ['status' => 'expired']);
            }

            // Debounce: wurde in den letzten $debounceHours bereits eine Warnung gesendet?
            if ($lastNotifiedAt > 0 && ($now - $lastNotifiedAt) < $debounceSeconds) {
                $io->note($this->translate('monitor.note.debounced', [$conn['uid'], $conn['label'] ?? '']));
                continue;
            }

            // Passendes Threshold-Level bestimmen (höchste zutreffende Stufe)
            $matchedDays = null;
            foreach ($thresholdDays as $days) {
                if ($secondsLeft <= $days * 86400) {
                    $matchedDays = $days;
                    break;
                }
            }

            if ($matchedDays === null) {
                continue;
            }

            $daysLeft   = (int)ceil($secondsLeft / 86400);
            $expiresStr = date('d.m.Y H:i', $expiresAt);
            $label      = ($conn['label'] ?? '') ?: ('#' . $conn['uid']);
            $urgency    = $daysLeft <= 1
                ? $this->translate('monitor.urgency.critical')
                : $this->translate('monitor.urgency.warning');

            $subject = $this->translate('monitor.email.subject', [$urgency, $daysLeft, $label]);

            $body = implode("\n", [
                $this->translate('monitor.email.intro'),
                '',
                $this->translate('monitor.email.label.connection') . $label,
                $this->translate('monitor.email.label.status') . ($conn['status'] ?? ''),
                $this->translate('monitor.email.label.expiresAt') . $expiresStr,
                $this->translate('monitor.email.remaining', [$daysLeft, $secondsLeft]),
                '',
                $this->translate('monitor.email.hint'),
                $this->translate('monitor.email.path'),
            ]);

            foreach ($recipients as $recipient) {
                $this->notificationService->sendFailureMail($recipient, $subject, $body);
            }

            $this->connectionRepository->updateFields((int)$conn['uid'], ['last_notified_at' => $now]);

            $io->writeln($this->translate('monitor.output.warningSent', [
                implode(', ', $recipients),
                $label,
                $daysLeft,
            ]));

            $sentCount++;
        }

        $io->success($this->translate('monitor.success.summary', [$sentCount]));
        return Command::SUCCESS;
    }

    private function translate(string $key, array $arguments = []): string
    {
        return LocalizationUtility::translate(
            'LLL:EXT:oauth_service/Resources/Private/Language/locallang_cmd.xlf:' . $key,
            'OauthService',
            $arguments !== [] ? $arguments : null,
        ) ?? $key;
    }
}