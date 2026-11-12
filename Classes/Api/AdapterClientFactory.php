<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Api;

use DigitalTolk\TmsConnector\Service\SettingsProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class AdapterClientFactory
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly SettingsProvider $settings,
    ) {
    }

    public function create(): AdapterClient
    {
        return new AdapterClient($this->http, $this->requests, $this->streams, $this->settings->get());
    }
}
