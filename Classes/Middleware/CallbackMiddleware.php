<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Middleware;

use DigitalTolk\TmsConnector\Api\CallbackSignature;
use DigitalTolk\TmsConnector\Domain\PackageRepository;
use DigitalTolk\TmsConnector\Service\SettingsProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final class CallbackMiddleware implements MiddlewareInterface
{
    public const PATH = '/dt-tms/callback';

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly PackageRepository $packages,
        private readonly CallbackSignature $signature,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (rtrim($request->getUri()->getPath(), '/') !== self::PATH) {
            return $handler->handle($request);
        }
        if ($request->getMethod() !== 'POST') {
            return new JsonResponse(['error' => 'method not allowed'], 405);
        }
        $body = (string)$request->getBody();
        if (!$this->signature->verify($request->getHeaderLine('X-DT-Signature'), $body, $this->settings->get()->connectionKey, time())) {
            return new JsonResponse(['error' => 'invalid signature'], 401);
        }
        $event = json_decode($body, true);
        $package = is_array($event) ? $this->packages->packageByReference((string)($event['reference'] ?? '')) : null;
        if ($package === null) {
            return new JsonResponse(['error' => 'unknown package'], 404);
        }
        $this->packages->updatePackage((int)$package['uid'], ['needs_poll' => 1]);

        return new JsonResponse(['accepted' => true], 202);
    }
}
