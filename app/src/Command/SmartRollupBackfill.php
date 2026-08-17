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
use App\Service\DataLifecycle\RollupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Populate the rollup tiers from existing history.
 *
 * Deliberately a separate command rather than part of a migration: aggregating
 * years of readings takes minutes, and a migration runs inside the container
 * entrypoint where that would exhaust the healthcheck grace period and
 * crash-loop the deployment.
 *
 * Work is done one (sid, type) pair at a time, which is the only shape the
 * index supports. A date-ranged scan across all devices has no usable index —
 * the unique index leads with `sid` — so chunking by day would re-scan the
 * whole table for every chunk.
 */
#[AsCommand(name: 'smart:rollup:backfill', description: 'Build the rollup tiers from existing history')]
final class SmartRollupBackfill extends Command
{
    private const LOCK_MINUTES = 120;

    public function __construct(
        private readonly RollupService $rollup,
        private readonly AppState $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Stop cleanly after roughly this long and save progress', '600')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ignore saved progress and start from the first pair')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the work without doing it')
            ->setHelp(<<<'HELP'
Aggregates all existing readings into the rollup tiers. Read-only with respect
to the readings themselves — it only ever writes to the rollup table, so it is
safe to run against live data.

Interruptible and resumable: progress is saved after every pair, so a run that
hits its time budget (or is killed) continues where it left off.

  <info>php %command.full_name%</info>
  <info>php %command.full_name%</info> <comment>--max-seconds=60</comment>   # a slice at a time
  <info>php %command.full_name%</info> <comment>--force</comment>            # start over
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        // 0 is meaningful: stop after a single pair. Useful for working through
        // a large backfill in controlled slices.
        $maxSeconds = max(0, (int) $input->getOption('max-seconds'));

        $pairs = $this->rollup->pairs();
        if ($pairs === []) {
            $io->writeln('No readings to roll up.');

            return Command::SUCCESS;
        }

        $start = $input->getOption('force') ? 0 : $this->savedCursor();
        if ($start >= \count($pairs)) {
            $start = 0;
        }

        if ($input->getOption('dry-run')) {
            $io->writeln(\sprintf('%d pair(s) to process, starting at #%d:', \count($pairs), $start + 1));
            foreach (\array_slice($pairs, $start) as $pair) {
                $io->writeln(\sprintf('  %s / %s', $pair['sid'], $pair['type']));
            }

            return Command::SUCCESS;
        }

        if (!$this->appState->tryLock(AppState::ROLLUP_LOCK, $now->modify(\sprintf('+%d minutes', self::LOCK_MINUTES)), $now)) {
            $io->error('Another rollup or backfill is already running.');

            return Command::FAILURE;
        }

        $started = microtime(true);
        $written = 0;
        $index = $start;

        $progress = $io->createProgressBar(\count($pairs));
        $progress->setFormat(' %current%/%max% [%bar%] %message%');
        $progress->setMessage('');
        $progress->start();
        $progress->advance($start);

        try {
            for (; $index < \count($pairs); ++$index) {
                $pair = $pairs[$index];
                $progress->setMessage(\sprintf('%s / %s', $pair['sid'], $pair['type']));

                // No window: this is the whole history for the pair, in one
                // index range scan.
                $written += $this->rollup->rollUpPair($pair['sid'], $pair['type']);

                $this->saveCursor($index + 1, $now);
                $progress->advance();

                if (microtime(true) - $started > $maxSeconds && $index + 1 < \count($pairs)) {
                    $progress->finish();
                    $io->newLine(2);
                    $io->warning(\sprintf(
                        'Time budget reached after %d of %d pair(s). Re-run to continue.',
                        $index + 1,
                        \count($pairs),
                    ));
                    $io->writeln(\sprintf('%d bucket(s) written', $written));

                    return Command::SUCCESS;
                }
            }

            $progress->finish();
            $io->newLine(2);

            // Everything up to now is aggregated, so the incremental job can
            // start from here instead of re-reading history.
            $this->rollup->setWatermark(RollupService::GRID_QUARTER, CronSmartRollup::floorTo($now, RollupService::GRID_QUARTER));
            $this->appState->set(AppState::ROLLUP_BACKFILL_CURSOR, '', $now);
        } finally {
            $this->appState->releaseLock(AppState::ROLLUP_LOCK);
        }

        $io->success(\sprintf(
            'Backfilled %d pair(s), %d bucket(s), in %.1fs',
            \count($pairs),
            $written,
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }

    private function savedCursor(): int
    {
        $raw = $this->appState->get(AppState::ROLLUP_BACKFILL_CURSOR);

        return $raw === null ? 0 : max(0, (int) $raw);
    }

    private function saveCursor(int $index, \DateTimeImmutable $now): void
    {
        $this->appState->set(AppState::ROLLUP_BACKFILL_CURSOR, (string) $index, $now);
    }
}
