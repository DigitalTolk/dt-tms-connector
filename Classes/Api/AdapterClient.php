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
    private const UPLOAD_HEADERS = ['content-type', 'content-md5', 'content-length'];
    private const CHUNK_BYTES = 65536;
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private readonly \Closure $sleep;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Settings $settings,
        ?\Closure $sleep = null,
        private readonly int $maxDownloadBytes = 52428800,
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
        if (strtoupper((string)($upload['method'] ?? 'PUT')) !== 'PUT') {
            throw new AdapterException('The upload link must use PUT.');
        }
        $url = $this->checkedLink((string)($upload['url'] ?? ''), 'The upload link');
        $request = $this->requests->createRequest('PUT', $url)
            ->withBody($this->streams->createStream($body));
        foreach ((array)($upload['headers'] ?? []) as $name => $value) {
            $lower = strtolower((string)$name);
            if (in_array($lower, self::UPLOAD_HEADERS, true) || str_starts_with($lower, 'x-amz-')) {
                $request = $request->withHeader((string)$name, (string)$value);
            }
        }
        $response = $this->send($request);
        if ($response->getStatusCode() >= 300) {
            throw new AdapterException('The upload to storage failed with HTTP ' . $response->getStatusCode() . '.', $response->getStatusCode());
        }
    }

    public function download(string $url): string
    {
        $response = $this->send($this->requests->createRequest('GET', $this->checkedLink($url, 'The download link')));
        if ($response->getStatusCode() !== 200) {
            throw new AdapterException('The download failed with HTTP ' . $response->getStatusCode() . '.', $response->getStatusCode());
        }
        $stream = $response->getBody();
        $data = '';
        while (!$stream->eof()) {
            $data .= $stream->read(self::CHUNK_BYTES);
            if (strlen($data) > $this->maxDownloadBytes) {
                throw new AdapterException(sprintf('The download is larger than %d bytes.', $this->maxDownloadBytes));
            }
        }

        return $data;
    }

    private function call(string $method, string $path, ?array $body = null): array
    {
        $request = $this->requests->createRequest($method, $this->baseUrl() . 'cms/' . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->settings->token)
            ->withHeader('X-Connection-Key', $this->settings->connectionKey);
        if ($this->settings->tenant !== '') {
            $request = $request->withHeader('X-Tenant', $this->settings->tenant);
        }
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
        if ($this->settings->tenant !== '' && preg_match(self::UUID_PATTERN, $this->settings->tenant) !== 1) {
            throw new AdapterException('The tenant must be the DigitalTolk tenant UUID, for example 0f8fad5b-d9cb-469f-a165-70867728950e, or empty.');
        }

        return rtrim($this->checkedUrl($this->settings->adapterUrl, 'The adapter URL'), '/') . '/';
    }

    private function checkedUrl(string $url, string $label): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower($parts['host'] ?? '');
        $localHttp = $this->settings->allowLocalHttp && $scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true);
        if ($host === '' || ($scheme !== 'https' && !$localHttp)) {
            throw new AdapterException($label . ' must use https.');
        }

        return $url;
    }

    private function checkedLink(string $url, string $label): string
    {
        $this->checkedUrl($url, $label);
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $allowed = $host === strtolower((string)parse_url($this->settings->adapterUrl, PHP_URL_HOST))
            || ($this->settings->allowLocalHttp && in_array($host, self::LOCAL_HOSTS, true));
        foreach ($this->settings->storageHosts as $pattern) {
            $allowed = $allowed || fnmatch(strtolower(trim((string)$pattern)), $host);
        }
        if (!$allowed) {
            throw new AdapterException(sprintf('%s points to the host %s, which is not allowed. Add it to the storage hosts setting if DigitalTolk uses it.', $label, $host));
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
