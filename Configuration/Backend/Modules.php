<?php

declare(strict_types=1);

use DigitalTolk\TmsConnector\Controller\TranslationsController;

return [
    'web_dttmsconnector' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'path' => '/module/web/dt-tms',
        'iconIdentifier' => 'dt-tms-connector-module',
        'labels' => 'LLL:EXT:dt_tms_connector/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => ['target' => TranslationsController::class . '::indexAction'],
            'send' => ['target' => TranslationsController::class . '::sendAction', 'methods' => ['POST']],
            'detail' => ['target' => TranslationsController::class . '::detailAction'],
            'import' => ['target' => TranslationsController::class . '::importAction', 'methods' => ['POST']],
            'cancel' => ['target' => TranslationsController::class . '::cancelAction', 'methods' => ['POST']],
            'test' => ['target' => TranslationsController::class . '::testAction', 'methods' => ['POST']],
        ],
    ],
];
