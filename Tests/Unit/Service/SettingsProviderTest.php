<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Service;

use DigitalTolk\TmsConnector\Service\SettingsProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

final class SettingsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        Environment::initialize(new ApplicationContext('Testing'), true, true, '/app', '/app/public', '/app/var', '/app/config', '/app/vendor/bin/typo3', 'UNIX');
        putenv('DT_TMS_TENANT');
    }

    protected function tearDown(): void
    {
        putenv('DT_TMS_TENANT');
    }

    private function provider(array $config): SettingsProvider
    {
        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);

        return new SettingsProvider($extensionConfiguration);
    }

    public function testReadsTheTenantFromTheConfiguration(): void
    {
        $settings = $this->provider(['tenant' => ' 0f8fad5b-d9cb-469f-a165-70867728950e '])->get();

        self::assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $settings->tenant);
    }

    public function testTheEnvironmentReplacesTheConfiguredTenant(): void
    {
        putenv('DT_TMS_TENANT=7c9e6679-7425-40de-944b-e07fc1f90ae7');

        $settings = $this->provider(['tenant' => '0f8fad5b-d9cb-469f-a165-70867728950e'])->get();

        self::assertSame('7c9e6679-7425-40de-944b-e07fc1f90ae7', $settings->tenant);
    }

    public function testTheTenantIsEmptyWhenNothingIsSet(): void
    {
        self::assertSame('', $this->provider([])->get()->tenant);
    }
}
