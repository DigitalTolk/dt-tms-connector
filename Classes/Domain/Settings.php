<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Domain;

final class Settings
{
    public const IMPORT_REVIEW = 'review';
    public const IMPORT_WORKSPACE = 'workspace';

    public function __construct(
        public readonly string $adapterUrl,
        public readonly string $token,
        public readonly string $connectionKey,
        public readonly string $importMode = self::IMPORT_REVIEW,
        public readonly int $workspaceId = 0,
        public readonly bool $hideNewTranslations = true,
        public readonly int $pollMinutes = 15,
        public readonly array $storageHosts = ['*.amazonaws.com'],
        public readonly bool $allowLocalHttp = false,
        public readonly string $tenant = '',
    ) {
    }

    public function importsIntoWorkspace(): bool
    {
        return $this->importMode === self::IMPORT_WORKSPACE && $this->workspaceId > 0;
    }

    public function exportWorkspace(): int
    {
        return $this->importsIntoWorkspace() ? $this->workspaceId : 0;
    }
}
