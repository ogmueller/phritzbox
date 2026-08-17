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

    public function testHouseholdIgnoresDevicesWithoutEnoughData(): void
    {
        $now = new \DateTimeImmutable();
        $this->device('sb-quiet');

        $household = $this->standby->household($now);

        self::assertSame(0, $household['devices']);
        self::assertSame(0.0, $household['watts']);
    }
}
