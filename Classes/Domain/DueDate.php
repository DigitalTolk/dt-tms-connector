<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Domain;

final class DueDate
{
    public static function fromInput(string $date, \DateTimeZone $timezone): int
    {
        $date = trim($date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return 0;
        }
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 17:00', $timezone);
        if ($due === false || $due->format('Y-m-d') !== $date) {
            return 0;
        }

        return $due->getTimestamp();
    }
}
