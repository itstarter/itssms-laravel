<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Support;

/**
 * Normalises Sri Lankan mobile numbers to the 94XXXXXXXXX form (11 digits, 947...).
 */
final class PhoneNumber
{
    /**
     * Returns the normalised number, or null when it is not a Sri Lankan mobile.
     */
    public static function normalize(string $number): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($number));

        if ($digits === null || $digits === '') {
            return null;
        }

        $hasPlus = str_starts_with($digits, '+');

        if ($hasPlus) {
            $digits = substr($digits, 1);
        }

        if ($digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus && ! str_starts_with($digits, '94')) {
            return null;
        }

        // International form written with the trunk zero, e.g. +94 (0) 77 123 4567.
        if (strlen($digits) === 12 && str_starts_with($digits, '940')) {
            $digits = '94'.substr($digits, 3);
        }

        $normalized = match (true) {
            strlen($digits) === 11 && str_starts_with($digits, '94') => $digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '94'.substr($digits, 1),
            strlen($digits) === 9 => '94'.$digits,
            default => null,
        };

        if ($normalized === null || preg_match('/^947\d{8}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    public static function isValid(string $number): bool
    {
        return self::normalize($number) !== null;
    }
}
