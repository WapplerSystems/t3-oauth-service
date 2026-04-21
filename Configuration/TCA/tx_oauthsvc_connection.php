<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection',
        'label' => 'uid',
        'label_alt' => 'status,client',
        'label_alt_force' => true,
        'groupName' => 'system',
        'rootLevel' => -1,
        'security' => [
            'ignoreWebMountRestriction' => true,
            'ignoreRootLevelRestriction' => true,
        ],

        'tstamp' => 'updated_at',
        'crdate' => 'created_at',

        'typeicon_classes' => [
            'default' => 'mimetypes-x-sys_redirect',
        ],

        'searchFields' => 'status,last_error_code,last_error_message,remote_subject',
    ],

    'types' => [
        '1' => [
            'showitem' => '
                --div--;Connection,
                    client, label, status, remote_subject,
                --div--;Token,
                    access_token_expires_at, token_type, last_refresh_at,
                --div--;Monitoring,
                    last_check_at, last_error_code, last_error_message, last_notified_at,
                --div--;State,
                    state_hash, state_created_at, code_verifier,
                --div--;Internal,
                    access_token, refresh_token,
            ',
        ],
    ],

    'columns' => [
        'pid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],

        'client' => [
            'label' => 'Client',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_oauthsvc_client',
                'foreign_table_where' => 'ORDER BY tx_oauthsvc_client.title',
                'minitems' => 1,
                'maxitems' => 1,
            ],
        ],

        'status' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:status.disconnected', 'disconnected'],
                    ['LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:status.connected', 'connected'],
                    ['LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:status.expired', 'expired'],
                    ['LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:status.error', 'error'],
                ],
                'default' => 'disconnected',
            ],
        ],

        'remote_subject' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection.remoteSubject',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'eval' => 'trim',
            ],
        ],

        'state_hash' => [
            'label' => 'State hash',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'readOnly' => true,
            ],
        ],
        'state_created_at' => [
            'label' => 'State created',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],

        'access_token' => [
            'label' => 'Access token (encrypted)',
            'config' => [
                'type' => 'text',
                'size' => 20,
                'readOnly' => true,
            ],
        ],
        'refresh_token' => [
            'label' => 'Refresh token (encrypted)',
            'config' => [
                'type' => 'text',
                'size' => 20,
                'readOnly' => true,
            ],
        ],

        'token_type' => [
            'label' => 'Token type',
            'config' => [
                'type' => 'input',
                'size' => 20,
                'readOnly' => true,
            ],
        ],

        'access_token_expires_at' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection.expiresAt',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],

        'last_refresh_at' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection.lastRefreshAt',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],

        'last_check_at' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection.lastCheckAt',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],

        'last_error_code' => [
            'label' => 'Error code',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'readOnly' => true,
            ],
        ],

        'last_error_message' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connection.lastError',
            'config' => [
                'type' => 'text',
                'rows' => 5,
                'cols' => 80,
                'readOnly' => true,
            ],
        ],

        'code_verifier' => [
            'label' => 'Code verifier (encrypted, PKCE)',
            'config' => [
                'type' => 'text',
                'size' => 20,
                'readOnly' => true,
            ],
        ],

        'last_notified_at' => [
            'label' => 'Last notification',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],

        'created_at' => [
            'label' => 'Created',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'updated_at' => [
            'label' => 'Updated',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ],
];
