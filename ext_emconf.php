<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'DigitalTolk TMS connector',
    'description' => 'Send pages to DigitalTolk for translation and import the translations.',
    'category' => 'module',
    'version' => '0.1.0',
    'state' => 'beta',
    'author' => 'DigitalTolk',
    'author_email' => 'engineering@digitaltolk.com',
    'author_company' => 'DigitalTolk',
    'constraints' => [
        'depends' => [
            'php' => '8.3.0-8.4.99',
            'typo3' => '12.4.0-13.4.99',
            'backend' => '12.4.0-13.4.99',
            'fluid' => '12.4.0-13.4.99',
            'l10nmgr' => '13.0.0-13.99.99',
            'scheduler' => '12.4.0-13.4.99',
        ],
    ],
];
