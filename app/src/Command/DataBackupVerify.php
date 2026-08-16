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
use App\Service\DataLifecycle\SnapshotInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Open a snapshot and report whether it could actually be restored.
 *
 * Safe to run at any time: it only ever reads, into a scratch copy.
 */
#[AsCommand(name: 'data:backup:verify', description: 'Check that a snapshot is intact and restorable')]
final class DataBackupVerify extends Command
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SnapshotInspector $inspector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot to check (defaults to the newest)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Check every snapshot in the backup directory')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory to look in (defaults to APP_BACKUP_DIR)')
            ->setHelp(<<<'HELP'
Decompresses the snapshot to a scratch copy, runs SQLite's integrity check,
confirms it really is a Phritzbox database, and reports what it holds — row
count, date span, devices, and the migration it was taken at.

  <info>php %command.full_name%</info>                       # newest snapshot
  <info>php %command.full_name%</info> <comment>--all</comment>                 # every snapshot
  <info>php %command.full_name%</info> <comment>/path/to/snap.sqlite.gz</comment>

Exits non-zero if any snapshot fails, so it can be wired into monitoring. An
untested backup is only a hypothesis; this is what turns it into a fact.
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $dir = \is_string($dir) ? $dir : null;

        $targets = $this->resolveTargets($input, $dir);
        if ($targets === []) {
            $io->warning(\sprintf('No snapshots found in %s', $this->backups->targetDir($dir)));

            return Command::FAILURE;
        }

        $scratch = sys_get_temp_dir().'/phritzbox-verify';
        $failed = 0;

        foreach ($targets as $path) {
            $io->section(basename($path));

            $materialized = null;
            try {
                $materialized = $this->inspector->materialize($path, $scratch);
                $report = $this->inspector->inspect($materialized['path']);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                ++$failed;
                continue;
            } finally {
                if ($materialized !== null && $materialized['temporary'] && is_file($materialized['path'])) {
                    @unlink($materialized['path']);
                }
            }

            if (!$report['ok']) {
                $io->error($report['error'] ?? 'verification failed');
                ++$failed;
                continue;
            }

            $rows = [
                ['Readings', number_format($report['rows'])],
                ['Devices', (string) $report['devices']],
                ['Oldest', $report['oldest'] ?? '—'],
                ['Newest', $report['newest'] ?? '—'],
                ['Migration', $report['migration'] ?? '—'],
            ];
            foreach ($report['types'] as $type => $count) {
                $rows[] = ['  '.$type, number_format($count)];
            }
            $io->definitionList(...array_map(static fn (array $r): array => [$r[0] => $r[1]], $rows));
            $io->success('Restorable');
        }

        if ($failed > 0) {
            $io->error(\sprintf('%d of %d snapshot(s) failed verification', $failed, \count($targets)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveTargets(InputInterface $input, ?string $dir): array
    {
        $explicit = $input->getArgument('snapshot');
        if (\is_string($explicit) && $explicit !== '') {
            return [$explicit];
        }

        $all = $this->backups->snapshotPaths($dir);
        if ($all === []) {
            return [];
        }

        // snapshotPaths() is newest-first, so the head is the latest backup.
        return $input->getOption('all') ? $all : [$all[0]];
    }
}
