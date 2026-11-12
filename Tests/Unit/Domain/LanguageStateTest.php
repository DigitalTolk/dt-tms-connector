<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Tests\Unit\Domain;

use DigitalTolk\TmsConnector\Domain\LanguageState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageStateTest extends TestCase
{
    public static function transitions(): array
    {
        return [
            'queued follows the adapter' => ['queued', 'waiting', 'waiting'],
            'waiting goes to review' => ['waiting', 'in_review', 'in_review'],
            'review goes to translation' => ['in_review', 'in_translation', 'in_translation'],
            'translation becomes ready' => ['in_translation', 'ready', 'ready'],
            'ready stays ready' => ['ready', 'ready', 'ready'],
            'a cancel ends the language' => ['in_translation', 'cancelled', 'cancelled'],
            'a failure ends the language' => ['in_review', 'failed', 'failed'],
            'collected elsewhere ends the language' => ['ready', 'collected', 'collected'],
            'downloaded keeps its own state' => ['downloaded', 'ready', 'downloaded'],
            'import requested keeps its own state' => ['import_requested', 'ready', 'import_requested'],
            'imported keeps its own state' => ['imported', 'ready', 'imported'],
            'imported becomes collected' => ['imported', 'collected', 'collected'],
            'collected is final' => ['collected', 'ready', 'collected'],
            'cancelled is final' => ['cancelled', 'in_translation', 'cancelled'],
            'an unknown remote state changes nothing' => ['in_translation', 'something_new', 'in_translation'],
        ];
    }

    #[DataProvider('transitions')]
    public function testAfterRemote(string $local, string $remote, string $expected): void
    {
        self::assertSame($expected, LanguageState::afterRemote($local, $remote));
    }

    public function testOnlyOpenLanguagesArePolled(): void
    {
        self::assertTrue(LanguageState::isPolled('in_translation'));
        self::assertTrue(LanguageState::isPolled('imported'));
        self::assertFalse(LanguageState::isPolled('downloaded'));
        self::assertFalse(LanguageState::isPolled('collected'));
        self::assertFalse(LanguageState::isPolled('cancelled'));
    }
}
