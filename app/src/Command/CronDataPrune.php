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

use App\Service\DataLifecycle\AppState;
use App\Service\DataLifecycle\BackupService;
use App\Service\DataLifecycle\PruneService;
use App\Service\DataLifecycle\RetentionConfig;
use App\Service\DataLifecycle\RollupService;
use App\Service\MetricUnits;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Apply retention: delete what is no longer needed.
 *
 * Does nothing at all until a retention window is configured — every one
 * defaults to "keep forever", so pulling a new image can never start deleting
 * data on its own.
 */
#[AsCommand(name: 'cron:data:prune', description: 'Apply retention windows (deletes data — opt-in)')]
final class CronDataPrune extends Command
{
    private const LOCK_MINUTES = 120;

    public function __construct(
        private readonly PruneService $prune,
        private readonly RollupService $rollup,
        private readonly AppState $appState,
        private readonly RetentionConfig $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-delete', null, InputOption::VALUE_REQUIRED, 'Cap on raw rows deleted per metric per run', '250000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted, without deleting')
            ->addOption('vacuum', null, InputOption::VALUE_NONE, 'Rebuild the file afterwards to actually reclaim disk space')
            ->setHelp(<<<'HELP'
Deletes only where retention is configured, and raw readings only where a rollup
bucket already covers them — if the rollup has never run, nothing is removed.

  <info>php %command.full_name%</info> <comment>--dry-run</comment>    # always start here

<comment>Deleting rows does not shrink the database file.</comment> SQLite keeps the freed
pages on a free list and reuses them, so the size on disk stays flat until the
file is rebuilt. This command reports the free-page total; pass
<info>--vacuum</info> to rebuild — which needs roughly as much free disk as the
database itself and takes an exclusive lock for minutes, so run it in a
maintenance window rather than from cron.
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $dryRun = (bool) $input->getOption('dry-run');
        $maxDelete = max(0, (int) $input->getOption('max-delete'));

        if (!$dryRun && !$this->appState->tryLock(AppState::ROLLUP_LOCK, $now->modify(\sprintf('+%d minutes', self::LOCK_MINUTES)), $now)) {
            $io->writeln('A rollup or prune is already running; nothing to do.');

            return Command::SUCCESS;
        }

        $unknown = $this->retention->unknownOverrides();
        if ($unknown !== []) {
            $io->warning(\sprintf(
                'APP_RETENTION_RAW_OVERRIDES names no such metric: %s. Valid names: %s.',
                implode(', ', $unknown),
                implode(', ', MetricUnits::TYPES),
            ));
        }

        $before = $this->prune->spaceReport();

        try {
            $rawRows = $this->pruneRaw($io, $now, $maxDelete, $dryRun);

            $quarter = $this->prune->pruneRollup(RollupService::GRID_QUARTER, $now, $dryRun);
            $daily = $this->prune->pruneRollup(RollupService::GRID_DAY, $now, $dryRun);
            $events = $this->prune->pruneAlertEvents($now, $dryRun);
            $tokens = $this->prune->pruneRefreshTokens($now, $dryRun);
            $logs = $this->prune->pruneLogs($now, $dryRun);
        } finally {
            if (!$dryRun) {
                $this->appState->releaseLock(AppState::ROLLUP_LOCK);
            }
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $io->definitionList(
            [$verb.' raw readings' => number_format($rawRows)],
            ['quarter-hour buckets' => number_format($quarter)],
            ['daily buckets' => number_format($daily)],
            ['alert events' => number_format($events)],
            ['expired refresh tokens' => number_format($tokens)],
            ['log files' => (string) \count($logs)],
        );

        if ($dryRun) {
            $io->writeln('Nothing was changed.');

            return Command::SUCCESS;
        }

        $this->reportSpace($io, $before, $input->getOption('vacuum') ? null : $this->prune->spaceReport());

        if ($input->getOption('vacuum')) {
            $io->writeln('Rebuilding the database file…');
            $this->vacuum();
            $this->reportSpace($io, $before, $this->prune->spaceReport());
        }

        return Command::SUCCESS;
    }

    private function pruneRaw(SymfonyStyle $io, \DateTimeImmutable $now, int $maxDelete, bool $dryRun): int
    {
        $total = 0;
        $skipped = [];

        foreach ($this->rollup->pairs() as $pair) {
            if ($this->prune->rawCutoff($pair['type'], $now) === null) {
                $skipped[$pair['type']] = true;
                continue;
            }
            $total += $this->prune->pruneRawPair($pair['sid'], $pair['type'], $now, $maxDelete, $dryRun);
        }

        // Never let a bounded run look like a completed one.
        if ($skipped !== []) {
            $io->writeln(\sprintf(
                'Raw retention is off (or the rollup has not caught up) for: %s',
                implode(', ', array_keys($skipped)),
            ));
        }

        return $total;
    }

    /**
     * @param array{bytes: int, freeBytes: int, pageSize: int}      $before
     * @param array{bytes: int, freeBytes: int, pageSize: int}|null $after
     */
    private function reportSpace(SymfonyStyle $io, array $before, ?array $after): void
    {
        if ($after === null) {
            return;
        }

        $io->writeln(\sprintf(
            'Database file %s (was %s); %s now reusable free pages.',
            BackupService::formatBytes($after['bytes']),
            BackupService::formatBytes($before['bytes']),
            BackupService::formatBytes($after['freeBytes']),
        ));

        if ($after['freeBytes'] > 0 && $after['bytes'] >= $before['bytes']) {
            $io->writeln('The file has not shrunk: SQLite reuses freed pages. Run with --vacuum to reclaim the space on disk.');
        }
    }

    /**
     * VACUUM cannot run inside a transaction, so it needs its own connection —
     * the same reason the backup command opens one.
     */
    private function vacuum(): void
    {
        $path = $this->prune->databasePath();
        $pdo = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('VACUUM');
    }
}
