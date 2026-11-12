<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Api;

final class AdapterException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly array $data = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
