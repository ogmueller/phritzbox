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

namespace App\Tests\Service\DataLifecycle;

use App\Service\DataLifecycle\RollupService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RollupServiceTest extends KernelTestCase
{
    private RollupService $rollup;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rollup = static::getContainer()->get(RollupService::class);
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function reading(string $sid, string $type, string $time, float $value): void
    {
        $this->conn->insert('smart_device_data', ['sid' => $sid, 'type' => $type, 'time' => $time, 'value' => $value]);
    }

    /** @return array<string, mixed>|false */
    private function bucketRow(string $sid, string $type, int $grid, string $bucket): array|false
    {
        return $this->conn->fetchAssociative(
            'SELECT * FROM smart_device_data_rollup WHERE sid = ? AND type = ? AND grid = ? AND bucket = ?',
            [$sid, $type, $grid, $bucket],
        );
    }

    public function testAggregatesRawIntoQuarterHourBuckets(): void
    {
        $sid = 'roll-basic';
        // Three readings inside 10:00–10:15, one in the next bucket.
        $this->reading($sid, 'power', '2026-08-01 10:01:00', 10.0);
        $this->reading($sid, 'power', '2026-08-01 10:07:00', 20.0);
        $this->reading($sid, 'power', '2026-08-01 10:14:00', 30.0);
        $this->reading($sid, 'power', '2026-08-01 10:16:00', 99.0);

        $this->rollup->rollUpFromRaw($sid, 'power');

        $first = $this->bucketRow($sid, 'power', RollupService::GRID_QUARTER, '2026-08-01 10:00:00');
        self::assertNotFalse($first);
        self::assertSame(20.0, (float) $first['avg_value']);
        self::assertSame(10.0, (float) $first['min_value']);
        self::assertSame(30.0, (float) $first['max_value']);
        self::assertSame(60.0, (float) $first['sum_value']);
        self::assertSame(3, (int) $first['sample_count']);
        // The reported timestamp is the first raw reading's, not the bucket edge —
        // this is what keeps a rollup-served point identical to a raw-served one.
        self::assertSame('2026-08-01 10:01:00', $first['time_min']);

        self::assertNotFalse($this->bucketRow($sid, 'power', RollupService::GRID_QUARTER, '2026-08-01 10:15:00'));
    }

    public function testBucketsAlignToQuarterHourBoundaries(): void
    {
        $sid = 'roll-align';
        foreach (['00:03', '15:02', '30:01', '45:59'] as $i => $mmss) {
            $this->reading($sid, 'power', '2026-08-01 10:'.$mmss, (float) $i);
        }

        $this->rollup->rollUpFromRaw($sid, 'power');

        $buckets = $this->conn->fetchFirstColumn(
            'SELECT bucket FROM smart_device_data_rollup WHERE sid = ? AND grid = ? ORDER BY bucket',
            [$sid, RollupService::GRID_QUARTER],
        );
        self::assertSame([
            '2026-08-01 10:00:00',
            '2026-08-01 10:15:00',
            '2026-08-01 10:30:00',
            '2026-08-01 10:45:00',
        ], $buckets);
    }

    public function testTemperatureIsLosslessOnTheQuarterHourGrid(): void
    {
        // The box emits temperature on its own 900s grid, so each bucket holds
        // exactly one reading and nothing is averaged away.
        $sid = 'roll-temp';
        $this->reading($sid, 'temperature', '2026-08-01 10:00:00', 21.5);
        $this->reading($sid, 'temperature', '2026-08-01 10:15:00', 21.0);
        $this->reading($sid, 'temperature', '2026-08-01 10:30:00', 22.5);

        $this->rollup->rollUpFromRaw($sid, 'temperature');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT avg_value, min_value, max_value, sample_count FROM smart_device_data_rollup'
            .' WHERE sid = ? AND grid = ? ORDER BY bucket',
            [$sid, RollupService::GRID_QUARTER],
        );
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['sample_count']);
            self::assertSame((float) $row['min_value'], (float) $row['avg_value']);
            self::assertSame((float) $row['max_value'], (float) $row['avg_value']);
        }
    }

    public function testDailyDerivedFromQuarterHoursEqualsTheRawAverage(): void
    {
        // The load-bearing arithmetic: a weighted mean over buckets of unequal
        // size must equal AVG() over the underlying rows. Deliberately uneven —
        // 3 readings in one bucket, 1 in another — so AVG(avg_value) would be
        // wrong and only SUM(sum)/SUM(count) is right.
        $sid = 'roll-daily';
        $this->reading($sid, 'power', '2026-08-01 10:01:00', 10.0);
        $this->reading($sid, 'power', '2026-08-01 10:02:00', 20.0);
        $this->reading($sid, 'power', '2026-08-01 10:03:00', 30.0);
        $this->reading($sid, 'power', '2026-08-01 11:00:00', 100.0);

        $this->rollup->rollUpPair($sid, 'power');

        $day = $this->bucketRow($sid, 'power', RollupService::GRID_DAY, '2026-08-01 00:00:00');
        self::assertNotFalse($day);

        $rawAvg = (float) $this->conn->fetchOne('SELECT AVG(value) FROM smart_device_data WHERE sid = ?', [$sid]);
        self::assertEqualsWithDelta($rawAvg, (float) $day['avg_value'], 0.000001);
        self::assertSame(40.0, (float) $day['avg_value']);
        self::assertSame(10.0, (float) $day['min_value']);
        self::assertSame(100.0, (float) $day['max_value']);
        self::assertSame(4, (int) $day['sample_count']);
    }

    public function testRunningTwiceChangesNothing(): void
    {
        $sid = 'roll-idempotent';
        $this->reading($sid, 'power', '2026-08-01 10:01:00', 10.0);
        $this->reading($sid, 'power', '2026-08-01 10:07:00', 20.0);

        $this->rollup->rollUpPair($sid, 'power');
        $first = $this->conn->fetchAllAssociative('SELECT * FROM smart_device_data_rollup WHERE sid = ? ORDER BY grid, bucket', [$sid]);

        $this->rollup->rollUpPair($sid, 'power');
        $second = $this->conn->fetchAllAssociative('SELECT * FROM smart_device_data_rollup WHERE sid = ? ORDER BY grid, bucket', [$sid]);

        self::assertSame($first, $second, 'a repeated rollup must be a no-op, not a double count');
    }

    public function testLateArrivingReadingRecomputesItsBucket(): void
    {
        // A reading that lands in an already-rolled bucket must correct it, not
        // be added on top of it.
        $sid = 'roll-late';
        $this->reading($sid, 'power', '2026-08-01 10:01:00', 10.0);
        $this->rollup->rollUpPair($sid, 'power');

        $this->reading($sid, 'power', '2026-08-01 10:05:00', 30.0);
        $this->rollup->rollUpPair($sid, 'power');

        $bucket = $this->bucketRow($sid, 'power', RollupService::GRID_QUARTER, '2026-08-01 10:00:00');
        self::assertSame(20.0, (float) $bucket['avg_value']);
        self::assertSame(2, (int) $bucket['sample_count']);
        self::assertSame(40.0, (float) $bucket['sum_value']);
    }

    public function testWindowBoundsRestrictWhatIsRolled(): void
    {
        $sid = 'roll-window';
        $this->reading($sid, 'power', '2026-08-01 10:00:00', 1.0);
        $this->reading($sid, 'power', '2026-08-02 10:00:00', 2.0);

        $this->rollup->rollUpFromRaw($sid, 'power', '2026-08-02 00:00:00', '2026-08-03 00:00:00');

        $buckets = $this->conn->fetchFirstColumn(
            'SELECT bucket FROM smart_device_data_rollup WHERE sid = ? AND grid = ?',
            [$sid, RollupService::GRID_QUARTER],
        );
        self::assertSame(['2026-08-02 10:00:00'], $buckets);
    }

    public function testPairsSkipsCombinationsThatHoldNoData(): void
    {
        $this->conn->insert('smart_device', [
            'ain' => 'roll-pairs', 'name' => 'x', 'manufacturer' => '', 'product_name' => '',
            'firmware_version' => '', 'function_bit_mask' => 0,
            'first_seen_at' => '2026-08-01 00:00:00', 'last_seen_at' => '2026-08-01 00:00:00',
        ]);
        $this->reading('roll-pairs', 'power', '2026-08-01 10:00:00', 1.0);

        $pairs = array_values(array_filter($this->rollup->pairs(), static fn (array $p): bool => $p['sid'] === 'roll-pairs'));

        self::assertSame([['sid' => 'roll-pairs', 'type' => 'power']], $pairs);
    }

    public function testWatermarkRoundTripsPerGrid(): void
    {
        $through = new \DateTimeImmutable('2026-08-01 10:00:00');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $through);

        self::assertSame(
            $through->getTimestamp(),
            $this->rollup->watermark(RollupService::GRID_QUARTER)?->getTimestamp(),
        );
        self::assertNull($this->rollup->watermark(RollupService::GRID_DAY), 'grids track their own watermark');
    }
}
