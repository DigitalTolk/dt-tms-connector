<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Import;

final class ImportResult
{
    public function __construct(
        public readonly string $summary,
        public readonly string $warning = '',
    ) {
    }
}
