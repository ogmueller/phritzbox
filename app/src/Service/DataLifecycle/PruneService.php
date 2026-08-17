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

namespace App\Service\DataLifecycle;

use App\Repository\AlertEventRepository;
use App\Repository\RefreshTokenRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes what retention says is no longer needed.
 *
 * The only irreversible part of the data lifecycle, so the safeguards are the
 * design rather than an afterthought:
 *
 *  - Every retention window defaults to "keep forever". An image update can
 *    never start deleting somebody's history; an operator has to opt in.
 *  - Raw readings are only removed where a rollup bucket already covers them.
 *    The cutoff is the *earlier* of the retention window and the rollup
 *    watermark, so a stalled or never-run rollup job means nothing is deleted
 *    rather than history being dropped un-aggregated.
 *  - Work happens one (sid, type, day) window at a time. journal_mode is
 *    `delete`, so a long-running write would block every reader for its whole
 *    duration; a per-day statement keeps each transaction short.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class PruneService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RollupService $rollup,
        private readonly RetentionConfig $retention,
        private readonly AlertEventRepository $alertEvents,
        private readonly RefreshTokenRepository $refreshTokens,
        #[Autowire('%env(int:APP_RETENTION_ALERT_EVENT_DAYS)%')]
        private readonly int $alertEventDays,
        #[Autowire('%env(int:APP_RETENTION_LOG_DAYS)%')]
        private readonly int $logDays,
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
    ) {
    }

    /**
     * The instant before which raw readings of a metric may be deleted.
     *
     * Null means "delete nothing", which is the answer whenever retention is
     * off for that metric or the rollup has not caught up.
     */
    public function rawCutoff(string $type, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $byRetention = $this->retention->rawCutoff($now, $type);
        if ($byRetention === null) {
            return null;
        }

        $watermark = $this->rollup->watermark(RollupService::GRID_QUARTER);
        if ($watermark === null) {
            // Nothing has ever been aggregated: deleting raw would destroy
            // history outright instead of compacting it.
            return null;
        }

        return min($byRetention, $watermark);
    }

    /**
     * Delete raw readings of one (sid, type) older than its cutoff.
     *
     * @return int rows deleted
     */
    public function pruneRawPair(string $sid, string $type, \DateTimeImmutable $now, int $maxDelete, bool $dryRun = false): int
    {
        $cutoff = $this->rawCutoff($type, $now);
        if ($cutoff === null || $maxDelete < 1) {
            return 0;
        }
        $cutoffSql = $cutoff->format('Y-m-d H:i:s');

        $deleted = 0;
        while ($deleted < $maxDelete) {
            // Jump straight to the oldest day that still has rows rather than
            // walking the calendar — years of gaps would otherwise cost one
            // pointless statement per empty day.
            $oldest = $this->connection->fetchOne(
                'SELECT MIN(time) FROM smart_device_data WHERE sid = :sid AND type = :type AND time < :cutoff',
                ['sid' => $sid, 'type' => $type, 'cutoff' => $cutoffSql],
            );
            if (!\is_string($oldest) || $oldest === '') {
                break;
            }

            $dayStart = (new \DateTimeImmutable($oldest))->setTime(0, 0);
            $dayEnd = $dayStart->modify('+1 day');
            // Never step past the cutoff on the boundary day.
            $upper = min($dayEnd, $cutoff)->format('Y-m-d H:i:s');

            if ($dryRun) {
                return (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM smart_device_data WHERE sid = :sid AND type = :type AND time < :cutoff',
                    ['sid' => $sid, 'type' => $type, 'cutoff' => $cutoffSql],
                );
            }

            $deleted += (int) $this->connection->executeStatement(
                'DELETE FROM smart_device_data WHERE sid = :sid AND type = :type AND time >= :from AND time < :to',
                ['sid' => $sid, 'type' => $type, 'from' => $dayStart->format('Y-m-d H:i:s'), 'to' => $upper],
            );
        }

        return $deleted;
    }

    /**
     * Delete rollup buckets of a grid older than its retention window.
     *
     * @return int rows deleted
     */
    public function pruneRollup(int $grid, \DateTimeImmutable $now, bool $dryRun = false): int
    {
        $days = $grid === RollupService::GRID_QUARTER ? $this->retention->quarterDays : $this->retention->dailyDays;
        if ($days < 1) {
            return 0;
        }

        $cutoff = $now->modify(\sprintf('-%d days', $days))->format('Y-m-d H:i:s');
        $sql = 'FROM smart_device_data_rollup WHERE grid = :grid AND bucket < :cutoff';
        $params = ['grid' => $grid, 'cutoff' => $cutoff];

        return $dryRun
            ? (int) $this->connection->fetchOne('SELECT COUNT(*) '.$sql, $params)
            : (int) $this->connection->executeStatement('DELETE '.$sql, $params);
    }

    /** @return int rows deleted */
    public function pruneAlertEvents(\DateTimeImmutable $now, bool $dryRun = false): int
    {
        if ($this->alertEventDays < 1) {
            return 0;
        }

        $cutoff = $now->modify(\sprintf('-%d days', $this->alertEventDays));

        return $dryRun
            ? $this->alertEvents->countOlderThan($cutoff)
            : $this->alertEvents->purgeOlderThan($cutoff);
    }

    /**
     * Remove refresh tokens that expired.
     *
     * The repository method for this has existed since refresh tokens were
     * introduced and was never called from anywhere, so the table only ever grew.
     *
     * @return int rows deleted
     */
    public function pruneRefreshTokens(\DateTimeImmutable $now, bool $dryRun = false): int
    {
        return $dryRun
            ? $this->refreshTokens->countExpired($now)
            : $this->refreshTokens->purgeExpired($now);
    }

    /**
     * Delete rotated log files older than the log window.
     *
     * Never touches a file carrying today's date: unlinking the file an open
     * handler is still writing to frees no space until the process restarts, and
     * on FrankenPHP that means a container restart.
     *
     * @return list<string> files removed
     */
    public function pruneLogs(\DateTimeImmutable $now, bool $dryRun = false): array
    {
        if ($this->logDays < 1 || !is_dir($this->logsDir)) {
            return [];
        }

        $cutoff = $now->modify(\sprintf('-%d days', $this->logDays))->getTimestamp();
        $today = $now->format('Y-m-d');
        $removed = [];

        foreach (glob(mb_rtrim($this->logsDir, '/').'/*.log') ?: [] as $file) {
            if (str_contains(basename($file), $today)) {
                continue;
            }
            if ((int) filemtime($file) >= $cutoff) {
                continue;
            }
            if ($dryRun || @unlink($file)) {
                $removed[] = $file;
            }
        }

        return $removed;
    }

    /**
     * Path of the live SQLite file, for operations that need their own connection.
     *
     * @throws \RuntimeException when the connection is not file-backed SQLite
     */
    public function databasePath(): string
    {
        $path = $this->connection->getParams()['path'] ?? null;
        if (!\is_string($path) || $path === '' || $path === ':memory:') {
            throw new \RuntimeException('--vacuum requires a file-backed SQLite database');
        }

        return $path;
    }

    /**
     * Page accounting for the SQLite file.
     *
     * Reported before and after a prune because deleting rows does *not* shrink
     * the file: SQLite keeps the freed pages on a free list and reuses them. An
     * operator who removes 40 million rows and sees the same file size on disk
     * would reasonably conclude the command is broken.
     *
     * @return array{bytes: int, freeBytes: int, pageSize: int}
     */
    public function spaceReport(): array
    {
        $pageSize = (int) $this->connection->fetchOne('PRAGMA page_size');
        $pageCount = (int) $this->connection->fetchOne('PRAGMA page_count');
        $freeList = (int) $this->connection->fetchOne('PRAGMA freelist_count');

        return [
            'bytes' => $pageCount * $pageSize,
            'freeBytes' => $freeList * $pageSize,
            'pageSize' => $pageSize,
        ];
    }
}
