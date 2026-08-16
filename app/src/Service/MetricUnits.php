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
 *   battery      stored %                             -> display %
 *   presence     stored 0/1                           -> display 0/1
 *
 * battery and presence are not series the Fritz!Box keeps history for — they are
 * instantaneous device state, sampled once per collection run. Modelling them as
 * metrics rather than as a separate concept means the whole alerting stack
 * (sustained thresholds, cooldown, channels, event log, chart overlay) applies to
 * "battery below 20%" and "offline for an hour" without a single change to it.
 */
final class MetricUnits
{
    public const TYPE_TEMPERATURE = 'temperature';
    public const TYPE_POWER = 'power';
    public const TYPE_VOLTAGE = 'voltage';
    public const TYPE_ENERGY = 'energy';
    public const TYPE_BATTERY = 'battery';
    public const TYPE_PRESENCE = 'presence';

    public const TYPES = [
        self::TYPE_TEMPERATURE,
        self::TYPE_POWER,
        self::TYPE_VOLTAGE,
        self::TYPE_ENERGY,
        self::TYPE_BATTERY,
        self::TYPE_PRESENCE,
    ];

    private const DIVISORS = [
        self::TYPE_VOLTAGE => 1000.0,
        self::TYPE_POWER => 100.0,
        self::TYPE_TEMPERATURE => 1.0,
        self::TYPE_ENERGY => 1.0,
        self::TYPE_BATTERY => 1.0,
        self::TYPE_PRESENCE => 1.0,
    ];

    private const UNITS = [
        self::TYPE_TEMPERATURE => '°C',
        self::TYPE_POWER => 'W',
        self::TYPE_VOLTAGE => 'V',
        self::TYPE_ENERGY => 'Wh',
        self::TYPE_BATTERY => '%',
        // Presence is a 0/1 flag; a unit suffix would only add noise.
        self::TYPE_PRESENCE => '',
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
