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

use App\Service\DataLifecycle\AppState;
use App\Service\DataLifecycle\BackupService;
use App\Service\DataLifecycle\RestoreService;
use App\Service\DataLifecycle\SnapshotInspector;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Restore overwrites the database the connection points at, and in the test
 * environment that is data/database_test.sqlite — a file tracked in git. Every
 * test here therefore drives the service through its own throwaway SQLite file,
 * never the container's connection.
 */
class RestoreServiceTest extends KernelTestCase
{
    private string $dir;
    private string $live;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/phritzbox-restore-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->live = $this->dir.'/live.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Build a Phritzbox-shaped database holding `$marker` as its only reading. */
    private function makeDb(string $path, float $marker): void
    {
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE smart_device_data (data_id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT, type TEXT, time DATETIME, value REAL)');
        $pdo->exec('CREATE TABLE smart_device (ain TEXT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE doctrine_migration_versions (version TEXT PRIMARY KEY)');
        $pdo->exec(\sprintf(
            "INSERT INTO smart_device_data (sid, type, time, value) VALUES ('a', 'temperature', '2026-08-16 10:00:00', %f)",
            $marker,
        ));
    }

    private function markerOf(string $path): float
    {
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return (float) $pdo->query('SELECT value FROM smart_device_data LIMIT 1')->fetchColumn();
    }

    private function service(): RestoreService
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->live]);
        $backups = new BackupService(
            $connection,
            static::getContainer()->get(AppState::class),
            $this->dir,
            3,
        );

        return new RestoreService($backups, static::getContainer()->get(SnapshotInspector::class));
    }

    public function testReplacesTheLiveDatabase(): void
    {
        $this->makeDb($this->live, 1.0);
        $snapshot = $this->dir.'/snap.sqlite';
        $this->makeDb($snapshot, 2.0);

        $result = $this->service()->restore($snapshot);

        self::assertSame($this->live, $result['restored']);
        self::assertSame(2.0, $this->markerOf($this->live), 'the live database now holds the snapshot data');
    }

    public function testKeepsThePreviousDatabaseAsAnUndoPath(): void
    {
        $this->makeDb($this->live, 1.0);
        $snapshot = $this->dir.'/snap.sqlite';
        $this->makeDb($snapshot, 2.0);

        $result = $this->service()->restore($snapshot);

        self::assertFileExists($result['safetyCopy']);
        self::assertSame(1.0, $this->markerOf($result['safetyCopy']), 'the safety copy holds what was overwritten');
    }

    public function testDoesNotConsumeAnUncompressedSnapshot(): void
    {
        // Restoring must not destroy the backup being restored from.
        $this->makeDb($this->live, 1.0);
        $snapshot = $this->dir.'/snap.sqlite';
        $this->makeDb($snapshot, 2.0);

        $this->service()->restore($snapshot);

        self::assertFileExists($snapshot);
        self::assertSame(2.0, $this->markerOf($snapshot));
    }

    public function testRestoresFromACompressedSnapshot(): void
    {
        $this->makeDb($this->live, 1.0);
        $plain = $this->dir.'/snap.sqlite';
        $this->makeDb($plain, 3.0);
        $gz = $plain.'.gz';
        $out = gzopen($gz, 'wb');
        if ($out === false) {
            self::fail(\sprintf('could not open "%s" for writing', $gz));
        }

        gzwrite($out, (string) file_get_contents($plain));
        gzclose($out);
        @unlink($plain);

        $this->service()->restore($gz);

        self::assertSame(3.0, $this->markerOf($this->live));
    }

    public function testRefusesACorruptSnapshotAndLeavesTheLiveDatabaseAlone(): void
    {
        $this->makeDb($this->live, 1.0);
        $broken = $this->dir.'/broken.sqlite';
        file_put_contents($broken, str_repeat('definitely not sqlite', 100));

        try {
            $this->service()->restore($broken);
            self::fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Refusing to restore', $e->getMessage());
        }

        self::assertSame(1.0, $this->markerOf($this->live), 'the live database must be untouched');
        self::assertSame([], glob($this->dir.'/pre-restore-*'), 'no safety copy for a refused restore');
    }

    public function testRefusesAValidSqliteFileThatIsNotPhritzbox(): void
    {
        $this->makeDb($this->live, 1.0);
        $other = $this->dir.'/other.sqlite';
        $pdo = new \PDO('sqlite:'.$other, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
        unset($pdo);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->restore($other);
        } finally {
            self::assertSame(1.0, $this->markerOf($this->live));
        }
    }

    public function testRemovesStaleWalAndShmSidecars(): void
    {
        // A restored database paired with the *previous* database's write-ahead
        // log is how a restore turns into corruption.
        $this->makeDb($this->live, 1.0);
        file_put_contents($this->live.'-wal', 'stale');
        file_put_contents($this->live.'-shm', 'stale');
        $snapshot = $this->dir.'/snap.sqlite';
        $this->makeDb($snapshot, 2.0);

        $this->service()->restore($snapshot);

        self::assertFileDoesNotExist($this->live.'-wal');
        self::assertFileDoesNotExist($this->live.'-shm');
        self::assertSame(2.0, $this->markerOf($this->live));
    }

    public function testMissingSnapshotThrowsBeforeAnythingIsTouched(): void
    {
        $this->makeDb($this->live, 1.0);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->restore($this->dir.'/does-not-exist.sqlite.gz');
        } finally {
            self::assertSame(1.0, $this->markerOf($this->live));
        }
    }
}
