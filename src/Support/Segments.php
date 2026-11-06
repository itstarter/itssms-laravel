<?php

declare(strict_types=1);

namespace ItsStarter\Sms\Support;

/**
 * SMS segment counter. Immutable value object, built with Segments::count().
 */
final readonly class Segments
{
    public const GSM7 = 'GSM-7';

    public const UCS2 = 'UCS-2';

    private const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM7_EXTENDED = "^{}\x5c[~]|€\f";

    public function __construct(
        public string $encoding,
        public int $characters,
        public int $segments,
        public int $perSegment,
    ) {}

    public static function count(string $message): self
    {
        $gsmUnits = self::gsm7Units($message);

        if ($gsmUnits !== null) {
            return new self(self::GSM7, $gsmUnits, self::segmentsFor($gsmUnits, 160, 153), $gsmUnits > 160 ? 153 : 160);
        }

        $units = intdiv(strlen((string) mb_convert_encoding($message, 'UTF-16LE', 'UTF-8')), 2);

        return new self(self::UCS2, $units, self::segmentsFor($units, 70, 67), $units > 70 ? 67 : 70);
    }

    /**
     * Septet count when every character fits GSM-7 (extended characters count 2), otherwise null.
     */
    private static function gsm7Units(string $message): ?int
    {
        $chars = mb_str_split($message, 1, 'UTF-8');
        $units = 0;

        foreach ($chars as $char) {
            if (str_contains(self::GSM7_BASIC, $char)) {
                $units++;
            } elseif (str_contains(self::GSM7_EXTENDED, $char)) {
                $units += 2;
            } else {
                return null;
            }
        }

        return $units;
    }

    private static function segmentsFor(int $units, int $single, int $multi): int
    {
        if ($units === 0) {
            return 0;
        }

        return $units <= $single ? 1 : (int) ceil($units / $multi);
    }
}
