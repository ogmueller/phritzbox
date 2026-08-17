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

use App\Repository\AlertEventRepository;
use App\Repository\RefreshTokenRepository;
use App\Service\DataLifecycle\PruneService;
use App\Service\DataLifecycle\RetentionConfig;
use App\Service\DataLifecycle\RollupService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PruneServiceTest extends KernelTestCase
{
    private Connection $conn;
    private RollupService $rollup;
    private string $logsDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->rollup = static::getContainer()->get(RollupService::class);
        $this->logsDir = sys_get_temp_dir().'/phritzbox-logs-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logsDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logsDir);
        parent::tearDown();
    }

    /**
     * Built by hand so each test states its own retention policy — the container
     * service is deliberately configured to keep everything.
     */
    private function service(
        int $rawDays = 0,
        string $rawOverrides = '',
        int $quarterDays = 0,
        int $dailyDays = 0,
        int $alertEventDays = 0,
        int $logDays = 14,
    ): PruneService {
        return new PruneService(
            $this->conn,
            $this->rollup,
            new RetentionConfig($rawDays, $quarterDays, $dailyDays, $rawOverrides),
            static::getContainer()->get(AlertEventRepository::class),
            static::getContainer()->get(RefreshTokenRepository::class),
            $alertEventDays,
            $logDays,
            $this->logsDir,
        );
    }

    private function reading(string $sid, string $type, string $time, float $value = 1.0): void
    {
        $this->conn->insert('smart_device_data', ['sid' => $sid, 'type' => $type, 'time' => $time, 'value' => $value]);
    }

    private function rawCount(string $sid, string $type): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data WHERE sid = ? AND type = ?',
            [$sid, $type],
        );
    }

    public function testNothingIsDeletedWhenRetentionIsOff(): void
    {
        // The default. An image update must not start removing history.
        $this->reading('pr-off', 'power', '2020-01-01 10:00:00');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, new \DateTimeImmutable());

        $deleted = $this->service(rawDays: 0)->pruneRawPair('pr-off', 'power', new \DateTimeImmutable(), 1000);

        self::assertSame(0, $deleted);
        self::assertSame(1, $this->rawCount('pr-off', 'power'));
    }

    public function testNothingIsDeletedWhenTheRollupHasNeverRun(): void
    {
        // Without a watermark there are no buckets covering the old readings, so
        // deleting them would destroy history rather than compact it.
        $this->reading('pr-norollup', 'power', '2020-01-01 10:00:00');

        $service = $this->service(rawDays: 30);
        self::assertNull($service->rawCutoff('power', new \DateTimeImmutable()));
        self::assertSame(0, $service->pruneRawPair('pr-norollup', 'power', new \DateTimeImmutable(), 1000));
        self::assertSame(1, $this->rawCount('pr-norollup', 'power'));
    }

    public function testTheCutoffNeverRunsAheadOfTheRollup(): void
    {
        // Retention would allow deleting up to 30 days ago, but the rollup has
        // only reached 90 days ago — so that is as far as deletion may go.
        $now = new \DateTimeImmutable('2026-06-30 12:00:00');
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now->modify('-90 days'));

        $cutoff = $this->service(rawDays: 30)->rawCutoff('power', $now);

        self::assertNotNull($cutoff);
        self::assertSame($now->modify('-90 days')->getTimestamp(), $cutoff->getTimestamp());
    }

    public function testDeletesOnlyBeyondTheCutoff(): void
    {
        $now = new \DateTimeImmutable();
        $this->reading('pr-cut', 'power', $now->modify('-100 days')->format('Y-m-d H:i:s'));
        $this->reading('pr-cut', 'power', $now->modify('-40 days')->format('Y-m-d H:i:s'));
        $this->reading('pr-cut', 'power', $now->modify('-1 day')->format('Y-m-d H:i:s'));
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $deleted = $this->service(rawDays: 30)->pruneRawPair('pr-cut', 'power', $now, 1000);

        self::assertSame(2, $deleted);
        self::assertSame(1, $this->rawCount('pr-cut', 'power'), 'the reading inside the window survives');
    }

    public function testPerMetricOverrideBeatsTheGlobalWindow(): void
    {
        // Voltage is half the database and barely varies, so it can be pruned
        // far more aggressively than temperature.
        $now = new \DateTimeImmutable();
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);
        $service = $this->service(rawDays: 90, rawOverrides: 'voltage=7');

        $voltageCutoff = $service->rawCutoff('voltage', $now);
        $tempCutoff = $service->rawCutoff('temperature', $now);

        self::assertNotNull($voltageCutoff);
        self::assertNotNull($tempCutoff);
        // A later cutoff means less raw history is kept.
        self::assertGreaterThan($tempCutoff, $voltageCutoff, 'voltage keeps a shorter raw window');
    }

    public function testAnOverrideAppliesOnlyToItsOwnMetric(): void
    {
        $config = new RetentionConfig(90, 0, 0, 'voltage=7');

        self::assertSame(7, $config->rawDaysFor('voltage'));
        self::assertSame(90, $config->rawDaysFor('power'));
        self::assertSame(90, $config->rawDaysFor('temperature'));
    }

    public function testMalformedOverrideIsIgnoredRatherThanDeletingEverything(): void
    {
        $config = new RetentionConfig(90, 0, 0, 'voltage=,=7,nonsense,power=-5');

        self::assertSame(90, $config->rawDaysFor('voltage'));
        self::assertSame(90, $config->rawDaysFor('power'));
    }

    public function testEveryDocumentedMetricNameIsAcceptedAsAnOverride(): void
    {
        // The names listed in INSTALL.md must actually work.
        $names = ['temperature', 'power', 'voltage', 'energy', 'battery', 'presence'];
        $config = new RetentionConfig(90, 0, 0, implode(',', array_map(
            static fn (string $n): string => $n.'=5',
            $names,
        )));

        self::assertSame([], $config->unknownOverrides());
        foreach ($names as $name) {
            self::assertSame(5, $config->rawDaysFor($name), $name);
        }
    }

    public function testATypoedMetricNameIsReportedRatherThanSilentlyIgnored(): void
    {
        // "voltag=14" would otherwise look configured and do nothing at all.
        $config = new RetentionConfig(90, 0, 0, 'voltag=14,power=30');

        self::assertSame(['voltag'], $config->unknownOverrides());
        self::assertSame(30, $config->rawDaysFor('power'), 'the valid pair still applies');
        self::assertSame(90, $config->rawDaysFor('voltage'), 'the typo leaves voltage on the global window');
    }

    public function testMaxDeleteCapsARun(): void
    {
        // Bounded runs let a first cleanup drain over several nights instead of
        // holding a multi-hour write lock.
        $now = new \DateTimeImmutable();
        foreach (range(1, 5) as $i) {
            $this->reading('pr-cap', 'power', $now->modify(\sprintf('-%d days', 40 + $i))->format('Y-m-d H:i:s'));
        }
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $deleted = $this->service(rawDays: 30)->pruneRawPair('pr-cap', 'power', $now, 2);

        self::assertLessThanOrEqual(3, $deleted, 'stops at roughly the cap, one day-window at a time');
        self::assertGreaterThan(0, $this->rawCount('pr-cap', 'power'), 'the rest is left for the next run');
    }

    public function testDryRunReportsWithoutDeleting(): void
    {
        $now = new \DateTimeImmutable();
        $this->reading('pr-dry', 'power', $now->modify('-100 days')->format('Y-m-d H:i:s'));
        $this->rollup->setWatermark(RollupService::GRID_QUARTER, $now);

        $would = $this->service(rawDays: 30)->pruneRawPair('pr-dry', 'power', $now, 1000, dryRun: true);

        self::assertSame(1, $would);
        self::assertSame(1, $this->rawCount('pr-dry', 'power'));
    }

    public function testPrunesRollupBucketsByGrid(): void
    {
        $now = new \DateTimeImmutable();
        $this->reading('pr-roll', 'power', $now->modify('-800 days')->format('Y-m-d H:i:s'));
        $this->rollup->rollUpPair('pr-roll', 'power');

        $before = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data_rollup WHERE sid = ? AND grid = ?',
            ['pr-roll', RollupService::GRID_QUARTER],
        );
        self::assertGreaterThan(0, $before);

        $deleted = $this->service(quarterDays: 730)->pruneRollup(RollupService::GRID_QUARTER, $now);

        self::assertGreaterThan(0, $deleted);
        // The daily tier keeps everything by default, so long-range charts survive.
        self::assertGreaterThan(0, (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM smart_device_data_rollup WHERE sid = ? AND grid = ?',
            ['pr-roll', RollupService::GRID_DAY],
        ));
    }

    public function testExpiredRefreshTokensArePurged(): void
    {
        // The repository method existed from the start and was never called.
        $this->conn->insert('refresh_token', [
            'token' => 'expired-'.bin2hex(random_bytes(4)),
            'username' => 'someone',
            'expires_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
            'created_at' => (new \DateTimeImmutable('-31 days'))->format('Y-m-d H:i:s'),
        ]);

        $deleted = $this->service()->pruneRefreshTokens(new \DateTimeImmutable());

        self::assertGreaterThan(0, $deleted);
    }

    public function testLogSweepSkipsTodaysFile(): void
    {
        mkdir($this->logsDir, 0o775, true);
        $now = new \DateTimeImmutable();
        $today = $this->logsDir.'/prod-'.$now->format('Y-m-d').'.log';
        $old = $this->logsDir.'/prod-2020-01-01.log';
        file_put_contents($today, 'x');
        file_put_contents($old, 'x');
        touch($old, $now->modify('-90 days')->getTimestamp());

        $removed = $this->service(logDays: 14)->pruneLogs($now);

        self::assertSame([$old], $removed, 'the file being written to must survive');
        self::assertFileExists($today);
    }

    public function testSpaceReportExplainsFreePages(): void
    {
        $report = $this->service()->spaceReport();

        self::assertGreaterThan(0, $report['bytes']);
        self::assertGreaterThan(0, $report['pageSize']);
        self::assertGreaterThanOrEqual(0, $report['freeBytes']);
    }
}
