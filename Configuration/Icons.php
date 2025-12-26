<?php

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'oauthservice-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:oauth_service/Resources/Public/Icons/Extension.svg',
        'spinning' => false,
    ],
];
