<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Domain;

use DigitalTolk\TmsConnector\Domain\TargetLanguages;
use PHPUnit\Framework\TestCase;

final class TargetLanguagesTest extends TestCase
{
    public function testFindsCodesThatTwoLanguagesShare(): void
    {
        $languages = [
            ['id' => 1, 'code' => 'de'],
            ['id' => 2, 'code' => 'en'],
            ['id' => 3, 'code' => 'de'],
        ];

        self::assertSame(['de'], TargetLanguages::duplicateCodes($languages));
    }

    public function testDistinctCodesHaveNoDuplicates(): void
    {
        self::assertSame([], TargetLanguages::duplicateCodes([['id' => 1, 'code' => 'en'], ['id' => 2, 'code' => 'fr']]));
    }
}
