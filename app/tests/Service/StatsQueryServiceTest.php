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

namespace App\Tests\Service;

use App\Service\DataLifecycle\RollupService;
use App\Service\StatsQueryService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StatsQueryServiceTest extends KernelTestCase
{
    private StatsQueryService $stats;
    private RollupService $rollup;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->stats = static::getContainer()->get(StatsQueryService::class);
        $this->rollup = static::getContainer()->get(RollupService::class);
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function reading(string $sid, string $type, string $time, float $value): void
    {
        $this->conn->insert('smart_device_data', ['sid' => $sid, 'type' => $type, 'time' => $time, 'value' => $value]);
    }

    /** Three days of readings every 10 minutes, with a value that varies. */
    private function seed(string $sid, string $type = 'power'): void
    {
        $t = new \DateTimeImmutable('2026-06-01 00:00:00');
        for ($i = 0; $i < 3 * 24 * 6; ++$i) {
            $this->reading($sid, $type, $t->format('Y-m-d H:i:s'), (float) (($i * 7) % 100));
            $t = $t->modify('+10 minutes');
        }
    }

    /**
     * The invariant the whole phase rests on: whether a query is answered from
     * raw readings or from pre-aggregated buckets must not change the answer.
     */
    public function testAggregatedPayloadIsIdenticalWithAndWithoutRollups(): void
    {
        $sid = 'sq-identical';
        $this->seed($sid);
        $from = new \DateTimeImmutable('2026-06-01 00:00:00');
        $to = new \DateTimeImmutable('2026-06-03 23:59:59');

        // No rollup rows yet: the union collapses to the raw half, which is what
        // the read path did before rollups existed.
        $before = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_QUARTER);
        self::assertNotEmpty($before);

        $this->rollup->rollUpPair($sid, 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-07-01 00:00:00'));

        $after = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_QUARTER);

        self::assertSame($before, $after, 'a rollup-served payload must equal the raw-served one');
    }

    public function testDailyPayloadIsIdenticalWithAndWithoutRollups(): void
    {
        $sid = 'sq-identical-day';
        $this->seed($sid);
        $from = new \DateTimeImmutable('2026-06-01 00:00:00');
        $to = new \DateTimeImmutable('2026-06-03 23:59:59');

        $before = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_DAY);

        $this->rollup->rollUpPair($sid, 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-07-01 00:00:00'));

        $after = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_DAY);

        self::assertSame($before, $after);
    }

    /**
     * The union boundary: half the window comes from buckets, half from raw.
     * If the two halves overlapped or left a gap, this is where it would show.
     */
    public function testWatermarkFallingMidWindowStillMatches(): void
    {
        $sid = 'sq-boundary';
        $this->seed($sid);
        $from = new \DateTimeImmutable('2026-06-01 00:00:00');
        $to = new \DateTimeImmutable('2026-06-03 23:59:59');

        $expected = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_QUARTER);

        $this->rollup->rollUpPair($sid, 'power');
        // Mid-window, on a bucket boundary — where the incremental job leaves it.
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-06-02 12:00:00'));

        $split = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_QUARTER);

        self::assertSame($expected, $split, 'the union must not double-count or drop a bucket');
    }

    public function testAWindowStartingMidBucketLosesNoReadings(): void
    {
        // A rolling window rarely begins on a bucket boundary. The bucket
        // containing `from` must still appear, from whichever half serves it.
        $sid = 'sq-partial';
        $this->seed($sid);
        $from = new \DateTimeImmutable('2026-06-01 00:07:00');
        $to = new \DateTimeImmutable('2026-06-03 23:59:59');

        $expected = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_QUARTER);

        $this->rollup->rollUpPair($sid, 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-07-01 00:00:00'));

        $actual = $this->stats->fetch($sid, 'power', $from, $to, StatsQueryService::RESOLUTION_QUARTER);

        self::assertSame($expected, $actual);
        // The bucket containing `from` is present, reported from its own start.
        self::assertStringStartsWith('2026-06-01T00:00:00', $actual[0]['time']);
    }

    public function testAggregatedPointsCarryTrueExtremes(): void
    {
        $sid = 'sq-extremes';
        // One quarter-hour holding a spike that an average alone would hide.
        $this->reading($sid, 'power', '2026-06-01 10:00:00', 10.0);
        $this->reading($sid, 'power', '2026-06-01 10:05:00', 2000.0);
        $this->reading($sid, 'power', '2026-06-01 10:10:00', 10.0);
        $this->rollup->rollUpPair($sid, 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-07-01 00:00:00'));

        $points = $this->stats->fetch(
            $sid,
            'power',
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-05 00:00:00'),
            StatsQueryService::RESOLUTION_QUARTER,
        );

        self::assertCount(1, $points);
        // Display units: power is stored in centiwatts, so /100.
        // (10 + 2000 + 10) / 3 = 673.33 cW = 6.73 W.
        self::assertEqualsWithDelta(6.73, $points[0]['value'], 0.01);
        self::assertEqualsWithDelta(0.1, $points[0]['min'], 0.01);
        // The 2000 cW spike survives the averaging — that is what min/max are for.
        self::assertEqualsWithDelta(20.0, $points[0]['max'], 0.01);
    }

    public function testRawPointsCarryNoExtremes(): void
    {
        // On raw data the point is the extreme; repeating it would be noise.
        $sid = 'sq-raw';
        $this->reading($sid, 'power', '2026-06-01 10:00:00', 10.0);

        $points = $this->stats->fetch(
            $sid,
            'power',
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-01 23:59:59'),
            StatsQueryService::RESOLUTION_RAW,
        );

        self::assertCount(1, $points);
        self::assertArrayNotHasKey('min', $points[0]);
        self::assertArrayNotHasKey('max', $points[0]);
    }

    public function testResolutionLadderMatchesTheThresholdsTheUiRelieson(): void
    {
        $now = new \DateTimeImmutable('2026-06-30 12:00:00');
        $ladder = fn (string $span): string => $this->stats->resolutionFor(
            $now->modify('-'.$span),
            $now,
            $now,
        );

        self::assertSame(StatsQueryService::RESOLUTION_RAW, $ladder('24 hours'));
        self::assertSame(StatsQueryService::RESOLUTION_RAW, $ladder('2 days'));
        self::assertSame(StatsQueryService::RESOLUTION_QUARTER, $ladder('7 days'));
        self::assertSame(StatsQueryService::RESOLUTION_QUARTER, $ladder('30 days'));
        self::assertSame(StatsQueryService::RESOLUTION_DAY, $ladder('90 days'));
        self::assertSame(StatsQueryService::RESOLUTION_DAY, $ladder('400 days'));
    }

    public function testRetentionIsNotConsultedWhileNothingIsPruned(): void
    {
        // Default config keeps everything, so a narrow window years back is
        // still answered from raw — the ladder behaves exactly as it always did.
        $now = new \DateTimeImmutable('2026-06-30 12:00:00');

        self::assertSame(
            StatsQueryService::RESOLUTION_RAW,
            $this->stats->resolutionFor($now->modify('-5 years'), $now->modify('-5 years +1 day'), $now),
        );
    }
}
