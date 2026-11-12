<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Api;

use DigitalTolk\TmsConnector\Domain\Settings;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class AdapterClient
{
    private const ATTEMPTS = 3;
    private const RETRY_STATUSES = [429, 502, 503, 504];
    private const RETRY_STATUSES_POST = [429];
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', 'host.docker.internal'];

    private readonly \Closure $sleep;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Settings $settings,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function ping(): array
    {
        return $this->call('GET', 'ping');
    }

    public function startPackage(string $reference, array $files): array
    {
        return $this->call('POST', 'packages', ['reference' => $reference, 'files' => $files])['package'] ?? [];
    }

    public function complete(string $reference, array $manifest): array
    {
        return $this->call('POST', 'packages/' . rawurlencode($reference) . '/complete', $manifest)['package'] ?? [];
    }

    public function status(string $reference): array
    {
        return $this->call('GET', 'packages/' . rawurlencode($reference))['package'] ?? [];
    }

    public function imported(string $reference, string $language): void
    {
        $this->call('POST', 'packages/' . rawurlencode($reference) . '/languages/' . rawurlencode($language) . '/imported', []);
    }

    public function cancel(string $reference): void
    {
        $this->call('DELETE', 'packages/' . rawurlencode($reference));
    }

    public function upload(array $upload, string $body): void
    {
        $url = $this->checkedUrl((string)($upload['url'] ?? ''), 'The upload link');
        $request = $this->requests->createRequest((string)($upload['method'] ?? 'PUT'), $url)
            ->withBody($this->streams->createStream($body));
        foreach ((array)($upload['headers'] ?? []) as $name => $value) {
            $request = $request->withHeader((string)$name, (string)$value);
        }
        $response = $this->send($request);
        if ($response->getStatusCode() >= 300) {
            throw new AdapterException('The upload to storage failed with HTTP ' . $response->getStatusCode() . '.', $response->getStatusCode());
        }
    }

    public function download(string $url): string
    {
        $response = $this->send($this->requests->createRequest('GET', $this->checkedUrl($url, 'The download link')));
        if ($response->getStatusCode() !== 200) {
            throw new AdapterException('The download failed with HTTP ' . $response->getStatusCode() . '.', $response->getStatusCode());
        }

        return (string)$response->getBody();
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        $request = $this->requests->createRequest($method, $this->baseUrl() . 'cms/' . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->settings->token)
            ->withHeader('X-Connection-Key', $this->settings->connectionKey);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }
        $response = $this->send($request);
        $decoded = json_decode((string)$response->getBody(), true);
        $data = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        if ($response->getStatusCode() >= 300) {
            $detail = $data !== [] ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string)($decoded['message'] ?? '');
            throw new AdapterException(
                sprintf('%s %s answered HTTP %d. %s', $method, $path, $response->getStatusCode(), $detail),
                $response->getStatusCode(),
                $data,
            );
        }

        return $data;
    }

    private function baseUrl(): string
    {
        if ($this->settings->token === '' || $this->settings->connectionKey === '' || $this->settings->adapterUrl === '') {
            throw new AdapterException('The adapter URL, the gateway token and the connection key must all be set.');
        }

        return rtrim($this->checkedUrl($this->settings->adapterUrl, 'The adapter URL'), '/') . '/';
    }

    private function checkedUrl(string $url, string $label): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true)))) {
            throw new AdapterException($label . ' must use https.');
        }

        return $url;
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        $post = $request->getMethod() === 'POST';
        $retryStatuses = $post ? self::RETRY_STATUSES_POST : self::RETRY_STATUSES;
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->sendRequest($request);
                if (!in_array($response->getStatusCode(), $retryStatuses, true) || $attempt === self::ATTEMPTS) {
                    return $response;
                }
            } catch (ClientExceptionInterface $e) {
                if ($attempt === self::ATTEMPTS || ($post && !$e instanceof NetworkExceptionInterface)) {
                    throw new AdapterException('The adapter cannot be reached: ' . $e->getMessage(), 0, [], $e);
                }
            }
            ($this->sleep)($attempt);
            $request->getBody()->rewind();
        }
    }
}
