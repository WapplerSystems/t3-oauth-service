<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Looks up the scheduler task that runs a given Symfony command (by its
 * command identifier) and returns a normalized status the backend module
 * can show in a callout.
 *
 * Used by the OAuth backend module to warn administrators when the
 * connection-monitor command is missing, disabled, failing or stale.
 *
 * Return format from getStatus():
 *   [
 *     'state'       => 'ok' | 'notConfigured' | 'disabled' | 'failed' | 'stale',
 *     'lastRun'     => int,    // timestamp of last execution (0 if never)
 *     'lastFailure' => string, // last execution failure message ('' if none)
 *   ]
 */
final class MonitorTaskStatusService
{
    /**
     * If the task hasn't run for this many seconds and isn't actively failing,
     * we consider it "stale". 48h gives enough head-room for daily schedules.
     */
    private const STALE_AFTER_SECONDS = 48 * 3600;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{state: string, lastRun: int, lastFailure: string}
     */
    public function getStatus(string $commandIdentifier): array
    {
        $row = $this->findTaskRow($commandIdentifier);

        if ($row === null) {
            return ['state' => 'notConfigured', 'lastRun' => 0, 'lastFailure' => ''];
        }

        $lastRun = (int)($row['lastexecution_time'] ?? 0);
        $lastFailure = (string)($row['lastexecution_failure'] ?? '');

        if ((int)($row['disable'] ?? 0) === 1) {
            return ['state' => 'disabled', 'lastRun' => $lastRun, 'lastFailure' => $lastFailure];
        }

        if ($lastFailure !== '') {
            return ['state' => 'failed', 'lastRun' => $lastRun, 'lastFailure' => $lastFailure];
        }

        if ($lastRun === 0 || (time() - $lastRun) > self::STALE_AFTER_SECONDS) {
            return ['state' => 'stale', 'lastRun' => $lastRun, 'lastFailure' => ''];
        }

        return ['state' => 'ok', 'lastRun' => $lastRun, 'lastFailure' => ''];
    }

    /**
     * Returns the first non-deleted scheduler row matching the command,
     * either by tasktype (TYPO3 v14 stores the command identifier there
     * directly for Symfony-command tasks) or by parameters JSON (legacy
     * pre-v14 format and a safety net).
     *
     * @return array<string, mixed>|null
     */
    private function findTaskRow(string $commandIdentifier): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tx_scheduler_task');
        $qb->getRestrictions()->removeAll();
        $row = $qb
            ->select('uid', 'disable', 'lastexecution_time', 'lastexecution_failure')
            ->from('tx_scheduler_task')
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->or(
                    $qb->expr()->eq('tasktype', $qb->createNamedParameter($commandIdentifier)),
                    $qb->expr()->like('parameters', $qb->createNamedParameter('%"commandIdentifier":"' . $commandIdentifier . '"%')),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }
}
