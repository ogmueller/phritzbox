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

namespace App\Tests\Command;

use App\Command\CronSmartRollup;
use App\Command\SmartRollupBackfill;
use App\Service\DataLifecycle\AppState;
use App\Service\DataLifecycle\RollupService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RollupCommandsTest extends KernelTestCase
{
    private RollupService $rollup;
    private AppState $appState;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rollup = static::getContainer()->get(RollupService::class);
        $this->appState = static::getContainer()->get(AppState::class);
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function device(string $ain): void
    {
        $this->conn->insert('smart_device', [
            'ain' => $ain, 'name' => $ain, 'manufacturer' => '', 'product_name' => '',
            'firmware_version' => '', 'function_bit_mask' => 0,
            'first_seen_at' => '2026-08-01 00:00:00', 'last_seen_at' => '2026-08-01 00:00:00',
        ]);
    }

    private function reading(string $sid, string $type, string $time, float $value): void
    {
        $this->conn->insert('smart_device_data', ['sid' => $sid, 'type' => $type, 'time' => $time, 'value' => $value]);
    }

    private function rollupTester(): CommandTester
    {
        return new CommandTester(new CronSmartRollup($this->rollup, $this->appState));
    }

    private function backfillTester(): CommandTester
    {
        return new CommandTester(new SmartRollupBackfill($this->rollup, $this->appState));
    }

    private function bucketCount(string $sid, int $grid): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data_rollup WHERE sid = ? AND grid = ?',
            [$sid, $grid],
        );
    }

    public function testBackfillAggregatesHistoryAndSetsTheWatermark(): void
    {
        $this->device('bf-1');
        $this->reading('bf-1', 'power', '2026-08-01 10:01:00', 10.0);
        $this->reading('bf-1', 'power', '2026-08-01 10:20:00', 20.0);

        $tester = $this->backfillTester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(2, $this->bucketCount('bf-1', RollupService::GRID_QUARTER));
        self::assertSame(1, $this->bucketCount('bf-1', RollupService::GRID_DAY));
        self::assertNotNull(
            $this->rollup->watermark(RollupService::GRID_QUARTER),
            'a completed backfill must hand over to the incremental job',
        );
    }

    public function testBackfillResumesFromItsCursor(): void
    {
        // A budget of zero stops after the first pair; the run after it must
        // continue rather than start over.
        $this->device('bf-2');
        $this->reading('bf-2', 'power', '2026-08-01 10:01:00', 10.0);
        $this->reading('bf-2', 'temperature', '2026-08-01 10:01:00', 21.0);

        $first = $this->backfillTester();
        $first->execute(['--max-seconds' => '0']);
        self::assertStringContainsString('Re-run to continue', $first->getDisplay());
        $cursor = $this->appState->get(AppState::ROLLUP_BACKFILL_CURSOR);
        self::assertNotNull($cursor);
        self::assertGreaterThan(0, (int) $cursor);

        $second = $this->backfillTester();
        $second->execute([]);

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        // Both pairs are aggregated: the one the first run reached, and the one
        // it stopped before.
        self::assertSame(['power', 'temperature'], $this->conn->fetchFirstColumn(
            'SELECT DISTINCT type FROM smart_device_data_rollup WHERE sid = ? AND grid = ? ORDER BY type',
            ['bf-2', RollupService::GRID_QUARTER],
        ));
    }

    public function testBackfillDryRunWritesNothing(): void
    {
        $this->device('bf-3');
        $this->reading('bf-3', 'power', '2026-08-01 10:01:00', 10.0);

        $tester = $this->backfillTester();
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, $this->bucketCount('bf-3', RollupService::GRID_QUARTER));
    }

    public function testBackfillRefusesWhileAnotherRunHoldsTheLease(): void
    {
        $this->device('bf-4');
        $this->reading('bf-4', 'power', '2026-08-01 10:01:00', 10.0);
        $this->appState->tryLock(AppState::ROLLUP_LOCK, new \DateTimeImmutable('+1 hour'));

        $tester = $this->backfillTester();
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('already running', $tester->getDisplay());
    }

    public function testAnExpiredLeaseDoesNotBlockForever(): void
    {
        // A process killed mid-run must not wedge the job permanently.
        $this->device('bf-5');
        $this->reading('bf-5', 'power', '2026-08-01 10:01:00', 10.0);
        $this->appState->tryLock(AppState::ROLLUP_LOCK, new \DateTimeImmutable('-1 hour'));

        $tester = $this->backfillTester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testIncrementalRollupSkipsTheBucketStillInProgress(): void
    {
        // The current quarter-hour is incomplete; writing it would show up as a
        // dip until it filled in.
        $this->device('inc-1');
        $now = new \DateTimeImmutable();
        $this->reading('inc-1', 'power', $now->format('Y-m-d H:i:s'), 42.0);
        $this->reading('inc-1', 'power', $now->modify('-2 hours')->format('Y-m-d H:i:s'), 10.0);

        $tester = $this->rollupTester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $currentBucket = CronSmartRollup::floorTo($now, RollupService::GRID_QUARTER)->format('Y-m-d H:i:s');
        self::assertFalse(
            $this->conn->fetchAssociative(
                'SELECT 1 FROM smart_device_data_rollup WHERE sid = ? AND grid = ? AND bucket = ?',
                ['inc-1', RollupService::GRID_QUARTER, $currentBucket],
            ),
            'the in-progress bucket must not be written',
        );
        self::assertSame(1, $this->bucketCount('inc-1', RollupService::GRID_QUARTER));
    }

    public function testIncrementalRollupAdvancesTheWatermark(): void
    {
        $this->device('inc-2');
        $this->reading('inc-2', 'power', (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), 10.0);

        $this->rollupTester()->execute([]);

        self::assertNotNull($this->rollup->watermark(RollupService::GRID_QUARTER));
    }

    public function testIncrementalRollupYieldsWhenAnotherRunHoldsTheLease(): void
    {
        $this->appState->tryLock(AppState::ROLLUP_LOCK, new \DateTimeImmutable('+1 hour'));

        $tester = $this->rollupTester();
        $tester->execute([]);

        // Success, not failure: an overlapping cron tick is normal, not an error.
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Another rollup is running', $tester->getDisplay());
    }

    public function testIncrementalRollupReleasesTheLease(): void
    {
        $this->device('inc-3');
        $this->reading('inc-3', 'power', (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), 10.0);

        $this->rollupTester()->execute([]);

        self::assertNull($this->appState->get(AppState::ROLLUP_LOCK), 'the lease must not outlive the run');
    }

    public function testIncrementalRollupIsSafeToRepeat(): void
    {
        $this->device('inc-4');
        $this->reading('inc-4', 'power', (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), 10.0);

        $this->rollupTester()->execute([]);
        $first = $this->conn->fetchAllAssociative('SELECT * FROM smart_device_data_rollup WHERE sid = ? ORDER BY grid, bucket', ['inc-4']);

        $this->rollupTester()->execute([]);
        $second = $this->conn->fetchAllAssociative('SELECT * FROM smart_device_data_rollup WHERE sid = ? ORDER BY grid, bucket', ['inc-4']);

        self::assertSame($first, $second);
    }
}
