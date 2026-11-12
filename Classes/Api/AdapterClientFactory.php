<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Api;

use DigitalTolk\TmsConnector\Service\SettingsProvider;
use GuzzleHttp\Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class AdapterClientFactory
{
    private const TIMEOUT_SECONDS = 120;
    private const CONNECT_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SettingsProvider $settings,
    ) {
    }

    public function create(): AdapterClient
    {
        $http = array_intersect_key((array)($GLOBALS['TYPO3_CONF_VARS']['HTTP'] ?? []), array_flip(['proxy', 'verify', 'cert', 'ssl_key']));
        $client = new Client($http + [
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);

        return new AdapterClient($client, $this->requests, $this->streams, $this->settings->get());
    }
}
