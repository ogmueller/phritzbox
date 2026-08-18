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
 * That floor answers "how much is this costing me around the clock", and for a
 * device that is off half the week it is zero — true, but it says nothing about
 * what the appliance draws when it *is* on and doing nothing, which is what most
 * people mean by standby. So two more figures come back alongside it:
 *
 *  - `dutyCyclePercent` — how much of the window the device drew any power.
 *  - `idleWatts` — the same 5th percentile taken over the on-samples only, i.e.
 *    the floor it holds while switched on. Null when it was never on, or was on
 *    too briefly for a percentile to mean anything (see IDLE_MIN_SAMPLES).
 *
 * Deliberately *not* annualised. `watts` can be projected over a year because it
 * is a round-the-clock floor; `idleWatts` only applies while the device is on,
 * and multiplying it by a duty cycle observed over one week would dress a
 * one-week usage pattern up as a yearly forecast.
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

    /**
     * Minimum on-samples before an idle-while-on figure is quoted.
     *
     * Not a statistical-power argument, an arithmetic one: `offsetFor()` skips
     * `count × 5 / 100` rows, which is zero for anything under twenty. Below
     * that the "5th percentile" *is* the minimum, and the robustness that the
     * percentile was chosen for is gone — so say nothing instead.
     */
    private const IDLE_MIN_SAMPLES = 20;

    public function __construct(
        private readonly Connection $connection,
        private readonly TariffSettings $tariff,
        private readonly SmartDeviceRepository $devices,
    ) {
    }

    /**
     * @return array{watts: float, annualKwh: float, annualCost: float|null, currency: string, dutyCyclePercent: float, idleWatts: float|null, samples: int, windowDays: int, source: string}|null
     *                                                                                                                                                                                             null when there is not enough data to quote a figure
     */
    public function forDevice(string $ain, ?\DateTimeImmutable $now = null, int $windowDays = self::DEFAULT_WINDOW_DAYS): ?array
    {
        $now ??= new \DateTimeImmutable();
        $from = $now->modify(\sprintf('-%d days', $windowDays))->format('Y-m-d H:i:s');

        $estimate = $this->fromRollup($ain, $from) ?? $this->fromRaw($ain, $from);
        if ($estimate === null) {
            return null;
        }

        $watts = MetricUnits::toDisplay(MetricUnits::TYPE_POWER, $estimate['floor']);
        $annualKwh = $watts * self::HOURS_PER_YEAR / 1000.0;
        $price = $this->tariff->pricePerKwh();

        return [
            'watts' => round($watts, 2),
            'annualKwh' => round($annualKwh, 1),
            // Null, never 0.00, when no tariff is configured.
            'annualCost' => $price === null ? null : round($annualKwh * $price, 2),
            // Carried alongside the amount so a caller never has to guess the
            // unit of a money field, matching /cost and /summary.
            'currency' => $this->tariff->currency(),
            // How much of the window the device drew anything at all. 0 means it
            // never came on, which is what makes a floor of 0 W readable: "off",
            // not "on and drawing nothing".
            'dutyCyclePercent' => round($estimate['on'] / $estimate['samples'] * 100, 1),
            // Null rather than 0.0: "it was never on long enough to say" is not
            // the same claim as "it idles at nothing", the same distinction the
            // whole cost feature makes between null and zero.
            'idleWatts' => $estimate['idle'] === null
                ? null
                : round(MetricUnits::toDisplay(MetricUnits::TYPE_POWER, $estimate['idle']), 2),
            'samples' => $estimate['samples'],
            'windowDays' => $windowDays,
            'source' => $estimate['source'],
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
     * A bucket counts as "on" only when its *minimum* is above zero, i.e. the
     * device drew power for the whole fifteen minutes. A bucket it switched off
     * halfway through counts as off, so the duty cycle read from this tier is a
     * slight underestimate — deliberately the same conservative direction as the
     * floor itself, and it keeps "on" meaning one thing across both figures.
     *
     * @return array{floor: float, idle: float|null, on: int, samples: int, source: string}|null
     */
    private function fromRollup(string $ain, string $from): ?array
    {
        $params = ['ain' => $ain, 'type' => MetricUnits::TYPE_POWER, 'grid' => RollupService::GRID_QUARTER, 'from' => $from];
        $where = ' WHERE sid = :ain AND type = :type AND grid = :grid AND bucket >= :from';

        $counts = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN min_value > 0 THEN 1 ELSE 0 END) AS on_count'
            .' FROM smart_device_data_rollup'.$where,
            $params,
        ) ?: [];

        $count = (int) ($counts['total'] ?? 0);
        if ($count < self::MIN_SAMPLES) {
            return null;
        }

        $on = (int) ($counts['on_count'] ?? 0);
        $floor = $this->valueAtOffset(
            'SELECT min_value FROM smart_device_data_rollup'.$where.' ORDER BY min_value ASC LIMIT 1 OFFSET :offset',
            $params,
            self::offsetFor($count),
        );

        return $floor === null ? null : [
            'floor' => $floor,
            'idle' => $on < self::IDLE_MIN_SAMPLES ? null : $this->valueAtOffset(
                'SELECT min_value FROM smart_device_data_rollup'.$where.' AND min_value > 0'
                .' ORDER BY min_value ASC LIMIT 1 OFFSET :offset',
                $params,
                self::offsetFor($on),
            ),
            'on' => $on,
            'samples' => $count,
            'source' => 'rollup',
        ];
    }

    /**
     * Fallback for an installation whose rollup job has never run.
     *
     * Percentile over raw readings rather than bucket minima — a slightly higher
     * figure for the same device, since it is not pre-floored, but the right
     * shape and far better than reporting nothing. The duty cycle is exact here
     * rather than conservative: a reading is on or off, with no bucket to
     * straddle.
     *
     * @return array{floor: float, idle: float|null, on: int, samples: int, source: string}|null
     */
    private function fromRaw(string $ain, string $from): ?array
    {
        $params = ['ain' => $ain, 'type' => MetricUnits::TYPE_POWER, 'from' => $from];
        $where = ' WHERE sid = :ain AND type = :type AND time >= :from';

        $counts = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN value > 0 THEN 1 ELSE 0 END) AS on_count'
            .' FROM smart_device_data'.$where,
            $params,
        ) ?: [];

        $count = (int) ($counts['total'] ?? 0);
        if ($count < self::MIN_SAMPLES) {
            return null;
        }

        $on = (int) ($counts['on_count'] ?? 0);
        $floor = $this->valueAtOffset(
            'SELECT value FROM smart_device_data'.$where.' ORDER BY value ASC LIMIT 1 OFFSET :offset',
            $params,
            self::offsetFor($count),
        );

        return $floor === null ? null : [
            'floor' => $floor,
            'idle' => $on < self::IDLE_MIN_SAMPLES ? null : $this->valueAtOffset(
                'SELECT value FROM smart_device_data'.$where.' AND value > 0'
                .' ORDER BY value ASC LIMIT 1 OFFSET :offset',
                $params,
                self::offsetFor($on),
            ),
            'on' => $on,
            'samples' => $count,
            'source' => 'raw',
        ];
    }

    /**
     * The row at a percentile offset.
     *
     * SQLite has no percentile function; ordering and skipping to the position
     * is exact and, on a few hundred rows, free.
     *
     * @param array<string, mixed> $params
     */
    private function valueAtOffset(string $sql, array $params, int $offset): ?float
    {
        $value = $this->connection->fetchOne($sql, $params + ['offset' => $offset]);

        return $value === false || $value === null ? null : (float) $value;
    }

    private static function offsetFor(int $count): int
    {
        return intdiv($count * self::PERCENTILE, 100);
    }
}
