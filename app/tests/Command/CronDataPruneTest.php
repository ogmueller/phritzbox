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

use App\Command\CronDataPrune;
use App\Service\DataLifecycle\AppState;
use App\Service\DataLifecycle\PruneService;
use App\Service\DataLifecycle\RetentionConfig;
use App\Service\DataLifecycle\RollupService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CronDataPruneTest extends KernelTestCase
{
    private Connection $conn;
    private AppState $appState;
    private RollupService $rollup;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->appState = static::getContainer()->get(AppState::class);
        $this->rollup = static::getContainer()->get(RollupService::class);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new CronDataPrune(
            static::getContainer()->get(PruneService::class),
            $this->rollup,
            $this->appState,
            static::getContainer()->get(RetentionConfig::class),
        ));
    }

    private function device(string $ain): void
    {
        $this->conn->insert('smart_device', [
            'ain' => $ain, 'name' => $ain, 'manufacturer' => '', 'product_name' => '',
            'firmware_version' => '', 'function_bit_mask' => 0,
            'first_seen_at' => '2020-01-01 00:00:00', 'last_seen_at' => '2020-01-01 00:00:00',
        ]);
    }

    public function testDeletesNothingWithTheShippedConfiguration(): void
    {
        // The container's RetentionConfig keeps everything, which is the default
        // an operator gets on an image update. This is the single most important
        // property of the whole phase.
        $this->device('prune-default');
        $this->conn->insert('smart_device_data', [
            'sid' => 'prune-default', 'type' => 'power', 'time' => '2019-01-01 10:00:00', 'value' => 1.0,
        ]);
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable());

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM smart_device_data WHERE sid = 'prune-default'",
        ));
    }

    public function testSaysWhenRetentionIsOffRatherThanLookingLikeSuccess(): void
    {
        $this->device('prune-quiet');
        $this->conn->insert('smart_device_data', [
            'sid' => 'prune-quiet', 'type' => 'power', 'time' => '2019-01-01 10:00:00', 'value' => 1.0,
        ]);

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Raw retention is off', $tester->getDisplay());
    }

    public function testDryRunChangesNothingAndSaysSo(): void
    {
        $this->device('prune-dry');
        $this->conn->insert('smart_device_data', [
            'sid' => 'prune-dry', 'type' => 'power', 'time' => '2019-01-01 10:00:00', 'value' => 1.0,
        ]);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would delete', $tester->getDisplay());
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
    }

    public function testYieldsWhileARollupHoldsTheLease(): void
    {
        // Sharing the rollup lease is deliberate: aggregating and deleting must
        // not run against each other.
        $this->appState->tryLock(AppState::ROLLUP_LOCK, new \DateTimeImmutable('+1 hour'));

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already running', $tester->getDisplay());
    }

    public function testADryRunDoesNotNeedTheLease(): void
    {
        // Reporting is read-only, so it should work even mid-rollup.
        $this->appState->tryLock(AppState::ROLLUP_LOCK, new \DateTimeImmutable('+1 hour'));

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('Would delete', $tester->getDisplay());
    }

    public function testReleasesTheLease(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        self::assertNull($this->appState->get(AppState::ROLLUP_LOCK));
    }

    public function testReportsTheFileSizeAndFreePages(): void
    {
        // Without this, deleting millions of rows and seeing an unchanged file
        // size reads as a broken command.
        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString('Database file', $tester->getDisplay());
    }
}
