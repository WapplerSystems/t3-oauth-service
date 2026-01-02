<?php


declare(strict_types=1);

return [
    'backend' => [
        'oauthservice/callback' => [
            'target' => \WapplerSystems\OauthService\Middleware\OauthCallbackMiddleware::class,
            'before' => [
                'typo3/cms-backend/locked-backend',
            ],
        ],
    ],
];
