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

use App\Service\DataLifecycle\BackupService;
use App\Service\DataLifecycle\SnapshotInspector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SnapshotInspectorTest extends KernelTestCase
{
    private SnapshotInspector $inspector;
    private BackupService $backups;
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->inspector = static::getContainer()->get(SnapshotInspector::class);
        $this->backups = static::getContainer()->get(BackupService::class);
        $this->dir = sys_get_temp_dir().'/phritzbox-inspect-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testInspectsARealSnapshot(): void
    {
        $snapshot = $this->backups->run($this->dir)['path'];

        $materialized = $this->inspector->materialize($snapshot, $this->dir);
        self::assertTrue($materialized['temporary'], 'a .gz must be decompressed to a scratch file');

        $report = $this->inspector->inspect($materialized['path']);

        self::assertTrue($report['ok'], $report['error'] ?? '');
        self::assertNull($report['error']);
        // The test database is migrated, so it must report a migration version.
        self::assertNotNull($report['migration']);
        @unlink($materialized['path']);
    }

    public function testUncompressedSnapshotIsNotCopied(): void
    {
        $snapshot = $this->backups->run($this->dir, null, false)['path'];

        $materialized = $this->inspector->materialize($snapshot, $this->dir);

        self::assertFalse($materialized['temporary'], 'a plain .sqlite is inspected in place');
        self::assertSame($snapshot, $materialized['path']);
    }

    public function testCorruptFileIsReportedNotThrown(): void
    {
        // "This backup is unusable" is the answer the caller wanted, not an
        // exception to handle.
        $broken = $this->dir.'/broken.sqlite';
        file_put_contents($broken, str_repeat('not a database', 100));

        $report = $this->inspector->inspect($broken);

        self::assertFalse($report['ok']);
        self::assertNotNull($report['error']);
    }

    public function testValidSqliteThatIsNotPhritzboxIsRejected(): void
    {
        // Structurally fine, but restoring it would wipe the app just as surely
        // as restoring a corrupt file.
        $other = $this->dir.'/other.sqlite';
        $pdo = new \PDO('sqlite:'.$other);
        $pdo->exec('CREATE TABLE unrelated (id INTEGER PRIMARY KEY)');
        unset($pdo);

        $report = $this->inspector->inspect($other);

        self::assertFalse($report['ok']);
        self::assertStringContainsString('not a Phritzbox database', (string) $report['error']);
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->inspector->materialize($this->dir.'/nope.sqlite.gz', $this->dir);
    }

    public function testReportsRowsSpanAndTypes(): void
    {
        $path = $this->dir.'/synthetic.sqlite';
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE smart_device_data (data_id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT, type TEXT, time DATETIME, value REAL)');
        $pdo->exec('CREATE TABLE smart_device (ain TEXT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE doctrine_migration_versions (version TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO smart_device_data (sid, type, time, value) VALUES
            ('a', 'temperature', '2026-08-01 10:00:00', 21.0),
            ('a', 'temperature', '2026-08-16 10:00:00', 22.0),
            ('a', 'presence', '2026-08-16 10:00:00', 1.0)");
        $pdo->exec("INSERT INTO smart_device (ain) VALUES ('a')");
        $pdo->exec("INSERT INTO doctrine_migration_versions (version) VALUES ('DoctrineMigrations\\Version20260628120000')");
        unset($pdo);

        $report = $this->inspector->inspect($path);

        self::assertTrue($report['ok'], $report['error'] ?? '');
        self::assertSame(3, $report['rows']);
        self::assertSame(1, $report['devices']);
        self::assertSame('2026-08-01 10:00:00', $report['oldest']);
        self::assertSame('2026-08-16 10:00:00', $report['newest']);
        self::assertSame(['presence' => 1, 'temperature' => 2], $report['types']);
        self::assertStringContainsString('Version20260628120000', (string) $report['migration']);
    }

    public function testSnapshotCapturesCommittedDataOnly(): void
    {
        // VACUUM INTO runs on its own connection, so it sees the committed
        // database — not whatever an open transaction elsewhere has staged. That
        // is the correct guarantee for a backup (never a half-finished write),
        // and it is why this suite builds synthetic fixtures above rather than
        // inserting through the test transaction.
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');
        $before = $this->inspector->inspect($this->backups->run($this->dir, null, false)['path'])['rows'];

        $conn->insert('smart_device_data', [
            'sid' => 'uncommitted', 'type' => 'temperature', 'time' => '2026-08-16 10:00:00', 'value' => 21.0,
        ]);

        $after = $this->inspector->inspect($this->backups->run($this->dir, null, false)['path'])['rows'];

        self::assertSame($before, $after, 'uncommitted rows must not appear in a snapshot');
    }
}
