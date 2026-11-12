<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Domain;

final class LanguageState
{
    public const QUEUED = 'queued';
    public const WAITING = 'waiting';
    public const IN_REVIEW = 'in_review';
    public const IN_TRANSLATION = 'in_translation';
    public const READY = 'ready';
    public const DOWNLOADED = 'downloaded';
    public const IMPORT_REQUESTED = 'import_requested';
    public const IMPORTED = 'imported';
    public const COLLECTED = 'collected';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    private const FOLLOWS_REMOTE = [self::QUEUED, self::WAITING, self::IN_REVIEW, self::IN_TRANSLATION, self::READY];
    private const REMOTE = [self::WAITING, self::IN_REVIEW, self::IN_TRANSLATION, self::READY, self::COLLECTED, self::CANCELLED, self::FAILED];

    public static function afterRemote(string $local, string $remote): string
    {
        if (!in_array($remote, self::REMOTE, true)) {
            return $local;
        }
        if (in_array($local, self::FOLLOWS_REMOTE, true)) {
            return $remote;
        }
        if ($local === self::IMPORTED && $remote === self::COLLECTED) {
            return self::COLLECTED;
        }

        return $local;
    }

    public static function isPolled(string $state): bool
    {
        return in_array($state, [...self::FOLLOWS_REMOTE, self::IMPORTED], true);
    }

    public static function isOpen(string $state): bool
    {
        return !in_array($state, [self::COLLECTED, self::CANCELLED, self::FAILED], true);
    }
}
