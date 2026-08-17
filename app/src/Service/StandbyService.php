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

use App\Repository\SmartDeviceRepository;
use App\Service\DataLifecycle\RollupService;
use Doctrine\DBAL\Connection;

/**
 * What a device draws when it is doing nothing.
 *
 * Estimated as the 5th percentile of power over a week — not the mean, which a
 * few hours of real use would drag upwards, and not the absolute minimum, which
 * a single dropout to zero would decide on its own. The 5th percentile is the
 * floor the device keeps returning to.
 *
 * Read from the quarter-hour rollup's `min_value` rather than raw readings.
 * Each bucket's minimum is already the floor of those 15 minutes, so a
 * percentile over a week of them is both cheaper (672 rows instead of ~25,000)
 * and unaffected by raw retention, which could otherwise leave the window with
 * only a day of data.
 *
 * The annual figure is explicitly hypothetical: "if it kept drawing this
 * continuously for a year". A device that spends most of its time switched off
 * reports a floor of zero, which is the honest answer — it costs nothing while
 * it is off.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class StandbyService
{
    public const DEFAULT_WINDOW_DAYS = 7;

    /** Percentile taken as the idle floor. */
    private const PERCENTILE = 5;

    private const HOURS_PER_YEAR = 8760.0;

    /**
     * Below roughly a day of samples the percentile is not worth quoting — it
     * would move sharply with each new reading.
     */
    private const MIN_SAMPLES = 96;

    public function __construct(
        private readonly Connection $connection,
        private readonly TariffSettings $tariff,
        private readonly SmartDeviceRepository $devices,
    ) {
    }

    /**
     * @return array{watts: float, annualKwh: float, annualCost: float|null, samples: int, windowDays: int, source: string}|null
     *                                                                                                                           null when there is not enough data to quote a figure
     */
    public function forDevice(string $ain, ?\DateTimeImmutable $now = null, int $windowDays = self::DEFAULT_WINDOW_DAYS): ?array
    {
        $now ??= new \DateTimeImmutable();
        $from = $now->modify(\sprintf('-%d days', $windowDays))->format('Y-m-d H:i:s');

        $estimate = $this->fromRollup($ain, $from) ?? $this->fromRaw($ain, $from);
        if ($estimate === null) {
            return null;
        }

        [$storedValue, $samples, $source] = $estimate;
        $watts = MetricUnits::toDisplay(MetricUnits::TYPE_POWER, $storedValue);
        $annualKwh = $watts * self::HOURS_PER_YEAR / 1000.0;
        $price = $this->tariff->pricePerKwh();

        return [
            'watts' => round($watts, 2),
            'annualKwh' => round($annualKwh, 1),
            // Null, never 0.00, when no tariff is configured.
            'annualCost' => $price === null ? null : round($annualKwh * $price, 2),
            'samples' => $samples,
            'windowDays' => $windowDays,
            'source' => $source,
        ];
    }

    /**
     * Household standby: the sum of every device's idle floor.
     *
     * @return array{watts: float, annualKwh: float, annualCost: float|null, devices: int}
     */
    public function household(?\DateTimeImmutable $now = null, int $windowDays = self::DEFAULT_WINDOW_DAYS): array
    {
        $now ??= new \DateTimeImmutable();
        $watts = 0.0;
        $counted = 0;

        foreach (array_keys($this->devices->findAllIndexedByAin()) as $ain) {
            $estimate = $this->forDevice((string) $ain, $now, $windowDays);
            if ($estimate === null) {
                continue;
            }
            $watts += $estimate['watts'];
            ++$counted;
        }

        $annualKwh = $watts * self::HOURS_PER_YEAR / 1000.0;
        $price = $this->tariff->pricePerKwh();

        return [
            'watts' => round($watts, 2),
            'annualKwh' => round($annualKwh, 1),
            'annualCost' => $price === null ? null : round($annualKwh * $price, 2),
            'devices' => $counted,
        ];
    }

    /**
     * @return array{0: float, 1: int, 2: string}|null
     */
    private function fromRollup(string $ain, string $from): ?array
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data_rollup'
            .' WHERE sid = :ain AND type = :type AND grid = :grid AND bucket >= :from',
            ['ain' => $ain, 'type' => MetricUnits::TYPE_POWER, 'grid' => RollupService::GRID_QUARTER, 'from' => $from],
        );

        if ($count < self::MIN_SAMPLES) {
            return null;
        }

        // SQLite has no percentile function; ordering and skipping to the
        // position is exact and, on a few hundred rows, free.
        $value = $this->connection->fetchOne(
            'SELECT min_value FROM smart_device_data_rollup'
            .' WHERE sid = :ain AND type = :type AND grid = :grid AND bucket >= :from'
            .' ORDER BY min_value ASC LIMIT 1 OFFSET :offset',
            [
                'ain' => $ain,
                'type' => MetricUnits::TYPE_POWER,
                'grid' => RollupService::GRID_QUARTER,
                'from' => $from,
                'offset' => self::offsetFor($count),
            ],
        );

        return $value === false ? null : [(float) $value, $count, 'rollup'];
    }

    /**
     * Fallback for an installation whose rollup job has never run.
     *
     * Percentile over raw readings rather than bucket minima — a slightly higher
     * figure for the same device, since it is not pre-floored, but the right
     * shape and far better than reporting nothing.
     *
     * @return array{0: float, 1: int, 2: string}|null
     */
    private function fromRaw(string $ain, string $from): ?array
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data WHERE sid = :ain AND type = :type AND time >= :from',
            ['ain' => $ain, 'type' => MetricUnits::TYPE_POWER, 'from' => $from],
        );

        if ($count < self::MIN_SAMPLES) {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT value FROM smart_device_data WHERE sid = :ain AND type = :type AND time >= :from'
            .' ORDER BY value ASC LIMIT 1 OFFSET :offset',
            [
                'ain' => $ain,
                'type' => MetricUnits::TYPE_POWER,
                'from' => $from,
                'offset' => self::offsetFor($count),
            ],
        );

        return $value === false ? null : [(float) $value, $count, 'raw'];
    }

    private static function offsetFor(int $count): int
    {
        return intdiv($count * self::PERCENTILE, 100);
    }
}
