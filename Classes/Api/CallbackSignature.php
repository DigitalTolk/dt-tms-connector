<?php

declare(strict_types=1);

namespace DigitalTolk\TmsConnector\Api;

final class CallbackSignature
{
    public const MAX_AGE_SECONDS = 300;

    public function verify(string $header, string $body, string $connectionKey, int $now): bool
    {
        if ($connectionKey === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === 't' && ctype_digit($value)) {
                $timestamp = (int)$value;
            } elseif ($name === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }
        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, hash('sha256', $connectionKey, true));
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
