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

namespace App\Service\DataLifecycle;

use App\Service\MetricUnits;
use Doctrine\DBAL\Connection;

/**
 * Turns raw readings into pre-aggregated windows.
 *
 * The Fritz!Box samples power and voltage about every 24 seconds, so a year of
 * one outlet is well over a million rows — and every long report re-averages
 * them from scratch. This computes those averages once.
 *
 * Two grids are built. The quarter-hour tier is the workhorse: it matches the
 * box's own 900-second grid for temperature (so temperature survives losslessly)
 * and compresses power and voltage roughly 37:1. The daily tier is derived from
 * the quarter-hour one rather than from raw, which is both far cheaper and
 * exactly equal — SUM(sum_value)/SUM(sample_count) is the same number as
 * AVG(value) over the underlying rows.
 *
 * Every bucket is fully recomputed and upserted, never incremented. That makes
 * the whole thing idempotent: running it twice, or re-running an interrupted
 * backfill, cannot double-count.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class RollupService
{
    /** Quarter-hour tier, in seconds. Matches the box's own temperature grid. */
    public const GRID_QUARTER = 900;

    /** Daily tier, in seconds. */
    public const GRID_DAY = 86400;

    /** Coarser grids are derived from the grid they are keyed to here. */
    public const DERIVED_FROM = [self::GRID_DAY => self::GRID_QUARTER];

    public function __construct(
        private readonly Connection $connection,
        private readonly AppState $appState,
    ) {
    }

    /**
     * Floor a stored timestamp onto a grid boundary.
     *
     * strftime('%s') reads the stored string as if it were UTC. That is exactly
     * what is wanted: the arithmetic then happens in the same local clock the
     * readings are written in, so a boundary lands on local midnight rather than
     * drifting by the UTC offset. The daylight-saving behaviour is unchanged
     * from the query-time bucketing this replaces — a repeated hour merges into
     * one bucket, a skipped hour simply has none.
     */
    public static function bucketExpr(string $column, int $grid): string
    {
        return \sprintf("datetime((strftime('%%s', %s) / %d) * %d, 'unixepoch')", $column, $grid, $grid);
    }

    /** The start of the bucket a moment falls in. */
    public static function floorTo(\DateTimeImmutable $moment, int $grid): \DateTimeImmutable
    {
        return $moment->setTimestamp(intdiv($moment->getTimestamp(), $grid) * $grid);
    }

    /**
     * (sid, type) pairs that actually hold readings.
     *
     * Derived from smart_device rather than from the readings table: a
     * `SELECT DISTINCT sid, type FROM smart_device_data` cannot be answered from
     * the index and degenerates into a full scan of tens of millions of rows —
     * the same trap documented in SmartStatsCollectionService.
     *
     * @return list<array{sid: string, type: string}>
     */
    public function pairs(): array
    {
        $ains = $this->connection->fetchFirstColumn('SELECT ain FROM smart_device ORDER BY ain');

        $pairs = [];
        foreach ($ains as $ain) {
            foreach (MetricUnits::TYPES as $type) {
                // One index probe each; skips the many combinations that never occur.
                $exists = $this->connection->fetchOne(
                    'SELECT 1 FROM smart_device_data WHERE sid = :sid AND type = :type LIMIT 1',
                    ['sid' => $ain, 'type' => $type],
                );
                if ($exists !== false) {
                    $pairs[] = ['sid' => (string) $ain, 'type' => $type];
                }
            }
        }

        return $pairs;
    }

    /**
     * Aggregate raw readings of one (sid, type) into the quarter-hour grid.
     *
     * A bounded window keeps each transaction short; journal_mode is `delete`,
     * so a writer blocks readers for as long as it runs.
     *
     * @return int buckets written
     */
    public function rollUpFromRaw(string $sid, string $type, ?string $from = null, ?string $through = null): int
    {
        $bucket = self::bucketExpr('time', self::GRID_QUARTER);

        $sql = 'INSERT INTO smart_device_data_rollup'
            .' (sid, type, grid, bucket, time_min, avg_value, min_value, max_value, sum_value, sample_count)'
            ." SELECT sid, type, :grid, {$bucket}, MIN(time), AVG(value), MIN(value), MAX(value), SUM(value), COUNT(*)"
            .' FROM smart_device_data'
            .' WHERE sid = :sid AND type = :type';

        $params = ['grid' => self::GRID_QUARTER, 'sid' => $sid, 'type' => $type];
        if ($from !== null) {
            $sql .= ' AND time >= :from';
            $params['from'] = $from;
        }
        if ($through !== null) {
            $sql .= ' AND time < :through';
            $params['through'] = $through;
        }

        $sql .= ' GROUP BY 4'.self::upsertTail();

        return (int) $this->connection->executeStatement($sql, $params);
    }

    /**
     * Derive a coarser grid from a finer one.
     *
     * @return int buckets written
     */
    public function rollUpFromGrid(string $sid, string $type, int $targetGrid, ?string $from = null, ?string $through = null): int
    {
        $sourceGrid = self::DERIVED_FROM[$targetGrid]
            ?? throw new \InvalidArgumentException(\sprintf('No source grid defined for %d', $targetGrid));

        $bucket = self::bucketExpr('bucket', $targetGrid);

        $sql = 'INSERT INTO smart_device_data_rollup'
            .' (sid, type, grid, bucket, time_min, avg_value, min_value, max_value, sum_value, sample_count)'
            ." SELECT sid, type, :targetGrid, {$bucket}, MIN(time_min),"
            // Weighted mean, not AVG(avg_value): buckets with different sample
            // counts must not be given equal weight, or the result stops
            // matching what raw would have produced.
            .' SUM(sum_value) / SUM(sample_count), MIN(min_value), MAX(max_value), SUM(sum_value), SUM(sample_count)'
            .' FROM smart_device_data_rollup'
            .' WHERE sid = :sid AND type = :type AND grid = :sourceGrid';

        $params = [
            'targetGrid' => $targetGrid,
            'sourceGrid' => $sourceGrid,
            'sid' => $sid,
            'type' => $type,
        ];
        if ($from !== null) {
            $sql .= ' AND bucket >= :from';
            $params['from'] = $from;
        }
        if ($through !== null) {
            $sql .= ' AND bucket < :through';
            $params['through'] = $through;
        }

        $sql .= ' GROUP BY 4'.self::upsertTail();

        return (int) $this->connection->executeStatement($sql, $params);
    }

    /**
     * Recompute a pair across every grid, oldest tier first.
     *
     * @return int buckets written across all grids
     */
    public function rollUpPair(string $sid, string $type, ?string $from = null, ?string $through = null): int
    {
        $written = $this->rollUpFromRaw($sid, $type, $from, $through);

        foreach (array_keys(self::DERIVED_FROM) as $grid) {
            // Bounds are timestamps in both cases, so the same window applies.
            $written += $this->rollUpFromGrid($sid, $type, $grid, $from, $through);
        }

        return $written;
    }

    public function watermarkKey(int $grid): string
    {
        return AppState::ROLLUP_THROUGH_PREFIX.$grid;
    }

    public function watermark(int $grid): ?\DateTimeImmutable
    {
        return $this->appState->getInstant($this->watermarkKey($grid));
    }

    public function setWatermark(int $grid, \DateTimeImmutable $through): void
    {
        $this->appState->setInstant($this->watermarkKey($grid), $through);
    }

    /**
     * The upsert tail shared by both directions.
     *
     * Recompute-and-replace rather than accumulate — that is what makes a
     * repeated or overlapping run a no-op instead of a double count.
     */
    private static function upsertTail(): string
    {
        return ' ON CONFLICT(sid, type, grid, bucket) DO UPDATE SET'
            .' time_min = excluded.time_min,'
            .' avg_value = excluded.avg_value,'
            .' min_value = excluded.min_value,'
            .' max_value = excluded.max_value,'
            .' sum_value = excluded.sum_value,'
            .' sample_count = excluded.sample_count';
    }
}
