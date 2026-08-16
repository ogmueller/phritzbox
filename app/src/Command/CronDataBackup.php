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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Write a verified, compressed snapshot of the SQLite database.
 *
 * Scheduled from the cronado labels in the operator's compose file — an image
 * update alone will not start running it.
 */
#[AsCommand(name: 'cron:data:backup', description: 'Write a verified, compressed snapshot of the database')]
final class CronDataBackup extends Command
{
    public function __construct(private readonly BackupService $backups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Target directory (defaults to APP_BACKUP_DIR)')
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'How many snapshots to retain (defaults to APP_BACKUP_KEEP)')
            ->addOption('no-compress', null, InputOption::VALUE_NONE, 'Leave the snapshot uncompressed')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen without writing anything')
            ->setHelp(<<<'HELP'
Takes a consistent snapshot with SQLite's <info>VACUUM INTO</info>, so the
database can stay in use while it runs, then verifies the copy with
<info>PRAGMA quick_check</info> before compressing it and rotating older
snapshots away. A snapshot that fails verification is discarded and existing
backups are left untouched.

  <info>php %command.full_name%</info>
  <info>php %command.full_name%</info> <comment>--dir=/mnt/nas/phritzbox --keep=14</comment>

<comment>The default target lives inside the persisted data volume.</comment> That protects
against corruption and bad migrations, but not against losing the volume — set
APP_BACKUP_DIR to a bind mount for copies that survive the host.
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dir = $input->getOption('dir');
        $keep = $input->getOption('keep');
        $keep = $keep === null ? null : max(0, (int) $keep);

        try {
            if ($input->getOption('dry-run')) {
                $source = $this->backups->sourcePath();
                $io->writeln(\sprintf(
                    'Would back up %s (%s) to %s',
                    $source,
                    BackupService::formatBytes((int) filesize($source)),
                    // The resolved directory, not the variable name — a dry run
                    // is where a misconfigured path should become obvious.
                    $this->backups->targetDir(\is_string($dir) ? $dir : null),
                ));

                return Command::SUCCESS;
            }

            $result = $this->backups->run($dir, $keep, !$input->getOption('no-compress'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(\sprintf(
            'Backed up %s to %s (%s)',
            BackupService::formatBytes($result['sourceBytes']),
            $result['path'],
            BackupService::formatBytes($result['bytes']),
        ));

        if ($result['pruned'] !== []) {
            $io->writeln(\sprintf('Removed %d older snapshot(s)', \count($result['pruned'])));
        }

        return Command::SUCCESS;
    }
}
