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

/**
 * Small key/value store backed by the `app_state` table.
 *
 * The table has deliberately never been mapped as an entity: the values are
 * operational bookkeeping (when did collection last run, how far has the rollup
 * progressed), not domain data, and they are read and written from raw DBAL in
 * places that have no business loading an ORM object. This class exists so that
 * the upsert SQL lives in exactly one spot instead of being copy-pasted into
 * every job that needs a watermark.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class AppState
{
    /** Timestamp (ATOM) of the last successful stats collection. */
    public const LAST_COLLECTION_AT = 'last_collection_at';

    /** Timestamp (ATOM) of the last successful database backup. */
    public const LAST_BACKUP_AT = 'last_backup_at';

    /** Timestamp (ATOM) of the last successful rollup run. */
    public const LAST_ROLLUP_AT = 'last_rollup_at';

    /** Bucket boundary before which every rollup bucket of that grid is complete. */
    public const ROLLUP_THROUGH_PREFIX = 'rollup_through_';

    /** JSON cursor so a long backfill can resume where it stopped. */
    public const ROLLUP_BACKFILL_CURSOR = 'rollup_backfill_cursor';

    /** Lease held while a rollup or backfill runs. */
    public const ROLLUP_LOCK = 'rollup_lock';

    public function __construct(private readonly Connection $connection)
    {
    }

    /** Null when the key was never written, or was written empty. */
    public function get(string $name): ?string
    {
        $value = $this->connection->fetchOne(
            'SELECT value FROM app_state WHERE name = :name',
            ['name' => $name],
        );

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $name, string $value, ?\DateTimeImmutable $now = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO app_state (name, value, updated_at) VALUES (:n, :v, :u)'
            .' ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [
                'n' => $name,
                'v' => $value,
                'u' => ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Convenience for the many keys that store an instant.
     *
     * Returns null for a missing key and for a value that no longer parses —
     * a corrupt watermark must read as "unknown" rather than blow up a cron job.
     */
    public function getInstant(string $name): ?\DateTimeImmutable
    {
        $raw = $this->get($name);
        if ($raw === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    public function setInstant(string $name, \DateTimeImmutable $value, ?\DateTimeImmutable $now = null): void
    {
        $this->set($name, $value->format(\DateTimeInterface::ATOM), $now);
    }

    /**
     * Take a lease, or report that someone else holds it.
     *
     * The conditional upsert is what makes this safe: SQLite evaluates the
     * WHERE on the conflicting row inside the same statement, so two callers
     * racing cannot both see it as free. The stored value is an expiry, so a
     * process killed mid-run does not leave the lease stuck forever.
     *
     * These jobs are idempotent by construction — every bucket is recomputed
     * and upserted — so the lease is about not doing the same expensive scan
     * twice and not piling writers onto a database where a writer blocks
     * readers. It is not load-bearing for correctness.
     */
    public function tryLock(string $name, \DateTimeImmutable $expiresAt, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        $affected = $this->connection->executeStatement(
            'INSERT INTO app_state (name, value, updated_at) VALUES (:n, :v, :u)'
            .' ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
            .' WHERE app_state.value < :now',
            [
                'n' => $name,
                // UTC, not ATOM: the comparison above is lexical, and ATOM
                // offsets change across a DST boundary ("+01:00" sorts before
                // "+02:00" while representing the later instant).
                'v' => self::utc($expiresAt),
                'u' => $now->format('Y-m-d H:i:s'),
                'now' => self::utc($now),
            ],
        );

        return $affected > 0;
    }

    public function releaseLock(string $name): void
    {
        $this->connection->executeStatement('DELETE FROM app_state WHERE name = :n', ['n' => $name]);
    }

    private static function utc(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
