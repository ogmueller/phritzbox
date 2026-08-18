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
 * Turns stored energy readings into money.
 *
 * Stored `energy` rows are **per-day amounts in Wh**, one per device per day, so
 * a period total is a SUM — not an average, and not the difference of a counter.
 * That is why this reads `sum_value` from the rollup rather than `avg_value`,
 * which the chart read path serves: an average of daily totals is the right
 * number for a bar chart and the wrong one for a bill.
 *
 * Every figure it reports is a **lower bound**. Energy rows are written once and
 * never corrected, and days the collector missed are never backfilled, so a `0`
 * does not reliably mean "consumed nothing". Rather than hide that, the payload
 * carries its own coverage — how many days the range spans, how many actually
 * hold data, and how many are genuinely missing — so the UI can say so.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class EnergyCostService
{
    public const WH_PER_KWH = 1000.0;

    /**
     * Before this release the collector stamped the box's newest complete day
     * with *yesterday's* midnight; afterwards, with today's. Per-day attribution
     * therefore shifts by one day across the boundary. Period totals are
     * unaffected beyond the boundary day itself, but a range that straddles this
     * date deserves a footnote.
     */
    public const DAY_LABEL_SHIFT_AT = '2026-07-16';

    public function __construct(
        private readonly Connection $connection,
        private readonly RollupService $rollup,
        private readonly TariffSettings $tariff,
        private readonly StatsQueryService $statsQuery,
        private readonly SmartDeviceRepository $devices,
        private readonly StandbyService $standby,
    ) {
    }

    /**
     * Energy and cost per device, plus a household total, for a window.
     *
     * @return array<string, mixed>
     */
    public function costForRange(\DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        // Energy is stamped at midnight, so a mid-day start would clip a whole
        // day. Same rule the chart applies, from the same place.
        $from = StatsRange::widenForEnergy($from, MetricUnits::TYPE_ENERGY);
        $boundary = $this->boundary($from, $to);
        $daysInRange = self::dayspan($from, $to);
        $price = $this->tariff->pricePerKwh();

        $devices = [];
        $householdWh = 0.0;
        $householdDaysWithData = 0;
        $householdGapDays = 0;
        $householdEstimated = 0.0;

        foreach ($this->devices->findAllIndexedByAin() as $ain => $device) {
            $stats = $this->energyFor((string) $ain, $from, $to, $boundary);

            $estimated = 0.0;
            if ($this->rangeIncludesToday($to, $now) && !$stats['hasToday']) {
                $synthetic = $this->statsQuery->synthesiseTodayEnergy((string) $ain, $now);
                if ($synthetic !== null) {
                    $estimated = $synthetic['value'];
                    $stats['wh'] += $estimated;
                    ++$stats['days'];
                }
            }

            // A device with no energy readings at all in the window is not a
            // contributor — listing it would be noise, and its coverage figures
            // would be meaningless.
            if ($stats['days'] === 0) {
                continue;
            }

            $gapDays = self::gapDays($stats['firstDay'], $stats['lastDay'], $stats['days']);

            $devices[] = [
                'ain' => (string) $ain,
                'name' => $device->getName(),
                'energyWh' => round($stats['wh'], 1),
                'cost' => self::cost($stats['wh'], $price),
                'coverage' => [
                    'daysInRange' => $daysInRange,
                    'daysWithData' => $stats['days'],
                    'gapDays' => $gapDays,
                    'zeroDays' => $stats['zeroDays'],
                    'firstDay' => $stats['firstDay'],
                    'lastDay' => $stats['lastDay'],
                    'estimatedTodayWh' => round($estimated, 1),
                ],
            ];

            $householdWh += $stats['wh'];
            $householdDaysWithData = max($householdDaysWithData, $stats['days']);
            $householdGapDays = max($householdGapDays, $gapDays);
            $householdEstimated += $estimated;
        }

        // Largest consumer first — the question anyone actually asks of a breakdown.
        usort($devices, static fn (array $a, array $b): int => $b['energyWh'] <=> $a['energyWh']);

        $energyCost = self::cost($householdWh, $price);
        $standingCost = $price === null ? null : $this->standingCharge($from, $to);

        return [
            'from' => $from->format(\DateTimeInterface::ATOM),
            'to' => $to->format(\DateTimeInterface::ATOM),
            'currency' => $this->tariff->currency(),
            'pricePerKwh' => $price,
            'configured' => $this->tariff->isConfigured(),
            'spansCollectorChange' => $this->spansCollectorChange($from, $to),
            'devices' => $devices,
            'household' => [
                'energyWh' => round($householdWh, 1),
                // Kept separate: the standing charge is billed per meter
                // connection, so it belongs to the household and never to a
                // device. Adding it per-device would need an invented allocation
                // rule and would make a device that consumed nothing cost money.
                'energyCost' => $energyCost,
                'standingCost' => $standingCost,
                'cost' => $energyCost === null ? null : round($energyCost + ($standingCost ?? 0.0), 2),
                'coverage' => [
                    'daysInRange' => $daysInRange,
                    'daysWithData' => $householdDaysWithData,
                    'gapDays' => $householdGapDays,
                    'estimatedTodayWh' => round($householdEstimated, 1),
                ],
            ],
        ];
    }

    /**
     * Today, month-to-date and the top consumer — the dashboard's single request.
     *
     * @return array<string, mixed>
     */
    public function summary(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $today = $this->costForRange($now->setTime(0, 0), $now, $now);
        $month = $this->costForRange($now->modify('first day of this month')->setTime(0, 0), $now, $now);

        $top = $month['devices'][0] ?? null;

        return [
            'currency' => $this->tariff->currency(),
            'configured' => $this->tariff->isConfigured(),
            'today' => [
                'energyWh' => $today['household']['energyWh'],
                'cost' => $today['household']['energyCost'],
                // The box reports energy once a day, so today is integrated from
                // power readings until it does.
                'estimated' => $today['household']['coverage']['estimatedTodayWh'] > 0.0,
            ],
            'monthToDate' => [
                'energyWh' => $month['household']['energyWh'],
                'energyCost' => $month['household']['energyCost'],
                'standingCost' => $month['household']['standingCost'],
                'cost' => $month['household']['cost'],
                'gapDays' => $month['household']['coverage']['gapDays'],
            ],
            'topConsumer' => $top === null ? null : [
                'ain' => $top['ain'],
                'name' => $top['name'],
                'energyWh' => $top['energyWh'],
                'cost' => $top['cost'],
            ],
            // What the household draws while doing nothing, projected over a
            // year. Explicitly hypothetical — see StandbyService.
            'standby' => $this->standby->household($now),
        ];
    }

    /**
     * The split point between the pre-aggregated tier and raw readings.
     *
     * Floored to a day because that is the grid being read: a mid-day boundary
     * would let the daily bucket and the same day's raw rows both count.
     */
    private function boundary(\DateTimeImmutable $from, \DateTimeImmutable $to): \DateTimeImmutable
    {
        $watermark = $this->rollup->watermark(RollupService::GRID_QUARTER);
        $boundary = $watermark === null
            // The rollup has never run: raw-only, which is exactly the
            // pre-rollup behaviour rather than a silent zero.
            ? $from
            : RollupService::floorTo($watermark, RollupService::GRID_DAY);

        return max($from, min($boundary, $to));
    }

    /**
     * Energy for one device, summed across both tiers.
     *
     * Queried per device rather than with a bare `type = 'energy'` scan: the only
     * usable index leads with `sid`, so a type-only filter would full-scan tens
     * of millions of rows.
     *
     * @return array{wh: float, days: int, zeroDays: int, firstDay: string|null, lastDay: string|null, hasToday: bool}
     */
    private function energyFor(string $ain, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $boundary): array
    {
        // The daily rollup covers everything before the boundary. It is not
        // optional: retention prunes raw energy too, so with a retention window
        // set, older raw rows are gone and only this tier still holds them.
        $rollup = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(sum_value), 0) AS wh, COUNT(*) AS days,'
            .' SUM(CASE WHEN sum_value = 0 THEN 1 ELSE 0 END) AS zero_days,'
            .' MIN(bucket) AS first_day, MAX(bucket) AS last_day'
            .' FROM smart_device_data_rollup'
            .' WHERE sid = :ain AND type = :type AND grid = :grid'
            .' AND bucket >= :from AND bucket < :boundary',
            [
                'ain' => $ain,
                'type' => MetricUnits::TYPE_ENERGY,
                'grid' => RollupService::GRID_DAY,
                'from' => self::sql($from),
                'boundary' => self::sql($boundary),
            ],
        ) ?: [];

        $raw = $this->connection->fetchAssociative(
            // COUNT(DISTINCT date(time)) rather than COUNT(*): a duplicate row
            // for one day must not inflate the coverage figure.
            'SELECT COALESCE(SUM(value), 0) AS wh, COUNT(DISTINCT date(time)) AS days,'
            .' SUM(CASE WHEN value = 0 THEN 1 ELSE 0 END) AS zero_days,'
            .' MIN(time) AS first_day, MAX(time) AS last_day'
            .' FROM smart_device_data'
            .' WHERE sid = :ain AND type = :type AND time >= :boundary AND time <= :to',
            [
                'ain' => $ain,
                'type' => MetricUnits::TYPE_ENERGY,
                'boundary' => self::sql($boundary),
                'to' => self::sql($to),
            ],
        ) ?: [];

        $firstDays = array_filter([self::day($rollup['first_day'] ?? null), self::day($raw['first_day'] ?? null)]);
        $lastDays = array_filter([self::day($rollup['last_day'] ?? null), self::day($raw['last_day'] ?? null)]);

        $lastDay = $lastDays === [] ? null : max($lastDays);

        return [
            'wh' => (float) ($rollup['wh'] ?? 0) + (float) ($raw['wh'] ?? 0),
            'days' => (int) ($rollup['days'] ?? 0) + (int) ($raw['days'] ?? 0),
            'zeroDays' => (int) ($rollup['zero_days'] ?? 0) + (int) ($raw['zero_days'] ?? 0),
            'firstDay' => $firstDays === [] ? null : min($firstDays),
            'lastDay' => $lastDay,
            'hasToday' => $lastDay === (new \DateTimeImmutable())->format('Y-m-d'),
        ];
    }

    /**
     * The fixed monthly charge, prorated across the months a range touches.
     *
     * Nothing here is measured. The charge is a figure the operator typed off a
     * bill; the only question is how much of a *monthly* number a given window
     * is owed:
     *
     *     for each calendar month the range touches:
     *         total += perMonth × (days of the range in that month ÷ days in that month)
     *
     * Divided by each month's own length rather than by a flat 30.44-day
     * average, so a whole month comes out at exactly the figure printed on the
     * bill — the number people check first. A 21 June–10 July window at €30 a
     * month is 30 × 10/30 + 30 × 10/31 = €19.68: June's ten days are worth more
     * than July's, because June is shorter.
     *
     * Days are counted inclusively at midnight granularity (see `dayspan`), so
     * an hour-long window is charged a whole day rather than 1/24 of one, and
     * month-to-date on the 18th of a 30-day month is charged 18/30. The
     * dashboard's standing charge therefore grows daily through the month
     * instead of landing whole on the 1st.
     */
    private function standingCharge(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $perMonth = $this->tariff->standingChargePerMonth();
        if ($perMonth <= 0.0) {
            return 0.0;
        }

        $total = 0.0;
        $cursor = $from->modify('first day of this month')->setTime(0, 0);
        $end = $to->setTime(0, 0);

        while ($cursor <= $end) {
            $monthStart = max($cursor, $from->setTime(0, 0));
            $monthEnd = min($cursor->modify('last day of this month')->setTime(0, 0), $end);
            $daysInMonth = (int) $cursor->format('t');

            $total += $perMonth * (self::dayspan($monthStart, $monthEnd) / $daysInMonth);

            $cursor = $cursor->modify('first day of next month');
        }

        return round($total, 2);
    }

    private function spansCollectorChange(\DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        $shift = new \DateTimeImmutable(self::DAY_LABEL_SHIFT_AT);

        return $from < $shift && $to >= $shift;
    }

    private function rangeIncludesToday(\DateTimeImmutable $to, \DateTimeImmutable $now): bool
    {
        return $to >= $now->setTime(0, 0);
    }

    private static function cost(float $wh, ?float $pricePerKwh): ?float
    {
        // Null, never 0.00: an unconfigured tariff must not look like a free one.
        return $pricePerKwh === null ? null : round($wh / self::WH_PER_KWH * $pricePerKwh, 2);
    }

    /** Inclusive count of calendar days between two instants. */
    private static function dayspan(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $days = (int) $from->setTime(0, 0)->diff($to->setTime(0, 0))->days;

        return max(1, $days + 1);
    }

    /**
     * Days genuinely lost, as opposed to days simply outside a device's lifetime.
     *
     * A device installed halfway through the range has fewer days of data than
     * the range spans, but nothing is missing. Measuring gaps against the
     * device's own first and last day is what keeps a newly added device from
     * raising a false warning.
     */
    private static function gapDays(?string $firstDay, ?string $lastDay, int $daysWithData): int
    {
        if ($firstDay === null || $lastDay === null) {
            return 0;
        }

        $span = self::dayspan(new \DateTimeImmutable($firstDay), new \DateTimeImmutable($lastDay));

        return max(0, $span - $daysWithData);
    }

    private static function day(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? mb_substr($value, 0, 10) : null;
    }

    private static function sql(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
