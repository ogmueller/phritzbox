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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BackupServiceTest extends KernelTestCase
{
    private BackupService $service;
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(BackupService::class);
        // Never inside the repo tree.
        $this->dir = sys_get_temp_dir().'/phritzbox-backup-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function gunzip(string $path): string
    {
        $plain = mb_substr($path, 0, -3);
        $in = gzopen($path, 'rb');
        $out = fopen($plain, 'w');
        if ($in === false || $out === false) {
            self::fail(\sprintf('could not open "%s" for decompression', $path));
        }

        while (!gzeof($in)) {
            fwrite($out, (string) gzread($in, 1024 * 1024));
        }
        gzclose($in);
        fclose($out);

        return $plain;
    }

    public function testWritesAVerifiableCompressedSnapshot(): void
    {
        // The whole point of the command: what lands on disk must be a real,
        // openable database, not just a file of the right size.
        $result = $this->service->run($this->dir);

        self::assertStringEndsWith('.sqlite.gz', $result['path']);
        self::assertFileExists($result['path']);
        self::assertGreaterThan(0, $result['bytes']);

        $plain = $this->gunzip($result['path']);
        $pdo = new \PDO('sqlite:'.$plain, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame('ok', $pdo->query('PRAGMA quick_check')->fetchColumn());
        self::assertNotFalse(
            $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='smart_device_data'")->fetchColumn(),
            'the snapshot must contain the real schema',
        );
        @unlink($plain);
    }

    public function testRunsWhileTheAppHoldsATransaction(): void
    {
        // dama/doctrine-test-bundle wraps every test in a transaction, and
        // SQLite rejects VACUUM INTO inside one. This passing is what proves the
        // service uses its own connection rather than the injected DBAL one.
        $result = $this->service->run($this->dir);

        self::assertFileExists($result['path']);
    }

    public function testUncompressedSnapshotOpensDirectly(): void
    {
        $result = $this->service->run($this->dir, null, false);

        self::assertStringEndsWith('.sqlite', $result['path']);
        $pdo = new \PDO('sqlite:'.$result['path'], null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame('ok', $pdo->query('PRAGMA quick_check')->fetchColumn());
    }

    public function testRotationKeepsTheNewestAndRemovesTheRest(): void
    {
        mkdir($this->dir, 0o775, true);
        // Nine older snapshots, named with the same sortable stamp the service uses.
        foreach (range(1, 9) as $i) {
            file_put_contents(\sprintf('%s/phritzbox-2026010%d-000000.sqlite.gz', $this->dir, $i), 'x');
        }

        $result = $this->service->run($this->dir, 3);

        $remaining = (array) glob($this->dir.'/phritzbox-*');
        self::assertCount(3, $remaining, 'exactly `keep` snapshots survive');
        self::assertContains($result['path'], $remaining, 'the snapshot just written is never pruned');
        self::assertCount(7, $result['pruned']);
    }

    public function testRotationOnlyTouchesItsOwnNaming(): void
    {
        mkdir($this->dir, 0o775, true);
        $bystander = $this->dir.'/important-not-ours.sqlite.gz';
        file_put_contents($bystander, 'x');
        foreach (range(1, 5) as $i) {
            file_put_contents(\sprintf('%s/phritzbox-2026010%d-000000.sqlite.gz', $this->dir, $i), 'x');
        }

        $this->service->run($this->dir, 1);

        self::assertFileExists($bystander, 'rotation must never delete files it did not write');
    }

    public function testKeepZeroDisablesRotationRatherThanDeletingEverything(): void
    {
        mkdir($this->dir, 0o775, true);
        file_put_contents($this->dir.'/phritzbox-20260101-000000.sqlite.gz', 'x');

        $result = $this->service->run($this->dir, 0);

        self::assertSame([], $result['pruned']);
        self::assertFileExists($result['path']);
    }

    public function testStampsLastBackupAt(): void
    {
        $appState = static::getContainer()->get(AppState::class);
        self::assertNull($appState->getInstant(AppState::LAST_BACKUP_AT));

        $this->service->run($this->dir);

        self::assertNotNull($appState->getInstant(AppState::LAST_BACKUP_AT));
    }

    public function testFailsClearlyWhenTheTargetIsNotWritable(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->run('/proc/definitely-not-writable/phritzbox');
    }

    public function testFormatBytesIsHumanReadable(): void
    {
        self::assertSame('512.0 B', BackupService::formatBytes(512));
        self::assertSame('1.0 KB', BackupService::formatBytes(1024));
        self::assertSame('5.6 GB', BackupService::formatBytes((int) (5.6 * 1024 ** 3)));
    }
}
