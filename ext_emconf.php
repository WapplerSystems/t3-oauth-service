<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'OAuth Services for TYPO3',
    'description' => 'Central OAuth2 client and token management for TYPO3 — Authorization Code Flow with PKCE, encrypted token storage, automatic refresh and expiry monitoring',
    'category' => 'plugin',
    'author' => 'Sven Wappler',
    'author_email' => 'typo3YYYY@wappler.systems',
    'author_company' => 'WapplerSystems',
    'state' => 'stable',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'clearCacheOnLoad' => 0,
    'version' => '14.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
