<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $extConf = $this->extensionConfiguration->get('oauth_service') ?? [];

        $warningEmail    = trim((string)($extConf['warningEmail'] ?? ''));
        $thresholdDaysRaw = (string)($extConf['warningThresholdDays'] ?? '7,3,1');
        $debounceHours   = max(1, (int)($extConf['debounceHours'] ?? 20));

        if ($warningEmail === '') {
            $io->warning('Keine warningEmail in den Extension-Einstellungen konfiguriert. Abbruch.');
            return Command::SUCCESS;
        }

        $recipients = array_filter(array_map('trim', explode(',', $warningEmail)));

        $thresholdDays = array_filter(
            array_map('intval', explode(',', $thresholdDaysRaw)),
            static fn(int $d): bool => $d > 0
        );
        rsort($thresholdDays); // absteigend: 7, 3, 1

        if (empty($thresholdDays)) {
            $io->warning('Keine gültigen warningThresholdDays konfiguriert.');
            return Command::SUCCESS;
        }

        $maxThresholdSeconds = max($thresholdDays) * 86400;
        $connections = $this->connectionRepository->findExpiringWithinSeconds($maxThresholdSeconds);

        if (empty($connections)) {
            $io->success('Keine ablaufenden Verbindungen gefunden.');
            return Command::SUCCESS;
        }

        $now            = time();
        $debounceSeconds = $debounceHours * 3600;
        $sentCount      = 0;

        foreach ($connections as $conn) {
            $expiresAt      = (int)($conn['access_token_expires_at'] ?? 0);
            $lastNotifiedAt = (int)($conn['last_notified_at'] ?? 0);
            $secondsLeft    = $expiresAt - $now;


            // Debounce: wurde in den letzten $debounceHours bereits eine Warnung gesendet?
            if ($lastNotifiedAt > 0 && ($now - $lastNotifiedAt) < $debounceSeconds) {
                $io->note(sprintf(
                    'Verbindung #%d (%s): Warnung kürzlich gesendet, übersprungen.',
                    $conn['uid'],
                    $conn['label'] ?? ''
                ));
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
            $urgency    = $daysLeft <= 1 ? 'DRINGEND' : 'Warnung';

            $subject = sprintf(
                '[OAuth] %s: Token läuft in %d Tag(en) ab – %s',
                $urgency,
                $daysLeft,
                $label
            );

            $body = implode("\n", [
                'Eine OAuth-Verbindung läuft demnächst ab.',
                '',
                'Verbindung:   ' . $label,
                'Status:       ' . ($conn['status'] ?? ''),
                'Läuft ab am:  ' . $expiresStr,
                'Verbleibend:  ' . $daysLeft . ' Tag(e) (' . $secondsLeft . ' Sekunden)',
                '',
                'Bitte erneuern Sie die Verbindung im TYPO3-Backend unter',
                'System > OAuth-Verbindungen.',
            ]);

            foreach ($recipients as $recipient) {
                $this->notificationService->sendFailureMail($recipient, $subject, $body);
            }

            $this->connectionRepository->update((int)$conn['uid'], ['last_notified_at' => $now]);

            $io->writeln(sprintf(
                '  → Warnung gesendet an %s: Verbindung "%s" läuft in %d Tag(en) ab.',
                implode(', ', $recipients),
                $label,
                $daysLeft
            ));

            $sentCount++;
        }

        $io->success(sprintf('%d Warn-Email(s) versendet.', $sentCount));
        return Command::SUCCESS;
    }
}
