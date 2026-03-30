<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:clients',
        'label' => 'uid',
        'label_alt' => 'provider',
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

        'searchFields' => 'provider,notify_email,scopes',
        'default_sortby' => 'ORDER BY title',
    ],

    'types' => [
        '1' => [
            'showitem' => '
                --div--;Client,
                    provider, is_active,
                --div--;Credentials,
                    client_id, client_secret,
                --div--;Scopes,
                    scopes,
                --div--;Monitoring,
                    notify_email,
                --div--;Connections,
                    connections,
            ',
        ],
    ],

    'palettes' => [
        'general' => [
            'showitem' => 'title, provider, is_active',
        ],
    ],

    'columns' => [
        'pid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],

        'provider' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:client.providerType',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim,required',
            ],
        ],

        'is_active' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:client.isActive',
            'config' => [
                'type' => 'check',
                'default' => 1,
            ],
        ],

        'client_id' => [
            'label' => 'Client ID',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'eval' => 'trim',
            ],
        ],

        // Speichert verschlüsselt. Im Backend soll man nur "neu setzen".
        // Praktikabler Ansatz: Feld als Passwort-Eingabe, beim Speichern verschlüsseln (DataHandler-Hook / PSR-14 Event).
        'client_secret' => [
            'label' => 'Client Secret',
            'description' => 'Stored encrypted. Enter a new value to replace the existing secret.',
            'config' => [
                'type' => 'password',
                'size' => 60,
                'eval' => 'trim',
            ],
        ],

        // string oder JSON; du unterstützt beides im FlowService.
        'scopes' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:client.scopes',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'cols' => 60,
                'eval' => 'trim',
            ],
        ],

        'notify_email' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:client.notifyEmail',
            'config' => [
                'type' => 'input',
                'size' => 60,
                'eval' => 'trim,email',
            ],
        ],

        // Inline-Verknüpfung zu Connections
        'connections' => [
            'label' => 'LLL:EXT:oauth_service/Resources/Private/Language/locallang_mod.xlf:connections',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_oauthsvc_connection',
                'foreign_field' => 'client',
                'appearance' => [
                    'collapseAll' => true,
                    'levelLinksPosition' => 'top',
                    'showSynchronizationLink' => false,
                    'showPossibleLocalizationRecords' => false,
                    'showAllLocalizationLink' => false,
                    'useSortable' => false,
                    'enabledControls' => [
                        'new' => true,
                        'delete' => true,
                        'hide' => false,
                        'dragdrop' => false,
                        'sort' => false,
                    ],
                ],
                'behaviour' => [
                    'disableMovingChildrenWithParent' => true,
                ],
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
