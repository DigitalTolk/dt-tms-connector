<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Api;

use DigitalTolk\TmsConnector\Api\CallbackSignature;
use PHPUnit\Framework\TestCase;

final class CallbackSignatureTest extends TestCase
{
    private const KEY = 'dtk_0123456789ab_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const BODY = '{"event":"translation.ready","reference":"send-17","language":"en"}';

    private function sign(int $t, string $body, string $key): string
    {
        return hash_hmac('sha256', $t . '.' . $body, hash('sha256', $key, true));
    }

    public function testAcceptsAValidSignature(): void
    {
        $header = 't=1000,v1=' . $this->sign(1000, self::BODY, self::KEY);
        self::assertTrue((new CallbackSignature())->verify($header, self::BODY, self::KEY, 1060));
    }

    public function testAcceptsWhenOneOfSeveralSignaturesMatches(): void
    {
        $header = 't=1000,v1=' . $this->sign(1000, self::BODY, 'dtk_other') . ',v1=' . $this->sign(1000, self::BODY, self::KEY);
        self::assertTrue((new CallbackSignature())->verify($header, self::BODY, self::KEY, 1000));
    }

    public function testRefusesAChangedBody(): void
    {
        $header = 't=1000,v1=' . $this->sign(1000, self::BODY, self::KEY);
        self::assertFalse((new CallbackSignature())->verify($header, self::BODY . ' ', self::KEY, 1000));
    }

    public function testRefusesAnOldTimestamp(): void
    {
        $header = 't=1000,v1=' . $this->sign(1000, self::BODY, self::KEY);
        self::assertFalse((new CallbackSignature())->verify($header, self::BODY, self::KEY, 1000 + 301));
    }

    public function testRefusesATimestampFarInTheFuture(): void
    {
        $header = 't=2000,v1=' . $this->sign(2000, self::BODY, self::KEY);
        self::assertFalse((new CallbackSignature())->verify($header, self::BODY, self::KEY, 1000));
    }

    public function testRefusesMalformedHeaders(): void
    {
        $signature = new CallbackSignature();
        foreach (['', 'v1=abc', 't=abc,v1=' . $this->sign(0, self::BODY, self::KEY), 't=1000'] as $header) {
            self::assertFalse($signature->verify($header, self::BODY, self::KEY, 1000), $header);
        }
    }

    public function testRefusesAnEmptyKey(): void
    {
        $header = 't=1000,v1=' . $this->sign(1000, self::BODY, '');
        self::assertFalse((new CallbackSignature())->verify($header, self::BODY, '', 1000));
    }
}
