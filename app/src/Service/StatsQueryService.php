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

use App\Service\DataLifecycle\RetentionConfig;
use App\Service\DataLifecycle\RollupService;
use Doctrine\DBAL\Connection;

/**
 * The single read path for stored readings, shared by the JSON API and export.
 *
 * Picks a resolution from the width of the window, then answers it from the
 * pre-aggregated tiers where they cover the range and from raw readings beyond
 * the rollup watermark. Because the watermark is always a bucket boundary the
 * two halves cannot overlap, and because a missing watermark collapses the
 * query to the raw half alone, an installation whose rollup job has never run
 * gets byte-for-byte the behaviour it had before rollups existed.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class StatsQueryService
{
    public const RESOLUTION_RAW = 'raw';
    public const RESOLUTION_QUARTER = 'quarter';
    public const RESOLUTION_DAY = 'day';

    private const GRID_OF = [
        self::RESOLUTION_QUARTER => RollupService::GRID_QUARTER,
        self::RESOLUTION_DAY => RollupService::GRID_DAY,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly RollupService $rollup,
        private readonly RetentionConfig $retention,
    ) {
    }

    /**
     * Which tier should answer a window of this width, starting this far back.
     *
     * The width thresholds are the ones the read path has always used. The
     * second step is new: once raw rows stop existing beyond a retention
     * cutoff, a two-day window six months ago has to come from a coarser tier
     * or it would render as an empty chart.
     */
    public function resolutionFor(\DateTimeImmutable $from, \DateTimeImmutable $to, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $diffDays = (int) $from->diff($to)->days;

        $resolution = match (true) {
            $diffDays > 30 => self::RESOLUTION_DAY,
            $diffDays > 2 => self::RESOLUTION_QUARTER,
            default => self::RESOLUTION_RAW,
        };

        $rawCutoff = $this->retention->rawCutoff($now);
        if ($resolution === self::RESOLUTION_RAW && $rawCutoff !== null && $from < $rawCutoff) {
            $resolution = self::RESOLUTION_QUARTER;
        }

        $quarterCutoff = $this->retention->quarterCutoff($now);
        if ($resolution === self::RESOLUTION_QUARTER && $quarterCutoff !== null && $from < $quarterCutoff) {
            $resolution = self::RESOLUTION_DAY;
        }

        return $resolution;
    }

    /**
     * Readings for one device, in display units.
     *
     * @return list<array{time: string, value: float, type: string, min?: float, max?: float}>
     */
    public function fetch(string $ain, string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, string $resolution): array
    {
        $rows = $resolution === self::RESOLUTION_RAW
            ? $this->fetchRaw($ain, $type, $from, $to)
            : $this->fetchAggregated($ain, $type, $from, $to, self::GRID_OF[$resolution]);

        return array_map(static function (array $r) use ($resolution): array {
            $point = [
                'time' => (new \DateTimeImmutable((string) $r['time']))->format(\DateTimeInterface::ATOM),
                'value' => MetricUnits::toDisplay((string) $r['type'], (float) $r['value']),
                'type' => (string) $r['type'],
            ];

            // Only meaningful once values have been aggregated; on raw data the
            // point *is* the extreme and repeating it would be noise.
            if ($resolution !== self::RESOLUTION_RAW) {
                $point['min'] = MetricUnits::toDisplay((string) $r['type'], (float) $r['vmin']);
                $point['max'] = MetricUnits::toDisplay((string) $r['type'], (float) $r['vmax']);
            }

            return $point;
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRaw(string $ain, string $type, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Collapse any duplicate (time, type) rows so each timestamp yields a
        // single point — otherwise the chart tooltip lists the value twice.
        // Duplicates hold the same value, so AVG is a no-op on clean data.
        $sql = 'SELECT time, AVG(value) AS value, type'
            .' FROM smart_device_data'
            .' WHERE sid = :ain AND time >= :from AND time <= :to';
        $params = ['ain' => $ain, 'from' => self::sql($from), 'to' => self::sql($to)];

        if ($type !== '') {
            $sql .= ' AND type = :type';
            $params['type'] = $type;
        }
        $sql .= ' GROUP BY time, type ORDER BY time ASC';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Pre-aggregated buckets up to the rollup watermark, plus raw readings
     * beyond it aggregated on the fly.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAggregated(string $ain, string $type, \DateTimeImmutable $from, \DateTimeImmutable $to, int $grid): array
    {
        // Widen the start to the containing bucket. A stored bucket covers its
        // whole period, so asking for "from 14:32" would otherwise drop the
        // 14:30 bucket from the rollup half while the raw half does not cover it
        // either — a gap at the left edge. Flooring makes both halves agree, and
        // means a bucket is always shown as the whole bucket it is. (The energy
        // branch already widens to midnight for the same reason.)
        $from = RollupService::floorTo($from, $grid);

        // Clamped into the window so neither half of the union reaches outside
        // it. A null watermark (the rollup has never run) collapses this to
        // $from, leaving only the raw half — exactly the old behaviour.
        $watermark = $this->rollup->watermark(RollupService::GRID_QUARTER) ?? $from;
        $watermark = max($from, min($watermark, $to));

        $params = [
            'ain' => $ain,
            'from' => self::sql($from),
            'to' => self::sql($to),
            'watermark' => self::sql($watermark),
            'grid' => $grid,
        ];
        $typeFilter = '';
        if ($type !== '') {
            $typeFilter = ' AND type = :type';
            $params['type'] = $type;
        }

        $bucket = RollupService::bucketExpr('time', $grid);

        $sql = 'SELECT time_min AS time, avg_value AS value, min_value AS vmin, max_value AS vmax, type'
            .' FROM smart_device_data_rollup'
            .' WHERE sid = :ain AND grid = :grid AND bucket >= :from AND bucket < :watermark'.$typeFilter
            .' UNION ALL'
            .' SELECT MIN(time) AS time, AVG(value) AS value, MIN(value) AS vmin, MAX(value) AS vmax, type'
            .' FROM smart_device_data'
            .' WHERE sid = :ain AND time >= :watermark AND time <= :to'.$typeFilter
            ." GROUP BY {$bucket}, type"
            .' ORDER BY time ASC';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Today's energy, integrated from power readings.
     *
     * The box reports energy once a day, so until it does the current day has
     * no bar at all. Trapezoidal integration over today's power fills it in.
     * Always reads raw: today is inside any retention window.
     *
     * @return array{time: string, value: float, type: string}|null
     */
    public function synthesiseTodayEnergy(string $ain, \DateTimeImmutable $now): ?array
    {
        $today = $now->setTime(0, 0);

        $powerRows = $this->connection->fetchAllAssociative(
            'SELECT time, value FROM smart_device_data'
            .' WHERE sid = :ain AND type = :ptype AND time >= :from AND time <= :to'
            .' ORDER BY time ASC',
            [
                'ain' => $ain,
                'ptype' => MetricUnits::TYPE_POWER,
                'from' => $today->format('Y-m-d').' 00:00:00',
                'to' => $today->format('Y-m-d').' 23:59:59',
            ],
        );

        if (\count($powerRows) < 2) {
            return null;
        }

        $energyWs = 0.0;
        for ($i = 1, $n = \count($powerRows); $i < $n; ++$i) {
            $t0 = (new \DateTimeImmutable((string) $powerRows[$i - 1]['time']))->getTimestamp();
            $t1 = (new \DateTimeImmutable((string) $powerRows[$i]['time']))->getTimestamp();
            // Average of two adjacent power readings (cW), convert to W
            $avgW = ((float) $powerRows[$i - 1]['value'] + (float) $powerRows[$i]['value']) / 2.0 / 100.0;
            $energyWs += $avgW * ($t1 - $t0);
        }

        return [
            'time' => $today->format(\DateTimeInterface::ATOM),
            'value' => round($energyWs / 3600.0, 1),
            'type' => MetricUnits::TYPE_ENERGY,
        ];
    }

    private static function sql(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
