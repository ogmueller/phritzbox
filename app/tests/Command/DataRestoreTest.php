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

use App\Command\DataRestore;
use App\Service\DataLifecycle\AppState;
use App\Service\DataLifecycle\BackupService;
use App\Service\DataLifecycle\RestoreService;
use App\Service\DataLifecycle\SnapshotInspector;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the command against a throwaway database, never the container's
 * connection — in the test environment that points at data/database_test.sqlite,
 * which is tracked in git.
 */
class DataRestoreTest extends KernelTestCase
{
    private string $dir;
    private string $live;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/phritzbox-restore-cmd-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
        $this->live = $this->dir.'/live.sqlite';
        $this->makeDb($this->live, 1.0);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function makeDb(string $path, float $marker): void
    {
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE smart_device_data (data_id INTEGER PRIMARY KEY AUTOINCREMENT, sid TEXT, type TEXT, time DATETIME, value REAL)');
        $pdo->exec('CREATE TABLE smart_device (ain TEXT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE doctrine_migration_versions (version TEXT PRIMARY KEY)');
        $pdo->exec(\sprintf("INSERT INTO smart_device_data (sid, type, time, value) VALUES ('a', 'temperature', '2026-08-16 10:00:00', %f)", $marker));
    }

    private function markerOf(string $path): float
    {
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        return (float) $pdo->query('SELECT value FROM smart_device_data LIMIT 1')->fetchColumn();
    }

    private function tester(): CommandTester
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->live]);
        $backups = new BackupService($connection, static::getContainer()->get(AppState::class), $this->dir, 3);
        $inspector = static::getContainer()->get(SnapshotInspector::class);

        return new CommandTester(new DataRestore(new RestoreService($backups, $inspector), $backups, $inspector));
    }

    private function snapshot(float $marker = 2.0): string
    {
        $path = $this->dir.'/phritzbox-20260101-000000.sqlite';
        $this->makeDb($path, $marker);

        return $path;
    }

    public function testDryRunChangesNothing(): void
    {
        $this->snapshot();
        $tester = $this->tester();

        $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertSame(1.0, $this->markerOf($this->live));
    }

    public function testAnsweringNoToTheAppStoppedQuestionAbortsAndExplainsHow(): void
    {
        // The whole point of asking separately: a user who has not stopped the
        // app gets instructions, not a broken restore.
        $this->snapshot();
        $tester = $this->tester();
        $tester->setInputs(['no']);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing was changed', $tester->getDisplay());
        self::assertStringContainsString('Stop it first', $tester->getDisplay());
        self::assertSame(1.0, $this->markerOf($this->live), 'the live database must be untouched');
    }

    public function testDecliningTheSecondConfirmationAborts(): void
    {
        $this->snapshot();
        $tester = $this->tester();
        $tester->setInputs(['yes', 'no']);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Aborted', $tester->getDisplay());
        self::assertSame(1.0, $this->markerOf($this->live));
    }

    public function testConfirmingBothQuestionsRestores(): void
    {
        $this->snapshot();
        $tester = $this->tester();
        $tester->setInputs(['yes', 'yes']);

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(2.0, $this->markerOf($this->live));
        self::assertStringContainsString('Previous database kept at', $tester->getDisplay());
        // The undo path is spelled out, not left as an exercise.
        self::assertStringContainsString('data:restore', $tester->getDisplay());
    }

    public function testWarningStatesWhatIsAboutToBeLost(): void
    {
        $this->snapshot();
        $tester = $this->tester();
        $tester->setInputs(['no']);

        $tester->execute([]);

        self::assertStringContainsString('readings currently in', $tester->getDisplay());
    }

    public function testNonInteractiveWithoutForceRefusesRatherThanGuessing(): void
    {
        $this->snapshot();
        $tester = $this->tester();

        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Refusing to restore without confirmation', $tester->getDisplay());
        self::assertSame(1.0, $this->markerOf($this->live));
    }

    public function testForceSkipsThePromptsForAutomation(): void
    {
        $this->snapshot();
        $tester = $this->tester();

        $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(2.0, $this->markerOf($this->live));
    }

    public function testBusyDatabaseRefusesEvenWithForce(): void
    {
        // An exclusive lock held elsewhere is a true positive: something is
        // using the database, and --force must not be able to talk over it.
        $this->snapshot();
        $blocker = new \PDO('sqlite:'.$this->live, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $blocker->exec('BEGIN EXCLUSIVE');

        $tester = $this->tester();
        $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('in use by another process', $tester->getDisplay());

        $blocker->exec('ROLLBACK');
        self::assertSame(1.0, $this->markerOf($this->live));
    }

    public function testCorruptSnapshotIsRefusedBeforeAnyPrompt(): void
    {
        $broken = $this->dir.'/phritzbox-20260101-000000.sqlite';
        file_put_contents($broken, str_repeat('not sqlite', 200));

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Refusing to restore', $tester->getDisplay());
        self::assertSame(1.0, $this->markerOf($this->live));
    }
}
