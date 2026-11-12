<?php

declare(strict_types=1);

return [
    'frontend' => [
        'digitaltolk/tms-connector/callback' => [
            'target' => \DigitalTolk\TmsConnector\Middleware\CallbackMiddleware::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
