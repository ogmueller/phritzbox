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
use App\Service\EnergyCostService;
use App\Service\TariffSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EnergyCostServiceTest extends KernelTestCase
{
    private EnergyCostService $cost;
    private RollupService $rollup;
    private TariffSettings $tariff;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->cost = static::getContainer()->get(EnergyCostService::class);
        $this->rollup = static::getContainer()->get(RollupService::class);
        $this->tariff = static::getContainer()->get(TariffSettings::class);
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function device(string $ain, string $name = 'Outlet'): void
    {
        $this->conn->insert('smart_device', [
            'ain' => $ain, 'name' => $name, 'manufacturer' => '', 'product_name' => '',
            'firmware_version' => '', 'function_bit_mask' => 0,
            'first_seen_at' => '2020-01-01 00:00:00', 'last_seen_at' => '2020-01-01 00:00:00',
        ]);
    }

    /** One energy row per day, as the collector writes them. */
    private function energy(string $ain, string $day, float $wh): void
    {
        $this->conn->insert('smart_device_data', [
            'sid' => $ain, 'type' => 'energy', 'time' => $day.' 00:00:00', 'value' => $wh,
        ]);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function deviceEntry(array $result, string $ain): array
    {
        foreach ($result['devices'] as $entry) {
            if ($entry['ain'] === $ain) {
                return $entry;
            }
        }
        self::fail("device {$ain} missing from the breakdown");
    }

    /**
     * The invariant the boundary logic exists for: the pre-aggregated half and
     * the raw half must partition the range, never overlap it.
     *
     * The energy rows here are stamped at 01:00, not midnight. That is not
     * contrived — the live database holds hundreds of such rows from before the
     * collector settled on UTC, and it is the only shape that exposes the bug:
     * with a watermark between midnight and the row's own time, an unfloored
     * boundary lets the whole-day bucket into the rollup half *and* the row
     * itself into the raw half, counting that day twice.
     */
    public function testRollupAndRawHalvesDoNotDoubleCountAcrossTheWatermark(): void
    {
        $this->device('ec-boundary');
        foreach (range(1, 10) as $d) {
            $this->conn->insert('smart_device_data', [
                'sid' => 'ec-boundary',
                'type' => 'energy',
                'time' => \sprintf('2026-06-%02d 01:00:00', $d),
                'value' => 100.0,
            ]);
        }
        $this->rollup->rollUpPair('ec-boundary', 'energy');
        // Between the day boundary and the readings' own timestamp.
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-06-05 00:30:00'));

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-10 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        $entry = $this->deviceEntry($result, 'ec-boundary');
        self::assertSame(1000.0, $entry['energyWh'], '10 days x 100 Wh, counted once each');
        self::assertSame(10, $entry['coverage']['daysWithData'], 'no day may be counted twice');
    }

    /**
     * The same partition, with the midnight-stamped rows the collector writes
     * today and the mid-day watermark the rollup job leaves behind.
     */
    public function testAMidDayWatermarkWithMidnightRowsAlsoTotalsCorrectly(): void
    {
        $this->device('ec-boundary-midnight');
        foreach (range(1, 10) as $d) {
            $this->energy('ec-boundary-midnight', \sprintf('2026-06-%02d', $d), 100.0);
        }
        $this->rollup->rollUpPair('ec-boundary-midnight', 'energy');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-06-05 14:30:00'));

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-10 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        self::assertSame(1000.0, $this->deviceEntry($result, 'ec-boundary-midnight')['energyWh']);
    }

    /**
     * Retention prunes energy too, so once raw is gone the daily rollup is the
     * only source left. A raw-only query would silently read zero.
     */
    public function testTotalIsUnchangedWhenRawIsPruned(): void
    {
        $this->device('ec-pruned');
        foreach (range(1, 10) as $d) {
            $this->energy('ec-pruned', \sprintf('2026-06-%02d', $d), 100.0);
        }
        $this->rollup->rollUpPair('ec-pruned', 'energy');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable('2026-06-11 00:00:00'));

        $from = new \DateTimeImmutable('2026-06-01 00:00:00');
        $to = new \DateTimeImmutable('2026-06-10 23:59:59');
        $now = new \DateTimeImmutable('2026-07-01 12:00:00');
        $before = $this->cost->costForRange($from, $to, $now);

        $this->conn->executeStatement(
            "DELETE FROM smart_device_data WHERE sid = 'ec-pruned' AND type = 'energy' AND time < '2026-06-05'",
        );
        $after = $this->cost->costForRange($from, $to, $now);

        self::assertSame(
            $this->deviceEntry($before, 'ec-pruned')['energyWh'],
            $this->deviceEntry($after, 'ec-pruned')['energyWh'],
        );
    }

    public function testGapsAreReported(): void
    {
        $this->device('ec-gap');
        // Days 1, 2, 4, 5 — day 3 genuinely missing.
        foreach ([1, 2, 4, 5] as $d) {
            $this->energy('ec-gap', \sprintf('2026-06-%02d', $d), 100.0);
        }

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-05 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        $coverage = $this->deviceEntry($result, 'ec-gap')['coverage'];
        self::assertSame(5, $coverage['daysInRange']);
        self::assertSame(4, $coverage['daysWithData']);
        self::assertSame(1, $coverage['gapDays']);
    }

    /**
     * A device installed mid-range has fewer days than the range spans but has
     * lost nothing. Warning on that would cry wolf on every newly added device.
     */
    public function testADeviceStartingMidRangeReportsNoGap(): void
    {
        $this->device('ec-new');
        foreach ([8, 9, 10] as $d) {
            $this->energy('ec-new', \sprintf('2026-06-%02d', $d), 100.0);
        }

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-10 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        $coverage = $this->deviceEntry($result, 'ec-new')['coverage'];
        self::assertSame(10, $coverage['daysInRange']);
        self::assertSame(3, $coverage['daysWithData']);
        self::assertSame(0, $coverage['gapDays'], 'nothing was lost — the device did not exist yet');
    }

    public function testZeroDaysCountAsDataButAreReportedSeparately(): void
    {
        // A 0 does not reliably mean "consumed nothing", so it is surfaced.
        $this->device('ec-zero');
        $this->energy('ec-zero', '2026-06-01', 100.0);
        $this->energy('ec-zero', '2026-06-02', 0.0);
        $this->energy('ec-zero', '2026-06-03', 50.0);

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-03 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        $coverage = $this->deviceEntry($result, 'ec-zero')['coverage'];
        self::assertSame(3, $coverage['daysWithData']);
        self::assertSame(0, $coverage['gapDays']);
        self::assertSame(1, $coverage['zeroDays']);
    }

    public function testWithoutATariffEnergyIsReportedButCostIsNull(): void
    {
        $this->device('ec-notariff');
        $this->energy('ec-notariff', '2026-06-01', 1000.0);

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-01 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        self::assertFalse($result['configured']);
        self::assertSame(1000.0, $this->deviceEntry($result, 'ec-notariff')['energyWh']);
        self::assertNull($this->deviceEntry($result, 'ec-notariff')['cost'], 'never 0.00 for an unset tariff');
        self::assertNull($result['household']['energyCost']);
        self::assertNull($result['household']['standingCost']);
        self::assertNull($result['household']['cost']);
    }

    public function testCostUsesKwh(): void
    {
        $this->tariff->save(0.30, 0.0, 'EUR');
        $this->device('ec-price');
        $this->energy('ec-price', '2026-06-01', 2500.0);

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-01 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        // 2500 Wh = 2.5 kWh x 0.30 = 0.75
        self::assertSame(0.75, $this->deviceEntry($result, 'ec-price')['cost']);
    }

    public function testTheStandingChargeIsHouseholdOnly(): void
    {
        $this->tariff->save(0.30, 20.0, 'EUR');
        $this->device('ec-standing');
        $this->energy('ec-standing', '2026-06-01', 1000.0);

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-30 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        $entry = $this->deviceEntry($result, 'ec-standing');
        // 1 kWh x 0.30, with no share of the connection charge.
        self::assertSame(0.30, $entry['cost']);
        self::assertSame(20.0, $result['household']['standingCost']);
        self::assertSame(20.30, $result['household']['cost']);
    }

    /**
     * A whole calendar month must come out at exactly the figure on the bill —
     * the number people check first. A flat 30.44-day divisor would give 20.37
     * for a 31-day month and look broken.
     */
    public function testAWholeMonthProratesToExactlyTheMonthlyCharge(): void
    {
        $this->tariff->save(0.30, 20.0, 'EUR');

        foreach ([['2026-06-01', '2026-06-30', 30], ['2026-07-01', '2026-07-31', 31]] as [$start, $end, $days]) {
            $result = $this->cost->costForRange(
                new \DateTimeImmutable($start.' 00:00:00'),
                new \DateTimeImmutable($end.' 23:59:59'),
                new \DateTimeImmutable('2026-09-01 12:00:00'),
            );
            self::assertSame(20.0, $result['household']['standingCost'], "{$days}-day month");
        }
    }

    public function testAPartialMonthProratesByThatMonthsOwnDayCount(): void
    {
        $this->tariff->save(0.30, 30.0, 'EUR');

        // 10 of June's 30 days => a third of the charge.
        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-10 23:59:59'),
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );

        self::assertSame(10.0, $result['household']['standingCost']);
    }

    public function testARangeSpanningTwoMonthsSumsBothProrations(): void
    {
        $this->tariff->save(0.30, 30.0, 'EUR');

        // Last 10 days of June (10/30) + first 10 of July (10/31).
        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-21 00:00:00'),
            new \DateTimeImmutable('2026-07-10 23:59:59'),
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );

        self::assertEqualsWithDelta(10.0 + 30.0 * 10 / 31, $result['household']['standingCost'], 0.01);
    }

    public function testWorksWithNoRollupAtAll(): void
    {
        // Graceful degradation: an install whose rollup job never ran must still
        // get a correct total rather than a silent zero.
        $this->device('ec-noroll');
        $this->energy('ec-noroll', '2026-06-01', 100.0);
        $this->energy('ec-noroll', '2026-06-02', 200.0);

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-02 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        self::assertSame(300.0, $this->deviceEntry($result, 'ec-noroll')['energyWh']);
    }

    public function testTheDayShiftFootnoteOnlyFiresForRangesThatStraddleIt(): void
    {
        $before = $this->cost->costForRange(
            new \DateTimeImmutable('2026-05-01 00:00:00'),
            new \DateTimeImmutable('2026-06-01 23:59:59'),
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );
        $across = $this->cost->costForRange(
            new \DateTimeImmutable('2026-07-01 00:00:00'),
            new \DateTimeImmutable('2026-08-01 23:59:59'),
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );
        $after = $this->cost->costForRange(
            new \DateTimeImmutable('2026-08-01 00:00:00'),
            new \DateTimeImmutable('2026-08-20 23:59:59'),
            new \DateTimeImmutable('2026-09-01 12:00:00'),
        );

        self::assertFalse($before['spansCollectorChange']);
        self::assertTrue($across['spansCollectorChange']);
        self::assertFalse($after['spansCollectorChange']);
    }

    public function testDevicesWithNoEnergyDataAreOmitted(): void
    {
        // A DECT repeater has no power meter; listing it with zeros is noise.
        $this->device('ec-silent', 'Repeater');

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-02 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        self::assertSame([], array_filter(
            $result['devices'],
            static fn (array $d): bool => $d['ain'] === 'ec-silent',
        ));
    }

    public function testTheBreakdownIsOrderedByConsumption(): void
    {
        $this->device('ec-small', 'Small');
        $this->device('ec-big', 'Big');
        $this->energy('ec-small', '2026-06-01', 100.0);
        $this->energy('ec-big', '2026-06-01', 900.0);

        $result = $this->cost->costForRange(
            new \DateTimeImmutable('2026-06-01 00:00:00'),
            new \DateTimeImmutable('2026-06-01 23:59:59'),
            new \DateTimeImmutable('2026-07-01 12:00:00'),
        );

        $ains = array_column($result['devices'], 'ain');
        self::assertSame(['ec-big', 'ec-small'], array_values(array_intersect($ains, ['ec-big', 'ec-small'])));
    }

    /**
     * What the dashboard's "This month" tile actually bills.
     *
     * The month-to-date window ends today, not on the last of the month, so the
     * standing charge in that headline is a fraction that grows daily — the
     * figure looks wrong to anyone expecting the whole monthly charge on the
     * 1st, which is exactly why it is pinned here and documented in Help.
     */
    public function testMonthToDateChargesTheStandingChargeOnlyForTheDaysElapsed(): void
    {
        $this->tariff->save(0.30, 30.0, 'EUR');

        // The 18th of a 30-day month: 18/30 of EUR 30.00.
        $summary = $this->cost->summary(new \DateTimeImmutable('2026-06-18 09:00:00'));

        self::assertSame(18.0, $summary['monthToDate']['standingCost']);
    }

    public function testSummaryReportsTodayMonthToDateAndTopConsumer(): void
    {
        $this->tariff->save(0.30, 30.0, 'EUR');
        $now = new \DateTimeImmutable();
        $this->device('ec-sum-big', 'Big');
        $this->device('ec-sum-small', 'Small');
        // Yesterday, so it lands inside month-to-date without relying on today.
        $day = $now->modify('-1 day')->format('Y-m-d');
        if ($day < $now->format('Y-m-01')) {
            self::markTestSkipped('run on the first of a month');
        }
        $this->energy('ec-sum-big', $day, 5000.0);
        $this->energy('ec-sum-small', $day, 100.0);

        $summary = $this->cost->summary($now);

        self::assertTrue($summary['configured']);
        self::assertSame('EUR', $summary['currency']);
        self::assertGreaterThanOrEqual(5100.0, $summary['monthToDate']['energyWh']);
        self::assertNotNull($summary['topConsumer']);
        self::assertSame('ec-sum-big', $summary['topConsumer']['ain']);
        self::assertArrayHasKey('estimated', $summary['today']);
    }
}
