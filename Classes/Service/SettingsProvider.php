<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Service;

use DigitalTolk\TmsConnector\Domain\Settings;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

final class SettingsProvider
{
    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration)
    {
    }

    public function get(): Settings
    {
        try {
            $config = (array)$this->extensionConfiguration->get('dt_tms_connector');
        } catch (\Throwable) {
            $config = [];
        }

        return new Settings(
            trim((string)(getenv('DT_TMS_ADAPTER_URL') ?: ($config['adapterUrl'] ?? ''))),
            trim((string)(getenv('DT_TMS_TOKEN') ?: ($config['token'] ?? ''))),
            trim((string)(getenv('DT_TMS_CONNECTION_KEY') ?: ($config['connectionKey'] ?? ''))),
            (string)($config['importMode'] ?? Settings::IMPORT_REVIEW),
            (int)($config['workspaceId'] ?? 0),
            (bool)($config['hideNewTranslations'] ?? true),
            max(1, (int)($config['pollMinutes'] ?? 15)),
            array_values(array_filter(array_map('trim', explode(',', (string)($config['storageHosts'] ?? '*.amazonaws.com'))))),
            Environment::getContext()->isDevelopment(),
            trim((string)(getenv('DT_TMS_TENANT') ?: ($config['tenant'] ?? ''))),
        );
    }
}
