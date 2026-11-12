<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Import;

final class RekeyResult
{
    public function __construct(
        public readonly string $xml,
        public readonly int $filled,
        public readonly int $total,
        public readonly int $blocked = 0,
    ) {
    }
}
