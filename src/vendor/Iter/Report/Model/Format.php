<?php

declare(strict_types=1);

namespace Iter\Report\Model;

/**
 * A report value as text, by its column type: on the screen and in the PDF ("R$ 1.234,56", "54,3%"),
 * and plain for the CSV ("1234,56", "54,3"), which Excel in Portuguese reads as numbers.
 * Types: text, int, qty, money, pct (a ratio: 0.543), date, datetime, age (hours).
 */
final class Format
{
    public static function show(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => 'R$ ' . self::number((float) $value, 2),
            'pct' => self::number((float) $value * 100, 1) . '%',
            'int' => self::number((float) $value, 0),
            'qty' => self::qty((float) $value),
            'date' => date('d/m/Y', strtotime((string) $value)),
            'datetime' => date('d/m/Y H:i', strtotime((string) $value)),
            'age' => self::age((float) $value),
            default => (string) $value,
        };
    }

    /** the CSV cell: numbers without symbol or thousands dot; text that a spreadsheet would run as a formula is quoted */
    public static function plain(string $type, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => number_format((float) $value, 2, ',', ''),
            'pct' => number_format((float) $value * 100, 1, ',', ''),
            'int' => (string) (int) round((float) $value),
            'qty' => str_replace('.', ',', self::trim((float) $value)),
            'date', 'datetime', 'age' => self::show($type, $value),
            default => self::safe((string) $value),
        };
    }

    /** "=HYPERLINK(...)" typed in a product name must stay text in a spreadsheet */
    public static function safe(string $text): string
    {
        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'" . $text : $text;
    }

    private static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    private static function qty(float $value): string
    {
        [$int, $dec] = explode('.', self::trim($value)) + [1 => ''];

        return number_format((float) $int, 0, ',', '.') . ($dec !== '' ? ',' . $dec : '');
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private static function age(float $hours): string
    {
        return match (true) {
            $hours < 1 => (int) floor($hours * 60) . ' min',
            $hours < 48 => (int) floor($hours) . ' h',
            default => (int) floor($hours / 24) . ' dias',
        };
    }
}
