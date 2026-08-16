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

namespace App\Service;

use App\Client\AhaApi;
use App\Device;
use App\Service\DataLifecycle\AppState;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fetches stats for all available devices from the Fritz!Box and persists any
 * new data points. Shared by the cron command and the HTTP "force pull" endpoint.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartStatsCollectionService
{
    /**
     * How many device-stats requests to run in parallel against the Fritz!Box.
     * Kept small on purpose — the Fritz!Box is a constrained device and does
     * not cope well with many simultaneous requests.
     */
    private const FETCH_CONCURRENCY = 4;

    public function __construct(
        private readonly AhaApi $ahaApi,
        private readonly EntityManagerInterface $entityManager,
        private readonly SmartDeviceService $smartDeviceService,
        private readonly AppState $appState,
    ) {
    }

    /**
     * Collect and persist stats for every available device.
     *
     * @return array{
     *     devices: int,
     *     rows: int,
     *     perDevice: array<string, array{name: string, rows: int}>
     * }
     */
    public function collectAll(): array
    {
        $devices = $this->ahaApi->getDeviceListInfos();

        // Sync device metadata to local smart_device table
        $this->smartDeviceService->syncDevices($devices);

        $now = new \DateTimeImmutable();

        $ains = array_map(static fn (Device $d) => $d->getIdentifier(), $devices);

        // Fetch every device's stats concurrently (bounded) instead of one
        // blocking request after another — this is the bulk of the run time.
        $statsByAin = $this->ahaApi->getBasicDeviceStatsBatch($ains, self::FETCH_CONCURRENCY);

        // Pre-fetch the last stored timestamp for exactly the (sid, type) pairs we
        // just pulled fresh stats for. Each lookup is a single reverse-seek on the
        // (sid, type, time) index — microseconds. We deliberately do NOT derive the
        // pairs with SELECT DISTINCT over smart_device_data: on a large table (~52M
        // rows) SQLite can't satisfy DISTINCT from the index and falls back to a full
        // scan that can blow past the request time limit (the manual-pull 500 error).
        $conn = $this->entityManager->getConnection();
        $lastSeen = [];
        foreach ($statsByAin as $sid => $stats) {
            foreach ($stats as $type => $_) {
                $last = $conn->fetchOne(
                    'SELECT MAX(time) FROM smart_device_data WHERE sid = ? AND type = ?',
                    [$sid, $type],
                );
                if (\is_string($last) && $last !== '') {
                    $lastSeen[$sid][$type] = $last;
                }
            }
        }

        $perDevice = [];
        $pending = [];

        /** @var Device $device */
        foreach ($devices as $device) {
            $ain = $device->getIdentifier();

            $stats = $statsByAin[$ain] ?? [];

            $deviceCount = 0;
            $last = $lastSeen[$ain] ?? [];

            foreach ($stats as $category => $statsList) {
                $index = 0;
                if (\count($statsList) > 1) {
                    // find values with shortest interval
                    $tmp = 0;
                    foreach ($statsList as $key => $data) {
                        if (empty($tmp) || $data['interval'] < $tmp) {
                            $tmp = $data['interval'];
                            $index = $key;
                        }
                    }
                }
                $data = $statsList[$index];
                $intervalSeconds = $data['interval'];

                // Timestamp of the NEWEST value in the series.
                // Prefer the box-supplied `datatime` (its own clock for the most
                // recent sample): it captures the freshest completed slot and is
                // immune to server/box clock and timezone drift. Reconstructing
                // from the server clock instead lags a full grid interval behind
                // — e.g. it stores 00:00 for a 15-min series pulled at 00:16,
                // hiding the 00:15 sample the box already has. Fall back to the
                // server clock only when the box omits datatime (older FRITZ!OS).
                if (!empty($data['datatime'])) {
                    $endTs = $data['datatime'] - ($data['datatime'] % $intervalSeconds);
                    $end = $now->setTimestamp($endTs);
                } else {
                    // go to the beginning of the last full time slot
                    $seconds = (int) $now->format('U');
                    $back = $intervalSeconds + $seconds % $intervalSeconds;
                    $end = $now->modify('-'.$back.' seconds');
                }
                $start = $end->modify('-'.($intervalSeconds * ($data['count'] - 1)).' seconds');

                $step = new \DateInterval('PT'.$intervalSeconds.'S');

                $count = 0;
                foreach (array_reverse($data['values']) as $value) {
                    // only save newer data points
                    if (empty($last[$category]) || $start->format('Y-m-d H:i:s') > $last[$category]) {
                        $pending[] = [$ain, $category, $start->format('Y-m-d H:i:s'), $value];
                        ++$count;
                    }

                    // next interval
                    $start = $start->add($step);
                }
                $deviceCount += $count;
            }

            $perDevice[$ain] = ['name' => $device->getName(), 'rows' => $deviceCount];
        }

        // Persist via INSERT OR IGNORE so overlapping runs (e.g. cron racing a
        // manual pull) cannot create duplicate (sid, type, time) rows — the UNIQUE
        // index backstops the in-PHP "only newer" guard. One shared transaction.
        $inserted = 0;
        if ($pending !== []) {
            $conn = $this->entityManager->getConnection();
            $conn->beginTransaction();
            try {
                foreach ($pending as $row) {
                    $inserted += (int) $conn->executeStatement(
                        'INSERT OR IGNORE INTO smart_device_data (sid, type, time, value) VALUES (?, ?, ?, ?)',
                        $row,
                    );
                }
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();

                throw $e;
            }
        }

        // Record that a collection ran (even if it added no new rows) so the UI
        // can detect stale data when scheduled collection is missed.
        $this->appState->setInstant(AppState::LAST_COLLECTION_AT, $now, $now);

        return [
            'devices' => \count($devices),
            'rows' => $inserted,
            'perDevice' => $perDevice,
        ];
    }
}
