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
use App\Service\StandbyService;
use App\Service\TariffSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StandbyServiceTest extends KernelTestCase
{
    private StandbyService $standby;
    private RollupService $rollup;
    private TariffSettings $tariff;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->standby = static::getContainer()->get(StandbyService::class);
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

    /**
     * Power readings every 10 minutes over `$days`, in centiwatts.
     *
     * @param callable(int): float $watts index → watts
     */
    private function power(string $ain, \DateTimeImmutable $now, int $days, callable $watts): void
    {
        $t = $now->modify(\sprintf('-%d days', $days))->modify('+10 minutes');
        $i = 0;
        while ($t < $now) {
            $this->conn->insert('smart_device_data', [
                'sid' => $ain,
                'type' => 'power',
                'time' => $t->format('Y-m-d H:i:s'),
                // Stored in centiwatts.
                'value' => $watts($i) * 100,
            ]);
            $t = $t->modify('+10 minutes');
            ++$i;
        }
    }

    public function testIdleFloorIsFoundBeneathHeavyUse(): void
    {
        // A device idling at 2 W that spends a third of its time at 500 W. The
        // mean would be ~170 W; the floor is 2 W.
        $now = new \DateTimeImmutable();
        $this->device('sb-floor');
        $this->power('sb-floor', $now, 7, static fn (int $i): float => $i % 3 === 0 ? 500.0 : 2.0);
        $this->rollup->rollUpPair('sb-floor', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-floor', $now);

        self::assertNotNull($estimate);
        self::assertEqualsWithDelta(2.0, $estimate['watts'], 0.01);
        self::assertSame('rollup', $estimate['source']);
    }

    public function testASingleDropoutToZeroDoesNotDecideTheFloor(): void
    {
        // Why the 5th percentile rather than MIN(): one dropped reading must not
        // report a device that always draws 10 W as drawing nothing.
        $now = new \DateTimeImmutable();
        $this->device('sb-dropout');
        $this->power('sb-dropout', $now, 7, static fn (int $i): float => $i === 5 ? 0.0 : 10.0);
        $this->rollup->rollUpPair('sb-dropout', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-dropout', $now);

        self::assertNotNull($estimate);
        self::assertEqualsWithDelta(10.0, $estimate['watts'], 0.01);
    }

    public function testASwitchedOffDeviceReportsZero(): void
    {
        // Honest rather than useful: it costs nothing while it is off.
        $now = new \DateTimeImmutable();
        $this->device('sb-off');
        $this->power('sb-off', $now, 7, static fn (): float => 0.0);
        $this->rollup->rollUpPair('sb-off', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-off', $now);

        self::assertNotNull($estimate);
        self::assertSame(0.0, $estimate['watts']);
        self::assertSame(0.0, $estimate['annualKwh']);
    }

    public function testAnnualProjectionAndCost(): void
    {
        $this->tariff->save(0.30, 0.0, 'EUR');
        $now = new \DateTimeImmutable();
        $this->device('sb-cost');
        $this->power('sb-cost', $now, 7, static fn (): float => 5.0);
        $this->rollup->rollUpPair('sb-cost', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-cost', $now);

        self::assertNotNull($estimate);
        // 5 W x 8760 h = 43.8 kWh; x 0.30 = 13.14
        self::assertEqualsWithDelta(43.8, $estimate['annualKwh'], 0.1);
        self::assertEqualsWithDelta(13.14, $estimate['annualCost'], 0.05);
        // A money field must carry its unit, or the caller has to guess it.
        self::assertSame('EUR', $estimate['currency']);
    }

    public function testCurrencyIsReportedEvenWhenTheAmountIsUnknown(): void
    {
        // The UI needs the code to format the *example* it shows before a price
        // is entered, so it cannot be conditional on the amount existing.
        $this->tariff->save(null, 0.0, 'CHF');
        $now = new \DateTimeImmutable();
        $this->device('sb-currency');
        $this->power('sb-currency', $now, 7, static fn (): float => 5.0);
        $this->rollup->rollUpPair('sb-currency', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-currency', $now);

        self::assertNotNull($estimate);
        self::assertNull($estimate['annualCost']);
        self::assertSame('CHF', $estimate['currency']);
    }

    public function testCostIsNullWithoutATariff(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-notariff');
        $this->power('sb-notariff', $now, 7, static fn (): float => 5.0);
        $this->rollup->rollUpPair('sb-notariff', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-notariff', $now);

        self::assertNotNull($estimate);
        self::assertGreaterThan(0.0, $estimate['annualKwh']);
        self::assertNull($estimate['annualCost'], 'never 0.00 for an unset tariff');
    }

    public function testTooLittleDataReportsNothingRatherThanAGuess(): void
    {
        // A couple of hours of history has no meaningful weekly floor.
        $now = new \DateTimeImmutable();
        $this->device('sb-thin');
        foreach (range(1, 5) as $i) {
            $this->conn->insert('smart_device_data', [
                'sid' => 'sb-thin', 'type' => 'power',
                'time' => $now->modify(\sprintf('-%d minutes', $i * 10))->format('Y-m-d H:i:s'),
                'value' => 500.0,
            ]);
        }

        self::assertNull($this->standby->forDevice('sb-thin', $now));
    }

    public function testFallsBackToRawWhenTheRollupHasNotRun(): void
    {
        // A fresh installation still gets a figure.
        $now = new \DateTimeImmutable();
        $this->device('sb-raw');
        $this->power('sb-raw', $now, 7, static fn (): float => 3.0);

        $estimate = $this->standby->forDevice('sb-raw', $now);

        self::assertNotNull($estimate);
        self::assertSame('raw', $estimate['source']);
        self::assertEqualsWithDelta(3.0, $estimate['watts'], 0.01);
    }

    public function testHouseholdSumsEveryDevicesFloor(): void
    {
        $this->tariff->save(0.30, 0.0, 'EUR');
        $now = new \DateTimeImmutable();
        $this->device('sb-h1');
        $this->device('sb-h2');
        $this->power('sb-h1', $now, 7, static fn (): float => 4.0);
        $this->power('sb-h2', $now, 7, static fn (): float => 6.0);
        $this->rollup->rollUpPair('sb-h1', 'power');
        $this->rollup->rollUpPair('sb-h2', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $household = $this->standby->household($now);

        self::assertGreaterThanOrEqual(10.0, $household['watts']);
        self::assertGreaterThanOrEqual(2, $household['devices']);
        self::assertNotNull($household['annualCost']);
    }

    /**
     * The case the round-the-clock floor cannot describe: an appliance that is
     * switched off most of the week but draws a real 14 W whenever it is on.
     * `watts` is 0 — correct, and useless for "what does it draw when idle".
     */
    public function testAnApplianceThatIsMostlyOffReportsItsIdleDrawSeparately(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-duty');
        // On for one index in four, at 14 W; off the rest of the time.
        $this->power('sb-duty', $now, 7, static fn (int $i): float => $i % 4 === 0 ? 14.0 : 0.0);
        $this->rollup->rollUpPair('sb-duty', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-duty', $now);

        self::assertNotNull($estimate);
        self::assertSame(0.0, $estimate['watts'], 'the round-the-clock floor is still zero');
        self::assertNotNull($estimate['idleWatts']);
        self::assertEqualsWithDelta(14.0, $estimate['idleWatts'], 0.01);
        self::assertGreaterThan(0.0, $estimate['dutyCyclePercent']);
        self::assertLessThan(100.0, $estimate['dutyCyclePercent']);
    }

    public function testADeviceThatIsNeverOnHasNoIdleFigureAndAZeroDutyCycle(): void
    {
        // Null, not 0.0: "never on, so we cannot say" is a different claim from
        // "it idles at nothing".
        $now = new \DateTimeImmutable();
        $this->device('sb-neveron');
        $this->power('sb-neveron', $now, 7, static fn (): float => 0.0);
        $this->rollup->rollUpPair('sb-neveron', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-neveron', $now);

        self::assertNotNull($estimate);
        self::assertSame(0.0, $estimate['dutyCyclePercent']);
        self::assertNull($estimate['idleWatts']);
    }

    public function testADeviceThatNeverSwitchesOffIdlesAtItsFloor(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-always');
        $this->power('sb-always', $now, 7, static fn (int $i): float => $i % 3 === 0 ? 90.0 : 12.0);
        $this->rollup->rollUpPair('sb-always', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-always', $now);

        self::assertNotNull($estimate);
        self::assertSame(100.0, $estimate['dutyCyclePercent']);
        // Nothing was excluded, so the two percentiles are the same figure.
        self::assertSame($estimate['watts'], $estimate['idleWatts']);
    }

    /**
     * Under twenty on-samples `offsetFor()` skips nothing, so the "5th
     * percentile" would just be the minimum — the one estimator the percentile
     * was chosen to avoid. Report nothing instead.
     */
    public function testTooBriefAnOnPeriodQuotesNoIdleFigure(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-brief');
        // 10-minute readings over 7 days: ~1008 samples, 10 of them on.
        $this->power('sb-brief', $now, 7, static fn (int $i): float => $i < 10 ? 30.0 : 0.0);
        $this->rollup->rollUpPair('sb-brief', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-brief', $now);

        self::assertNotNull($estimate);
        self::assertNull($estimate['idleWatts']);
        self::assertGreaterThan(0.0, $estimate['dutyCyclePercent'], 'the duty cycle is still reported');
    }

    /**
     * The case fifteen-minute bucketing loses: an appliance run in blocks leaves
     * hundreds of on-readings but only a handful of buckets that are on for the
     * *whole* quarter hour — too few for a percentile. The floor still comes
     * from the summary tier; only the idle figure drops to raw.
     */
    public function testTheIdleDrawFallsBackToRawWhenTheRollupHasTooFewWholeOnBuckets(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-hybrid');
        // 10-minute readings: 25 consecutive on-readings is ~4 hours, which is
        // plenty of raw samples but well under twenty whole-on buckets.
        $this->power('sb-hybrid', $now, 7, static fn (int $i): float => $i < 25 ? 14.0 : 0.0);
        $this->rollup->rollUpPair('sb-hybrid', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-hybrid', $now);

        self::assertNotNull($estimate);
        self::assertSame('rollup', $estimate['source'], 'the floor still comes from the summary tier');
        self::assertSame('raw', $estimate['idleSource'], 'only the idle figure fell back');
        self::assertEqualsWithDelta(14.0, $estimate['idleWatts'], 0.01);
        self::assertSame(0.0, $estimate['watts']);
    }

    public function testADeviceThatWasNeverOnIsNotChasedIntoTheRawTier(): void
    {
        // Nothing to find, and this runs per device on every dashboard poll, so
        // the rollup's own "was it ever on" count has to short-circuit it.
        $now = new \DateTimeImmutable();
        $this->device('sb-nofallback');
        $this->power('sb-nofallback', $now, 7, static fn (): float => 0.0);
        $this->rollup->rollUpPair('sb-nofallback', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $estimate = $this->standby->forDevice('sb-nofallback', $now);

        self::assertNotNull($estimate);
        self::assertNull($estimate['idleWatts']);
        self::assertNull($estimate['idleSource']);
    }

    public function testTheFallbackDegradesToNullOnceRawReadingsArePruned(): void
    {
        // Retention removes raw rows long before summary buckets. The idle
        // figure then simply stops being quoted; the floor is unaffected.
        $now = new \DateTimeImmutable();
        $this->device('sb-pruned');
        $this->power('sb-pruned', $now, 7, static fn (int $i): float => $i < 25 ? 14.0 : 0.0);
        $this->rollup->rollUpPair('sb-pruned', 'power');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);
        $this->conn->delete('smart_device_data', ['sid' => 'sb-pruned', 'type' => 'power']);

        $estimate = $this->standby->forDevice('sb-pruned', $now);

        self::assertNotNull($estimate);
        self::assertSame('rollup', $estimate['source']);
        self::assertNull($estimate['idleWatts']);
        self::assertNull($estimate['idleSource']);
    }

    public function testTheRawFallbackAlsoReportsDutyCycleAndIdleDraw(): void
    {
        // No rollup at all, so the raw tier answers — it must carry the same
        // fields rather than degrading to the floor alone.
        $now = new \DateTimeImmutable();
        $this->device('sb-rawduty');
        $this->power('sb-rawduty', $now, 7, static fn (int $i): float => $i % 2 === 0 ? 8.0 : 0.0);

        $estimate = $this->standby->forDevice('sb-rawduty', $now);

        self::assertNotNull($estimate);
        self::assertSame('raw', $estimate['source']);
        self::assertEqualsWithDelta(50.0, $estimate['dutyCyclePercent'], 1.0);
        self::assertEqualsWithDelta(8.0, $estimate['idleWatts'], 0.01);
    }

    public function testHouseholdIgnoresDevicesWithoutEnoughData(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-quiet');

        $household = $this->standby->household($now);

        self::assertSame(0, $household['devices']);
        self::assertSame(0.0, $household['watts']);
    }
}
