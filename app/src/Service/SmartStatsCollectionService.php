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
use App\Entity\SmartDeviceData;
use Doctrine\DBAL\ArrayParameterType;
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

        // Pre-fetch last stored timestamp for every (sid, type). A single
        // GROUP BY sid, type over the whole table forces a full scan of all
        // rows (~52M); instead, derive the distinct (sid, type) pairs and let
        // the (sid, type, time) index do a per-pair reverse-seek for MAX(time).
        // This turns a multi-second scan into a handful of millisecond seeks.
        $ains = array_map(static fn (Device $d) => $d->getIdentifier(), $devices);
        $lastRaw = $ains === [] ? [] : $this->entityManager->getConnection()->executeQuery(
            'SELECT p.sid AS sid, p.type AS type,'
            .' (SELECT MAX(d.time) FROM smart_device_data d WHERE d.sid = p.sid AND d.type = p.type) AS last'
            .' FROM (SELECT DISTINCT sid, type FROM smart_device_data WHERE sid IN (:sids)) p',
            ['sids' => $ains],
            ['sids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        // Build lookup: $lastSeen[$sid][$type] = 'Y-m-d H:i:s'
        $lastSeen = [];
        foreach ($lastRaw as $row) {
            $lastSeen[$row['sid']][$row['type']] = $row['last'];
        }

        // Fetch every device's stats concurrently (bounded) instead of one
        // blocking request after another — this is the bulk of the run time.
        $statsByAin = $this->ahaApi->getBasicDeviceStatsBatch($ains, self::FETCH_CONCURRENCY);

        $perDevice = [];
        $totalRows = 0;

        /** @var Device $device */
        foreach ($devices as $device) {
            $ain = $device->getIdentifier();

            $stats = $statsByAin[$ain] ?? [];

            $deviceCount = 0;
            $last = $lastSeen[$ain] ?? [];

            foreach ($stats as $category => $statsList) {
                if (\count($statsList) > 1) {
                    // find values with shortest interval
                    $tmp = 0;
                    foreach ($statsList as $key => $data) {
                        if (empty($tmp) || $data['interval'] < $tmp) {
                            $tmp = $data['interval'];
                            $index = $key;
                        }
                    }
                } else {
                    $index = 0;
                }
                $data = $statsList[$index];
                $intervalSeconds = $data['interval'];

                // calculate current interval starting point
                // go to the beginning of the last full time slot
                $seconds = (int) $now->format('U');
                $back = $intervalSeconds + $seconds % $intervalSeconds;
                $end = $now->modify('-'.$back.' seconds');
                $start = $end->modify('-'.($intervalSeconds * ($data['count'] - 1)).' seconds');

                $step = new \DateInterval('PT'.$intervalSeconds.'S');

                $sdd = new SmartDeviceData();
                $sdd->setType($category);
                $sdd->setSid($ain);

                $count = 0;
                foreach (array_reverse($data['values']) as $value) {
                    // only save newer data points
                    if (empty($last[$category]) || $start->format('Y-m-d H:i:s') > $last[$category]) {
                        $insert = clone $sdd;
                        $insert->setTime($start);
                        $insert->setValue($value);
                        $this->entityManager->persist($insert);
                        ++$count;
                    }

                    // next interval
                    $start = $start->add($step);
                }
                $deviceCount += $count;
            }

            $perDevice[$ain] = ['name' => $device->getName(), 'rows' => $deviceCount];
            $totalRows += $deviceCount;
        }

        // Single flush wraps all inserts in one SQLite transaction
        $this->entityManager->flush();

        return [
            'devices' => \count($devices),
            'rows' => $totalRows,
            'perDevice' => $perDevice,
        ];
    }
}
