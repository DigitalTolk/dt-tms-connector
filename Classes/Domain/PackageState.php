<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Domain;

final class PackageState
{
    public const QUEUED = 'queued';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const DONE = 'done';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    public static function afterLanguages(string $current, array $languageStates): string
    {
        if ($current !== self::SENT || $languageStates === []) {
            return $current;
        }
        foreach ($languageStates as $state) {
            if (LanguageState::isOpen((string)$state)) {
                return self::SENT;
            }
        }
        foreach ($languageStates as $state) {
            if ($state !== LanguageState::CANCELLED) {
                return self::DONE;
            }
        }

        return self::CANCELLED;
    }
}
