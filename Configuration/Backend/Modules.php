<?php
return [
    'tools_oauthservice' => [
        'parent' => 'tools',
        'position' => ['after' => 'tools_ExtensionmanagerExtensionmanager'],
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'actions-key',
        'path' => '/module/tools/oauthservice',
        'labels' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'OauthService',
        'controllerActions' => [
            \WapplerSystems\OauthService\Backend\Controller\OAuthModuleController::class => [
                'index', 'connect', 'reconnect', 'disconnect'
            ],
        ],
    ],
];
