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
use App\Service\DataLifecycle\RestoreService;
use App\Service\DataLifecycle\SnapshotInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Replace the live database with a snapshot. Run with the application stopped.
 */
#[AsCommand(name: 'data:restore', description: 'Replace the live database with a verified snapshot')]
final class DataRestore extends Command
{
    public function __construct(
        private readonly RestoreService $restore,
        private readonly BackupService $backups,
        private readonly SnapshotInspector $inspector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot to restore (defaults to the newest)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory to look in (defaults to APP_BACKUP_DIR)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt (for non-interactive use)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify and report, without replacing anything')
            ->setHelp(<<<'HELP'
Verifies the snapshot, copies the database it is about to overwrite to
<info>pre-restore-&lt;timestamp&gt;.sqlite</info> beside it, and only then swaps it in.
Nothing touches the live file until a readable replacement is in place.

<comment>Stop the application first.</comment> Replacing the file underneath a running
process leaves it holding the old one open, so it would keep serving
pre-restore data until restart — a restore that looks like it did nothing:

  <info>docker compose stop app</info>
  <info>docker compose run --rm app php bin/console data:restore</info>
  <info>docker compose up -d</info>

  <info>php %command.full_name%</info> <comment>--dry-run</comment>    # verify without touching anything

If a restore turns out to be the wrong call, the pre-restore copy beside the
database is the way back.
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dir = $input->getOption('dir');
        $dir = \is_string($dir) ? $dir : null;

        $snapshot = $this->resolveSnapshot($input, $dir);
        if ($snapshot === null) {
            $io->error(\sprintf('No snapshots found in %s', $this->backups->targetDir($dir)));

            return Command::FAILURE;
        }

        try {
            $live = $this->backups->sourcePath();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        // Report before asking, so the confirmation is an informed one: what is
        // in the snapshot, and how much data replacing the live file would drop.
        $io->writeln(\sprintf('Snapshot: %s', $snapshot));
        $scratch = sys_get_temp_dir().'/phritzbox-verify';
        $materialized = null;
        try {
            $materialized = $this->inspector->materialize($snapshot, $scratch);
            $report = $this->inspector->inspect($materialized['path']);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } finally {
            if ($materialized !== null && $materialized['temporary'] && is_file($materialized['path'])) {
                @unlink($materialized['path']);
            }
        }

        if (!$report['ok']) {
            $io->error(\sprintf('Refusing to restore: %s', $report['error'] ?? 'verification failed'));

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Readings' => number_format($report['rows'])],
            ['Devices' => (string) $report['devices']],
            ['Newest reading' => $report['newest'] ?? '—'],
            ['Migration' => $report['migration'] ?? '—'],
            ['Target' => $live],
        );

        if ($input->getOption('dry-run')) {
            $io->success('Snapshot verified. Nothing was changed.');

            return Command::SUCCESS;
        }

        // A positive hit here is decisive — something is using the database
        // right now — so refuse outright rather than asking. --force does not
        // override it: the answer is to stop the app, not to insist harder.
        if ($this->restore->isDatabaseBusy()) {
            $io->error('The database is in use by another process right now.');
            $io->writeln($this->howToStop());

            return Command::FAILURE;
        }

        $io->warning([
            'This replaces the live database with the snapshot above.',
            \sprintf('%s readings currently in %s will be replaced.', number_format($this->liveReadings($live)), basename($live)),
            'The application must be stopped first.',
            'Restoring underneath a running app leaves it serving the old data.',
        ]);

        $force = (bool) $input->getOption('force');

        if (!$force && !$input->isInteractive()) {
            $io->error('Refusing to restore without confirmation. Re-run interactively, or pass --force if you have already stopped the application.');

            return Command::FAILURE;
        }

        if (!$force) {
            // Asked separately from "do you want this?" on purpose: one blended
            // prompt gets answered on autopilot, and this is the question that
            // actually decides whether the restore works.
            if (!$io->confirm('Have you stopped the application?', false)) {
                $io->writeln('');
                $io->writeln('Nothing was changed. Stop it first, then run this again:');
                $io->writeln($this->howToStop());

                return Command::SUCCESS;
            }

            if (!$io->confirm(\sprintf('Replace %s now?', $live), false)) {
                $io->writeln('Aborted. Nothing was changed.');

                return Command::SUCCESS;
            }
        }

        try {
            $result = $this->restore->restore($snapshot);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Restored %s', $result['restored']));
        $io->writeln(\sprintf('Previous database kept at %s', $result['safetyCopy']));
        $io->writeln('If this turns out to be the wrong snapshot, restore that file to undo:');
        $io->writeln(\sprintf('  php bin/console data:restore %s', $result['safetyCopy']));
        $io->writeln('');
        $io->writeln('Start the application again:');
        $io->writeln($this->howToStart());

        return Command::SUCCESS;
    }

    /** Readings in the database about to be replaced, for the confirmation text. */
    private function liveReadings(string $live): int
    {
        try {
            $pdo = new \PDO('sqlite:'.$live, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

            // @phpstan-ignore method.nonObject (ERRMODE_EXCEPTION: never false)
            return (int) $pdo->query('SELECT COUNT(*) FROM smart_device_data')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Concrete commands rather than "stop the application" — the instruction is
     * only useful if it can be pasted.
     */
    private function howToStop(): string
    {
        return self::inContainer()
            ? "  docker compose stop app\n"
              ."  docker compose run --rm app php bin/console data:restore\n"
              .'  docker compose up -d'
            : '  Stop the web server / PHP-FPM process serving Phritzbox, then run this again.';
    }

    private function howToStart(): string
    {
        return self::inContainer()
            ? '  docker compose up -d'
            : '  Start the web server / PHP-FPM process again.';
    }

    private static function inContainer(): bool
    {
        return is_file('/.dockerenv');
    }

    private function resolveSnapshot(InputInterface $input, ?string $dir): ?string
    {
        $explicit = $input->getArgument('snapshot');
        if (\is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        // snapshotPaths() is newest-first.
        return $this->backups->snapshotPaths($dir)[0] ?? null;
    }
}
