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
 * Opens a backup snapshot and reports what is actually inside it.
 *
 * A backup nobody has opened is a hypothesis. This turns the manual "gunzip,
 * quick_check, count the rows, look at the date span" routine into something
 * that can be run on a schedule — so a snapshot is known to be restorable
 * before the day it has to be, not during it.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SnapshotInspector
{
    /**
     * Decompress `$path` into `$workDir` if needed, returning the path of a
     * plain SQLite file.
     *
     * For a restore, `$workDir` must sit on the same filesystem as the live
     * database so the final move can be atomic; for a read-only inspection any
     * scratch directory will do.
     *
     * @throws \RuntimeException when the file is missing or cannot be read
     *
     * @return array{path: string, temporary: bool} `temporary` marks a file this
     *                                              call created and the caller
     *                                              should clean up
     */
    public function materialize(string $path, string $workDir): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Snapshot "%s" does not exist', $path));
        }

        if (!str_ends_with($path, '.gz')) {
            return ['path' => $path, 'temporary' => false];
        }

        if (!is_dir($workDir) && !@mkdir($workDir, 0o775, true) && !is_dir($workDir)) {
            throw new \RuntimeException(\sprintf('Work directory "%s" could not be created', $workDir));
        }

        $target = mb_rtrim($workDir, '/').'/.restore-'.bin2hex(random_bytes(6)).'.sqlite';

        $in = gzopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException(\sprintf('Cannot open "%s"', $path));
        }
        $out = fopen($target, 'w');
        if ($out === false) {
            gzclose($in);
            throw new \RuntimeException(\sprintf('Cannot write "%s"', $target));
        }

        // Streamed: a multi-GB snapshot must never be held in memory.
        while (!gzeof($in)) {
            $chunk = gzread($in, 4 * 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);

        return ['path' => $target, 'temporary' => true];
    }

    /**
     * Read a plain SQLite file and describe it.
     *
     * Never throws on a bad snapshot — a corrupt file is a *result*, reported as
     * ok=false, because "this backup is unusable" is exactly what the caller
     * asked to find out.
     *
     * @return array{
     *     ok: bool,
     *     error: string|null,
     *     rows: int,
     *     devices: int,
     *     oldest: string|null,
     *     newest: string|null,
     *     migration: string|null,
     *     types: array<string, int>
     * }
     */
    public function inspect(string $plainPath): array
    {
        $result = [
            'ok' => false,
            'error' => null,
            'rows' => 0,
            'devices' => 0,
            'oldest' => null,
            'newest' => null,
            'migration' => null,
            'types' => [],
        ];

        try {
            $pdo = new \PDO('sqlite:'.$plainPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

            // @phpstan-ignore method.nonObject (ERRMODE_EXCEPTION: never false)
            $check = $pdo->query('PRAGMA quick_check')->fetchColumn();
            if ($check !== 'ok') {
                $result['error'] = \sprintf('integrity check failed: %s', (string) $check);

                return $result;
            }

            // A structurally valid SQLite file that is not a Phritzbox database
            // is just as useless as a corrupt one, so check the schema too.
            $hasTable = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='smart_device_data'"
            )->fetchColumn(); // @phpstan-ignore method.nonObject (ERRMODE_EXCEPTION: never false)
            if ((int) $hasTable === 0) {
                $result['error'] = 'not a Phritzbox database (smart_device_data is missing)';

                return $result;
            }

            $row = $pdo->query('SELECT COUNT(*) AS c, MIN(time) AS mn, MAX(time) AS mx FROM smart_device_data')
                ->fetch(\PDO::FETCH_ASSOC); // @phpstan-ignore method.nonObject (ERRMODE_EXCEPTION: never false)
            $result['rows'] = (int) ($row['c'] ?? 0);
            $result['oldest'] = $row['mn'] !== null ? (string) $row['mn'] : null;
            $result['newest'] = $row['mx'] !== null ? (string) $row['mx'] : null;

            // @phpstan-ignore foreach.nonIterable (ERRMODE_EXCEPTION: never false)
            foreach ($pdo->query('SELECT type, COUNT(*) AS c FROM smart_device_data GROUP BY type ORDER BY type') as $r) {
                $result['types'][(string) $r['type']] = (int) $r['c'];
            }

            // @phpstan-ignore method.nonObject (ERRMODE_EXCEPTION: never false)
            $result['devices'] = (int) $pdo->query('SELECT COUNT(*) FROM smart_device')->fetchColumn();

            // Which migration the snapshot was taken at. Restoring a snapshot
            // from a NEWER release onto older code is the dangerous direction,
            // and this is what makes that visible.
            $result['migration'] = (string) $pdo->query(
                'SELECT MAX(version) FROM doctrine_migration_versions'
            )->fetchColumn() ?: null; // @phpstan-ignore method.nonObject (ERRMODE_EXCEPTION: never false)

            $result['ok'] = true;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
