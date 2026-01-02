<?php
return [
    'oauthsvc_callback' => [
        'path' => '/oauthservice/callback',
        'target' => \WapplerSystems\OauthService\Backend\Controller\OAuthCallbackController::class . '::callback',
        'access' => 'public',
    ],
];
