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

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Consistent, verified, compressed snapshots of the SQLite database.
 *
 * Uses `VACUUM INTO` rather than copying the file: it produces a defragmented
 * snapshot of a single committed state while the application keeps serving, and
 * cannot capture the torn mid-write file that `cp` on a live database can.
 *
 * The snapshot is taken over a dedicated PDO connection opened from the database
 * path, not over the injected DBAL connection. `VACUUM INTO` is rejected inside
 * an open transaction, and the test suite wraps every test in one
 * (dama/doctrine-test-bundle) — using the shared connection would make the
 * command untestable and, worse, would fail at runtime the moment it were ever
 * called from inside a transactional code path.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class BackupService
{
    /** Snapshot must fit, with headroom for the gzip pass that follows. */
    private const FREE_SPACE_FACTOR = 1.2;

    private const FILE_PREFIX = 'phritzbox-';

    public function __construct(
        private readonly Connection $connection,
        private readonly AppState $appState,
        // resolve: expands %kernel.project_dir% inside the env value, the same way
        // DATABASE_URL is read. Without it the placeholder reaches the filesystem
        // verbatim and every run fails with "directory is not writable".
        #[Autowire('%env(resolve:APP_BACKUP_DIR)%')]
        private readonly string $backupDir,
        #[Autowire('%env(int:APP_BACKUP_KEEP)%')]
        private readonly int $keep,
    ) {
    }

    /**
     * @throws \RuntimeException when the database is not a usable SQLite file,
     *                           free space is insufficient, or the snapshot
     *                           fails its integrity check
     *
     * @return array{path: string, bytes: int, sourceBytes: int, pruned: list<string>}
     */
    public function run(?string $dirOverride = null, ?int $keepOverride = null, bool $compress = true, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $dir = $this->targetDir($dirOverride);
        $keep = $keepOverride ?? $this->keep;

        $source = $this->sourcePath();
        $sourceBytes = (int) filesize($source);

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Backup directory "%s" could not be created', $dir));
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException(\sprintf('Backup directory "%s" is not writable', $dir));
        }

        $free = @disk_free_space($dir);
        if ($free !== false && $free < $sourceBytes * self::FREE_SPACE_FACTOR) {
            throw new \RuntimeException(\sprintf('Not enough free space in "%s": %s available, at least %s needed', $dir, self::formatBytes((int) $free), self::formatBytes((int) ($sourceBytes * self::FREE_SPACE_FACTOR))));
        }

        $stamp = $now->format('Ymd-His');
        $snapshot = mb_rtrim($dir, '/').'/'.self::FILE_PREFIX.$stamp.'.sqlite';

        // A leftover from an aborted run would make VACUUM INTO fail outright.
        if (file_exists($snapshot)) {
            @unlink($snapshot);
        }

        $this->vacuumInto($source, $snapshot);
        $this->assertIntact($snapshot);

        $final = $snapshot;
        if ($compress) {
            $final = $this->gzip($snapshot);
            @unlink($snapshot);
        }

        // Only prune once a verified snapshot exists, so a failed run can never
        // cost you the backups you already had.
        $pruned = $this->rotate($dir, $keep);

        $this->appState->setInstant(AppState::LAST_BACKUP_AT, $now, $now);

        return [
            'path' => $final,
            'bytes' => (int) filesize($final),
            'sourceBytes' => $sourceBytes,
            'pruned' => $pruned,
        ];
    }

    /** The directory snapshots are written to, with the configured default applied. */
    public function targetDir(?string $override = null): string
    {
        return $override ?? $this->backupDir;
    }

    /**
     * Snapshot paths in this command's own naming, newest first.
     *
     * The names carry a sortable Ymd-His stamp, so lexical order is
     * chronological — no stat() per file needed to sort them.
     *
     * @return list<string>
     */
    public function snapshotPaths(?string $dirOverride = null): array
    {
        $dir = $this->targetDir($dirOverride);
        $found = glob(mb_rtrim($dir, '/').'/'.self::FILE_PREFIX.'*.sqlite{,.gz}', \GLOB_BRACE);
        if ($found === false) {
            return [];
        }

        rsort($found);

        return $found;
    }

    /**
     * Snapshots with their on-disk size and age, newest first.
     *
     * @return list<array{path: string, bytes: int, mtime: int}>
     */
    public function listSnapshots(?string $dirOverride = null): array
    {
        return array_map(static fn (string $p): array => [
            'path' => $p,
            'bytes' => (int) filesize($p),
            'mtime' => (int) filemtime($p),
        ], $this->snapshotPaths($dirOverride));
    }

    /**
     * Path of the live SQLite file.
     *
     * @throws \RuntimeException when the connection is not file-backed SQLite
     */
    public function sourcePath(): string
    {
        $params = $this->connection->getParams();
        $path = $params['path'] ?? null;

        if (!\is_string($path) || $path === '' || $path === ':memory:') {
            throw new \RuntimeException('cron:data:backup only supports a file-backed SQLite database');
        }
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Database file "%s" does not exist', $path));
        }

        return $path;
    }

    private function vacuumInto(string $source, string $target): void
    {
        // Read-only would reject VACUUM INTO; the source is opened read-write but
        // only ever read from.
        $pdo = new \PDO('sqlite:'.$source, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->prepare('VACUUM INTO ?');
        $statement->execute([$target]);
    }

    /**
     * @throws \RuntimeException when the snapshot is missing or fails quick_check
     */
    private function assertIntact(string $snapshot): void
    {
        if (!is_file($snapshot)) {
            throw new \RuntimeException(\sprintf('Snapshot "%s" was not created', $snapshot));
        }

        $pdo = new \PDO('sqlite:'.$snapshot, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        // ERRMODE_EXCEPTION means query() throws rather than returning false.
        $result = $pdo->query('PRAGMA quick_check')->fetchColumn();

        if ($result !== 'ok') {
            @unlink($snapshot);
            throw new \RuntimeException(\sprintf('Snapshot failed its integrity check: %s', var_export($result, true)));
        }
    }

    private function gzip(string $snapshot): string
    {
        $target = $snapshot.'.gz';

        $in = fopen($snapshot, 'r');
        if ($in === false) {
            throw new \RuntimeException(\sprintf('Cannot read snapshot "%s"', $snapshot));
        }

        $out = gzopen($target, 'wb6');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException(\sprintf('Cannot write "%s"', $target));
        }

        // Streamed in chunks: a multi-GB database must never be held in memory.
        while (!feof($in)) {
            $chunk = fread($in, 4 * 1024 * 1024);
            if ($chunk === false) {
                fclose($in);
                gzclose($out);
                throw new \RuntimeException(\sprintf('Read error while compressing "%s"', $snapshot));
            }
            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);

        return $target;
    }

    /**
     * Delete the oldest snapshots beyond `keep`.
     *
     * Matches only this command's own naming, in the configured directory, so a
     * misconfigured path cannot turn rotation into a directory wipe.
     *
     * @return list<string> paths that were removed
     */
    private function rotate(string $dir, int $keep): array
    {
        if ($keep < 1) {
            return [];
        }

        $found = $this->snapshotPaths($dir);
        $pruned = [];
        foreach (\array_slice($found, $keep) as $old) {
            if (@unlink($old)) {
                $pruned[] = $old;
            }
        }

        return $pruned;
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < \count($units) - 1) {
            $value /= 1024;
            ++$i;
        }

        return \sprintf('%.1f %s', $value, $units[$i]);
    }
}
