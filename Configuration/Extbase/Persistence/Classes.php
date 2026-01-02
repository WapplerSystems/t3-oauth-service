<?php
declare(strict_types=1);

return [
    \WapplerSystems\OauthService\Domain\Model\Client::class => [
        'tableName' => 'tx_oauthsvc_client',
    ],
    \WapplerSystems\OauthService\Domain\Model\Connection::class => [
        'tableName' => 'tx_oauthsvc_connection',
    ],
];
