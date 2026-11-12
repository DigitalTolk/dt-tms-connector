<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Domain;

use DigitalTolk\TmsConnector\Domain\DueDate;
use PHPUnit\Framework\TestCase;

final class DueDateTest extends TestCase
{
    public function testAnEmptyDateGivesNoDueDate(): void
    {
        self::assertSame(0, DueDate::fromInput('', new \DateTimeZone('Europe/Stockholm')));
        self::assertSame(0, DueDate::fromInput('   ', new \DateTimeZone('Europe/Stockholm')));
    }

    public function testADateIsDueAtFivePmInTheSiteTimezone(): void
    {
        self::assertSame(
            (new \DateTimeImmutable('2026-12-01 17:00', new \DateTimeZone('Europe/Stockholm')))->getTimestamp(),
            DueDate::fromInput('2026-12-01', new \DateTimeZone('Europe/Stockholm')),
        );
    }

    public function testAnInvalidDateGivesNoDueDate(): void
    {
        foreach (['tomorrow', '2026-13-01', '2026-02-30', '01.12.2026', '2026-12-01 09:00'] as $input) {
            self::assertSame(0, DueDate::fromInput($input, new \DateTimeZone('UTC')), $input);
        }
    }
}
