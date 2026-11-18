<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Api;

use DigitalTolk\TmsConnector\Api\AdapterClient;
use DigitalTolk\TmsConnector\Api\AdapterException;
use DigitalTolk\TmsConnector\Domain\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AdapterClientTest extends TestCase
{
    private array $history = [];
    private array $sleeps = [];

    private function client(array $responses, ?Settings $settings = null, int $maxDownloadBytes = 52428800): AdapterClient
    {
        $this->history = [];
        $this->sleeps = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $factory = new HttpFactory();

        return new AdapterClient(
            new Client(['handler' => $stack, 'http_errors' => false]),
            $factory,
            $factory,
            $settings ?? new Settings('https://gw.example/api/v1/tms-order-adapter/', 'pat-token', 'dtk_0123456789ab_secret'),
            function (int $seconds): void {
                $this->sleeps[] = $seconds;
            },
            $maxDownloadBytes,
        );
    }

    private static function success(int $status, array $data): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode(['status' => 'success', 'data' => $data]));
    }

    public function testPingSendsBothCredentials(): void
    {
        $client = $this->client([self::success(200, ['connection' => 'acme-typo3', 'target_languages' => ['en']])]);

        $data = $client->ping();

        self::assertSame('acme-typo3', $data['connection']);
        $request = $this->history[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://gw.example/api/v1/tms-order-adapter/cms/ping', (string)$request->getUri());
        self::assertSame('Bearer pat-token', $request->getHeaderLine('Authorization'));
        self::assertSame('dtk_0123456789ab_secret', $request->getHeaderLine('X-Connection-Key'));
    }

    public function testSendsTheTenantWithEveryAdapterCallWhenSet(): void
    {
        $settings = new Settings('https://gw.example/api/v1/tms-order-adapter/', 'pat-token', 'dtk_x', tenant: '0f8fad5b-d9cb-469f-a165-70867728950e');
        $client = $this->client([self::success(200, ['connection' => 'acme-typo3']), self::success(202, ['package' => ['state' => 'queued']])], $settings);

        $client->ping();
        $client->complete('send-17', []);

        self::assertCount(2, $this->history);
        self::assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $this->history[0]['request']->getHeaderLine('X-Tenant'));
        self::assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $this->history[1]['request']->getHeaderLine('X-Tenant'));
    }

    public function testSendsNoTenantWhenItIsEmpty(): void
    {
        $client = $this->client([self::success(200, ['connection' => 'acme-typo3'])]);

        $client->ping();

        self::assertFalse($this->history[0]['request']->hasHeader('X-Tenant'));
    }

    public function testRefusesATenantThatIsNotAUuid(): void
    {
        $client = $this->client([self::success(200, [])], new Settings('https://gw.example/', 'pat', 'dtk_x', tenant: '24translate'));

        try {
            $client->ping();
            self::fail('no exception');
        } catch (AdapterException $e) {
            self::assertStringContainsString('tenant', $e->getMessage());
            self::assertStringContainsString('UUID', $e->getMessage());
        }
        self::assertCount(0, $this->history);
    }

    public function testSendsNoTenantWithStorageRequests(): void
    {
        $settings = new Settings('https://gw.example/api/v1/tms-order-adapter/', 'pat', 'dtk_x', tenant: '0f8fad5b-d9cb-469f-a165-70867728950e');
        $client = $this->client([new Response(200), new Response(200, [], '<TYPO3L10N/>')], $settings);

        $client->upload(['method' => 'PUT', 'url' => 'https://bucket.s3.eu-north-1.amazonaws.com/cms/x?sig=1', 'headers' => ['Content-Type' => 'application/xml']], '<TYPO3L10N/>');
        $client->download('https://bucket.s3.eu-north-1.amazonaws.com/out/en/export.xml?sig=1');

        self::assertFalse($this->history[0]['request']->hasHeader('X-Tenant'));
        self::assertFalse($this->history[1]['request']->hasHeader('X-Tenant'));
    }

    public function testCompleteSendsTheManifestAsJson(): void
    {
        $client = $this->client([self::success(202, ['package' => ['reference' => 'send-17', 'state' => 'queued']])]);

        $package = $client->complete('send 17', ['target_languages' => ['en'], 'items' => [['id' => 'pages:2']]]);

        self::assertSame('queued', $package['state']);
        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertStringEndsWith('/cms/packages/send%2017/complete', (string)$request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(['target_languages' => ['en'], 'items' => [['id' => 'pages:2']]], json_decode((string)$request->getBody(), true));
    }

    public function testRetriesBusyAnswersAndNetworkErrors(): void
    {
        $client = $this->client([
            new Response(503),
            new ConnectException('down', new Request('GET', 'x')),
            self::success(200, ['package' => ['state' => 'ordered']]),
        ]);

        self::assertSame('ordered', $client->status('send-17')['state']);
        self::assertCount(3, $this->history);
        self::assertSame([1, 2], $this->sleeps);
    }

    public function testGivesUpAfterThreeAttempts(): void
    {
        $client = $this->client([new Response(429), new Response(502), new Response(504)]);

        try {
            $client->status('send-17');
            self::fail('no exception');
        } catch (AdapterException $e) {
            self::assertSame(504, $e->statusCode);
        }
        self::assertCount(3, $this->history);
    }

    public function testDoesNotRetryAClientError(): void
    {
        $body = json_encode(['status' => 'fail', 'data' => ['reference' => 'reference in use']]);
        $client = $this->client([new Response(409, [], $body)]);

        try {
            $client->startPackage('send-17', [['path' => 'export.xml', 'size' => 10]]);
            self::fail('no exception');
        } catch (AdapterException $e) {
            self::assertSame(409, $e->statusCode);
            self::assertSame(['reference' => 'reference in use'], $e->data);
            self::assertStringContainsString('reference in use', $e->getMessage());
        }
        self::assertCount(1, $this->history);
    }

    public function testUploadUsesTheSignedRequestWithoutCredentials(): void
    {
        $client = $this->client([new Response(200)]);

        $client->upload(['method' => 'PUT', 'url' => 'https://bucket.s3.eu-north-1.amazonaws.com/cms/x?sig=1', 'headers' => ['Content-Type' => 'application/xml']], '<TYPO3L10N/>');

        $request = $this->history[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('https://bucket.s3.eu-north-1.amazonaws.com/cms/x?sig=1', (string)$request->getUri());
        self::assertSame('application/xml', $request->getHeaderLine('Content-Type'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('X-Connection-Key'));
        self::assertSame('<TYPO3L10N/>', (string)$request->getBody());
    }

    public function testDownloadReturnsTheBody(): void
    {
        $client = $this->client([new Response(200, [], '<TYPO3L10N/>')]);

        self::assertSame('<TYPO3L10N/>', $client->download('https://bucket.s3.eu-north-1.amazonaws.com/out/en/export.xml?sig=1'));
        self::assertSame('', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testRefusesAnIncompleteSetup(): void
    {
        $client = $this->client([], new Settings('https://gw.example', '', 'dtk_x'));

        $this->expectException(AdapterException::class);
        $client->ping();
    }

    public function testRefusesAPlainHttpAdapterUrl(): void
    {
        $client = $this->client([], new Settings('http://gw.example', 'pat', 'dtk_x'));

        $this->expectException(AdapterException::class);
        $client->ping();
    }

    public function testAllowsPlainHttpForALocalAdapter(): void
    {
        $client = $this->client([self::success(200, ['connection' => 'local'])], new Settings('http://host.docker.internal:8080/', 'pat', 'dtk_x', allowLocalHttp: true));

        self::assertSame('local', $client->ping()['connection']);
        self::assertSame('http://host.docker.internal:8080/cms/ping', (string)$this->history[0]['request']->getUri());
    }

    public function testDoesNotRepeatAPostAfterAGatewayError(): void
    {
        $client = $this->client([new Response(504), self::success(201, ['package' => ['reference' => 'send-17']])]);

        try {
            $client->startPackage('send-17', [['path' => 'export.xml', 'size' => 10]]);
            self::fail('no exception');
        } catch (AdapterException $e) {
            self::assertSame(504, $e->statusCode);
        }
        self::assertCount(1, $this->history);
    }

    public function testRepeatsAPostThatWasRateLimited(): void
    {
        $client = $this->client([new Response(429), self::success(202, ['package' => ['state' => 'queued']])]);

        self::assertSame('queued', $client->complete('send-17', [])['state']);
        self::assertCount(2, $this->history);
    }

    public function testRepeatsAPostThatNeverReachedTheServer(): void
    {
        $client = $this->client([new ConnectException('down', new Request('POST', 'x')), self::success(202, ['package' => ['state' => 'queued']])]);

        self::assertSame('queued', $client->complete('send-17', [])['state']);
        self::assertCount(2, $this->history);
    }

    public function testDoesNotRepeatAPostAfterAnUnclearNetworkError(): void
    {
        $client = $this->client([new \GuzzleHttp\Exception\RequestException('reset', new Request('POST', 'x')), self::success(202, [])]);

        $this->expectException(AdapterException::class);
        try {
            $client->complete('send-17', []);
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testRefusesAPlainHttpUploadLink(): void
    {
        $client = $this->client([new Response(200)]);

        try {
            $client->upload(['method' => 'PUT', 'url' => 'http://s3.example/cms/x?sig=1'], '<TYPO3L10N/>');
            self::fail('no exception');
        } catch (AdapterException $e) {
            self::assertStringContainsString('https', $e->getMessage());
        }
        self::assertCount(0, $this->history);
    }

    public function testRefusesAPlainHttpDownloadLink(): void
    {
        $client = $this->client([new Response(200, [], '<TYPO3L10N/>')]);

        $this->expectException(AdapterException::class);
        $client->download('http://s3.example/out/en/export.xml?sig=1');
    }

    public function testAllowsPlainHttpLinksForALocalAdapterInDevelopment(): void
    {
        $client = $this->client([new Response(200, [], '<TYPO3L10N/>')], new Settings('http://host.docker.internal:9040/', 'pat', 'dtk_x', allowLocalHttp: true));

        self::assertSame('<TYPO3L10N/>', $client->download('http://host.docker.internal:4566/out/en/export.xml?sig=1'));
    }

    public function testRefusesPlainHttpToALocalHostOutsideDevelopment(): void
    {
        $client = $this->client([self::success(200, [])], new Settings('http://localhost:9040/', 'pat', 'dtk_x'));

        $this->expectException(AdapterException::class);
        $client->ping();
    }

    public function testRefusesALinkToAHostThatIsNotAllowed(): void
    {
        $client = $this->client([new Response(200, [], 'secret internal data')]);

        try {
            $client->download('https://intranet.customer.example/admin');
            self::fail('no exception');
        } catch (AdapterException $e) {
            self::assertStringContainsString('not allowed', $e->getMessage());
        }
        self::assertCount(0, $this->history);
    }

    public function testAllowsLinksToTheAdapterHostAndToExtraStorageHosts(): void
    {
        $settings = new Settings('https://gw.example/api/v1/tms-order-adapter/', 'pat', 'dtk_x', storageHosts: ['files.example.org']);
        $client = $this->client([new Response(200, [], 'a'), new Response(200, [], 'b')], $settings);

        self::assertSame('a', $client->download('https://gw.example/files/1'));
        self::assertSame('b', $client->download('https://files.example.org/out/1'));
    }

    public function testRefusesAnUploadThatIsNotAPut(): void
    {
        $client = $this->client([new Response(200)]);

        $this->expectException(AdapterException::class);
        $client->upload(['method' => 'POST', 'url' => 'https://bucket.s3.eu-north-1.amazonaws.com/cms/x?sig=1'], '<TYPO3L10N/>');
    }

    public function testSendsOnlyStorageHeadersWithAnUpload(): void
    {
        $client = $this->client([new Response(200)]);

        $client->upload(['method' => 'PUT', 'url' => 'https://bucket.s3.eu-north-1.amazonaws.com/cms/x?sig=1', 'headers' => [
            'Content-Type' => 'application/xml',
            'x-amz-checksum-sha256' => 'abc',
            'Authorization' => 'Bearer stolen',
            'Host' => 'intranet.customer.example',
        ]], '<TYPO3L10N/>');

        $request = $this->history[0]['request'];
        self::assertSame('application/xml', $request->getHeaderLine('Content-Type'));
        self::assertSame('abc', $request->getHeaderLine('x-amz-checksum-sha256'));
        self::assertSame('', $request->getHeaderLine('Authorization'));
        self::assertSame('bucket.s3.eu-north-1.amazonaws.com', $request->getHeaderLine('Host'));
    }

    public function testRefusesADownloadThatIsTooLarge(): void
    {
        $client = $this->client([new Response(200, [], str_repeat('x', 2048))], null, 1024);

        $this->expectException(AdapterException::class);
        $client->download('https://bucket.s3.eu-north-1.amazonaws.com/out/en/export.xml?sig=1');
    }
}
