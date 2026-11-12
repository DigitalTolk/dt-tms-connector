<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Domain;

use DigitalTolk\TmsConnector\Domain\PackageState;
use PHPUnit\Framework\TestCase;

final class PackageStateTest extends TestCase
{
    public function testStaysSentWhileOneLanguageIsOpen(): void
    {
        self::assertSame('sent', PackageState::afterLanguages('sent', ['collected', 'downloaded']));
    }

    public function testIsDoneWhenAllLanguagesAreClosed(): void
    {
        self::assertSame('done', PackageState::afterLanguages('sent', ['collected', 'cancelled']));
        self::assertSame('done', PackageState::afterLanguages('sent', ['failed']));
    }

    public function testIsCancelledWhenAllLanguagesAreCancelled(): void
    {
        self::assertSame('cancelled', PackageState::afterLanguages('sent', ['cancelled', 'cancelled']));
    }

    public function testKeepsStatesBeforeSending(): void
    {
        self::assertSame('queued', PackageState::afterLanguages('queued', ['cancelled']));
        self::assertSame('failed', PackageState::afterLanguages('failed', ['collected']));
    }
}
