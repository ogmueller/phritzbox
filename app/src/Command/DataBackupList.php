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

namespace App\Command;

use App\Service\DataLifecycle\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'data:backup:list', description: 'List available database snapshots')]
final class DataBackupList extends Command
{
    public function __construct(private readonly BackupService $backups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory to list (defaults to APP_BACKUP_DIR)')
            ->addOption('simple', 's', InputOption::VALUE_NONE, 'Print paths only, one per line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $dir = \is_string($dir) ? $dir : null;

        $snapshots = $this->backups->listSnapshots($dir);

        if ($snapshots === []) {
            $io->writeln(\sprintf('No snapshots in %s', $this->backups->targetDir($dir)));

            return Command::SUCCESS;
        }

        if ($input->getOption('simple')) {
            foreach ($snapshots as $s) {
                $io->writeln($s['path']);
            }

            return Command::SUCCESS;
        }

        $now = time();
        $table = new Table($output);
        $table->setHeaders(['Snapshot', 'Size', 'Age']);
        foreach ($snapshots as $s) {
            $table->addRow([
                basename($s['path']),
                BackupService::formatBytes($s['bytes']),
                self::humanAge($now - $s['mtime']),
            ]);
        }
        $table->render();

        $io->writeln(\sprintf('%d snapshot(s) in %s', \count($snapshots), $this->backups->targetDir($dir)));

        return Command::SUCCESS;
    }

    private static function humanAge(int $seconds): string
    {
        if ($seconds < 3600) {
            return \sprintf('%d min', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return \sprintf('%d h', intdiv($seconds, 3600));
        }

        return \sprintf('%d d', intdiv($seconds, 86400));
    }
}
