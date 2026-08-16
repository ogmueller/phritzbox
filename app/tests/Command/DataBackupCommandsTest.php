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

use App\Command\DataBackupList;
use App\Command\DataBackupVerify;
use App\Service\DataLifecycle\BackupService;
use App\Service\DataLifecycle\SnapshotInspector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DataBackupCommandsTest extends KernelTestCase
{
    private BackupService $backups;
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->backups = static::getContainer()->get(BackupService::class);
        $this->dir = sys_get_temp_dir().'/phritzbox-cmds-'.bin2hex(random_bytes(6));
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

    private function listTester(): CommandTester
    {
        return new CommandTester(new DataBackupList($this->backups));
    }

    private function verifyTester(): CommandTester
    {
        return new CommandTester(new DataBackupVerify(
            $this->backups,
            static::getContainer()->get(SnapshotInspector::class),
        ));
    }

    public function testListReportsAnEmptyDirectoryWithoutFailing(): void
    {
        $tester = $this->listTester();

        $tester->execute(['--dir' => $this->dir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No snapshots', $tester->getDisplay());
    }

    public function testListShowsSnapshots(): void
    {
        $this->backups->run($this->dir);

        $tester = $this->listTester();
        $tester->execute(['--dir' => $this->dir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('phritzbox-', $tester->getDisplay());
        self::assertStringContainsString('1 snapshot(s)', $tester->getDisplay());
    }

    public function testVerifyPassesOnAFreshSnapshot(): void
    {
        $this->backups->run($this->dir);

        $tester = $this->verifyTester();
        $tester->execute(['--dir' => $this->dir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Restorable', $tester->getDisplay());
    }

    public function testVerifyFailsOnACorruptSnapshot(): void
    {
        // Non-zero exit is what lets this be wired into monitoring.
        mkdir($this->dir, 0o775, true);
        $broken = $this->dir.'/phritzbox-20260101-000000.sqlite';
        file_put_contents($broken, str_repeat('not sqlite', 200));

        $tester = $this->verifyTester();
        $tester->execute(['--dir' => $this->dir]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testVerifyFailsWhenThereIsNothingToVerify(): void
    {
        // "No backups at all" must not read as a pass.
        $tester = $this->verifyTester();

        $tester->execute(['--dir' => $this->dir]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testVerifyAllChecksEverySnapshotAndFailsIfAnyIsBad(): void
    {
        $this->backups->run($this->dir);
        file_put_contents($this->dir.'/phritzbox-20260101-000000.sqlite', str_repeat('bad', 200));

        $tester = $this->verifyTester();
        $tester->execute(['--dir' => $this->dir, '--all' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 of 2 snapshot(s) failed', $tester->getDisplay());
    }
}
