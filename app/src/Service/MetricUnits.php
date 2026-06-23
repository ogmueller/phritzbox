<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

/**
 * Single source of truth for how stored metric values relate to their display
 * units. Readings are persisted in raw Fritz!Box units; the UI (and alert
 * thresholds) work in human units.
 *
 *   temperature  stored °C   (already /10 at ingest)  -> display °C
 *   energy       stored Wh                            -> display Wh
 *   voltage      stored mV                            -> display V   (/1000)
 *   power        stored cW                            -> display W   (/100)
 */
final class MetricUnits
{
    public const TYPES = ['temperature', 'power', 'voltage', 'energy'];

    private const DIVISORS = [
        'voltage' => 1000.0,
        'power' => 100.0,
        'temperature' => 1.0,
        'energy' => 1.0,
    ];

    private const UNITS = [
        'temperature' => '°C',
        'power' => 'W',
        'voltage' => 'V',
        'energy' => 'Wh',
    ];

    public static function isValidType(string $type): bool
    {
        return \in_array($type, self::TYPES, true);
    }

    public static function divisor(string $type): float
    {
        return self::DIVISORS[$type] ?? 1.0;
    }

    /** Convert a stored raw value to its display value. */
    public static function toDisplay(string $type, float $stored): float
    {
        return $stored / self::divisor($type);
    }

    /** Convert a display value (as entered by the user) to the stored raw value. */
    public static function toStored(string $type, float $display): float
    {
        return $display * self::divisor($type);
    }

    public static function unit(string $type): string
    {
        return self::UNITS[$type] ?? '';
    }
}
