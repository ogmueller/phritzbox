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

use App\Command\CronDataBackup;
use App\Service\DataLifecycle\BackupService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CronDataBackupTest extends KernelTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->dir = sys_get_temp_dir().'/phritzbox-backup-cmd-'.bin2hex(random_bytes(6));
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

    private function tester(): CommandTester
    {
        return new CommandTester(new CronDataBackup(static::getContainer()->get(BackupService::class)));
    }

    public function testWritesASnapshotAndReportsIt(): void
    {
        $tester = $this->tester();

        $tester->execute(['--dir' => $this->dir]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Backed up', $tester->getDisplay());
        self::assertCount(1, (array) glob($this->dir.'/phritzbox-*.sqlite.gz'));
    }

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->tester();

        $tester->execute(['--dir' => $this->dir, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would back up', $tester->getDisplay());
        self::assertFalse(is_dir($this->dir), 'a dry run must not even create the target directory');
    }

    public function testReportsRotation(): void
    {
        mkdir($this->dir, 0o775, true);
        foreach (range(1, 5) as $i) {
            file_put_contents(\sprintf('%s/phritzbox-2026010%d-000000.sqlite.gz', $this->dir, $i), 'x');
        }

        $tester = $this->tester();
        $tester->execute(['--dir' => $this->dir, '--keep' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('older snapshot', $tester->getDisplay());
        self::assertCount(2, (array) glob($this->dir.'/phritzbox-*'));
    }

    public function testFailsWithAClearMessageRatherThanAStackTrace(): void
    {
        $tester = $this->tester();

        $tester->execute(['--dir' => '/proc/definitely-not-writable/phritzbox']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('[ERROR]', $tester->getDisplay());
    }
}
