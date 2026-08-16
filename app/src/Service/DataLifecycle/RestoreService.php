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

/**
 * Replaces the live database with a verified snapshot.
 *
 * This is the most destructive operation in the application, so the order of
 * work is deliberate: decompress, verify, snapshot what is about to be
 * overwritten, and only then swap. Nothing touches the live file until a
 * readable, schema-correct replacement is sitting next to it.
 *
 * It must run with the application stopped. Replacing the file underneath a
 * live process leaves that process holding a descriptor on the old inode, so it
 * would carry on serving the pre-restore data until restart — a restore that
 * silently appears to do nothing. That cannot be enforced from here: SQLite only
 * takes locks during transactions, so an idle pooled connection would not fail a
 * lock probe and the check would give a false all-clear. The safety snapshot and
 * an explicit confirmation are the mitigation instead.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class RestoreService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly SnapshotInspector $inspector,
    ) {
    }

    /**
     * @throws \RuntimeException when the snapshot is unreadable, is not a
     *                           Phritzbox database, or the swap cannot be done
     *
     * @return array{restored: string, safetyCopy: string, snapshot: array<string, mixed>}
     */
    public function restore(string $snapshotPath, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $live = $this->backups->sourcePath();
        // Same directory as the live file, so the final rename stays on one
        // filesystem and is therefore atomic. A cross-device rename would fall
        // back to a copy and could leave a half-written database behind.
        $workDir = \dirname($live);

        $materialized = $this->inspector->materialize($snapshotPath, $workDir);
        $plain = $materialized['path'];

        try {
            $report = $this->inspector->inspect($plain);
            if (!$report['ok']) {
                throw new \RuntimeException(\sprintf('Refusing to restore: %s', $report['error'] ?? 'snapshot failed verification'));
            }

            // Undo path for a restore performed in a hurry.
            $safety = $this->safetyCopy($live, $now);

            $this->swap($plain, $live, $materialized['temporary']);
        } catch (\Throwable $e) {
            if ($materialized['temporary'] && is_file($plain)) {
                @unlink($plain);
            }

            throw $e;
        }

        return [
            'restored' => $live,
            'safetyCopy' => $safety,
            'snapshot' => $report,
        ];
    }

    /**
     * Best-effort check for another process actively using the database.
     *
     * A `true` result is trustworthy: something holds a conflicting lock right
     * now, so restoring would be wrong. A `false` result proves nothing —
     * SQLite only takes locks for the duration of a transaction, so an app that
     * is up but idle looks exactly like an app that is stopped. Treat it as a
     * tripwire that catches the worst case, never as an all-clear.
     */
    public function isDatabaseBusy(): bool
    {
        try {
            $live = $this->backups->sourcePath();
            $pdo = new \PDO('sqlite:'.$live, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            // Short timeout: this is a probe, not something to wait a minute on.
            $pdo->exec('PRAGMA busy_timeout = 2000');
            $pdo->exec('BEGIN EXCLUSIVE');
            $pdo->exec('ROLLBACK');

            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Copy the database that is about to be overwritten, so the restore itself
     * can be undone. Written with VACUUM INTO for the same reason backups are.
     */
    private function safetyCopy(string $live, \DateTimeImmutable $now): string
    {
        $target = \dirname($live).'/pre-restore-'.$now->format('Ymd-His').'.sqlite';

        $pdo = new \PDO('sqlite:'.$live, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->prepare('VACUUM INTO ?')->execute([$target]);

        return $target;
    }

    /**
     * Move the verified database into place.
     *
     * The -wal and -shm sidecars of the *old* database must go with it. SQLite
     * would otherwise pair a freshly restored file with a stale write-ahead log
     * and either lose the restore or corrupt it outright.
     */
    private function swap(string $plain, string $live, bool $plainIsTemporary): void
    {
        $source = $plain;

        // An uncompressed snapshot given directly must be copied, not moved —
        // consuming the operator's file would destroy the backup being restored.
        if (!$plainIsTemporary) {
            $source = $live.'.incoming';
            if (!@copy($plain, $source)) {
                throw new \RuntimeException(\sprintf('Cannot stage "%s" next to the live database', $plain));
            }
        }

        if (!@rename($source, $live)) {
            @unlink($source);
            throw new \RuntimeException(\sprintf('Cannot move the restored database into "%s"', $live));
        }

        foreach (['-wal', '-shm'] as $sidecar) {
            if (is_file($live.$sidecar)) {
                @unlink($live.$sidecar);
            }
        }
    }
}
