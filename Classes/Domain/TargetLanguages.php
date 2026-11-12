<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Domain;

final class TargetLanguages
{
    public static function duplicateCodes(array $languages): array
    {
        $counts = array_count_values(array_map('strval', array_column($languages, 'code')));

        return array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
    }
}
