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
 * Keep the pre-aggregated tiers current.
 *
 * Incremental: it reprocesses from the last watermark, minus a lateness window,
 * so readings that arrived out of order still land in their bucket. Populating
 * the tiers for the first time is smart:rollup:backfill.
 */
#[AsCommand(name: 'cron:smart:rollup', description: 'Aggregate new readings into the rollup tiers')]
final class CronSmartRollup extends Command
{
    /** Long enough to cover a stuck run, short enough to self-heal within an hour. */
    private const LOCK_MINUTES = 55;

    public function __construct(
        private readonly RollupService $rollup,
        private readonly AppState $appState,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lateness-hours', null, InputOption::VALUE_REQUIRED, 'How far back to reprocess for out-of-order readings', '3')
            ->addOption('max-seconds', null, InputOption::VALUE_REQUIRED, 'Stop cleanly after roughly this long', '300')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the window without writing')
            ->setHelp(<<<'HELP'
Aggregates raw readings into the quarter-hour tier, then derives the daily tier
from it. Only complete buckets are written, so the in-progress quarter-hour is
left alone until it closes.

Safe to run repeatedly: every bucket is recomputed and replaced, never added to,
so an overlapping or repeated run cannot double-count.
HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $lateness = max(0, (int) $input->getOption('lateness-hours'));
        $maxSeconds = max(0, (int) $input->getOption('max-seconds'));

        // Only complete buckets: the quarter-hour in progress would be rewritten
        // on the next run anyway, and writing it invites a half-empty bucket
        // being read as a real dip.
        $through = self::floorTo($now, RollupService::GRID_QUARTER);

        $watermark = $this->rollup->watermark(RollupService::GRID_QUARTER);
        $from = $watermark?->modify(\sprintf('-%d hours', $lateness));

        $fromSql = $from?->format('Y-m-d H:i:s');
        $throughSql = $through->format('Y-m-d H:i:s');

        if ($input->getOption('dry-run')) {
            $io->writeln(\sprintf('Would roll up %s .. %s', $fromSql ?? 'the beginning', $throughSql));

            return Command::SUCCESS;
        }

        if (!$this->appState->tryLock(AppState::ROLLUP_LOCK, $now->modify(\sprintf('+%d minutes', self::LOCK_MINUTES)), $now)) {
            $io->writeln('Another rollup is running; nothing to do.');

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $pairs = $this->rollup->pairs();
        $written = 0;
        $done = 0;

        try {
            foreach ($pairs as $pair) {
                $written += $this->rollup->rollUpPair($pair['sid'], $pair['type'], $fromSql, $throughSql);
                ++$done;

                if (microtime(true) - $started > $maxSeconds) {
                    // Stop without advancing the watermark: the pairs not reached
                    // must be picked up by the next run, so the window has to stay open.
                    $io->warning(\sprintf('Time budget reached after %d of %d pair(s); the watermark was not advanced.', $done, \count($pairs)));
                    $io->writeln(\sprintf('%d bucket(s) written', $written));

                    return Command::SUCCESS;
                }
            }

            $this->rollup->setWatermark(RollupService::GRID_QUARTER, $through);
            $this->appState->setInstant(AppState::LAST_ROLLUP_AT, $now, $now);
        } finally {
            $this->appState->releaseLock(AppState::ROLLUP_LOCK);
        }

        $io->writeln(\sprintf(
            '%d bucket(s) written across %d pair(s) in %.1fs (through %s)',
            $written,
            \count($pairs),
            microtime(true) - $started,
            $throughSql,
        ));

        return Command::SUCCESS;
    }

    public static function floorTo(\DateTimeImmutable $moment, int $grid): \DateTimeImmutable
    {
        return $moment->setTimestamp(intdiv($moment->getTimestamp(), $grid) * $grid);
    }
}
