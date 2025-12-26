<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Scheduler;

use TYPO3\CMS\Scheduler\Task\AbstractTask;
use WapplerSystems\OauthService\Service\MonitoringService;

final class OAuthMonitorTask extends AbstractTask
{
    public function __construct(
        private readonly MonitoringService $monitoringService,
        int                                $taskUid = 0
    )
    {
        parent::__construct($taskUid);
    }

    public function execute(): bool
    {
        $this->monitoringService->run();
        return true;
    }
}
