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
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fetches stats for all available devices from the Fritz!Box and persists any
 * new data points. Shared by the cron command and the HTTP "force pull" endpoint.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartStatsCollectionService
{
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

        // Pre-fetch last stored timestamp for every (sid, type) in one query
        // instead of querying once per device inside the loop.
        $ains = array_map(static fn (Device $d) => $d->getIdentifier(), $devices);
        $lastRaw = $this->entityManager->createQueryBuilder()
            ->select('d.sid, d.type, max(d.time) as last')
            ->from(SmartDeviceData::class, 'd')
            ->where('d.sid IN (:sids)')
            ->addGroupBy('d.sid')
            ->addGroupBy('d.type')
            ->setParameter('sids', $ains)
            ->getQuery()
            ->getArrayResult();

        // Build lookup: $lastSeen[$sid][$type] = 'Y-m-d H:i:s'
        $lastSeen = [];
        foreach ($lastRaw as $row) {
            $lastSeen[$row['sid']][$row['type']] = $row['last'];
        }

        $perDevice = [];
        $totalRows = 0;

        /** @var Device $device */
        foreach ($devices as $device) {
            $ain = $device->getIdentifier();

            $stats = $this->ahaApi->getBasicDeviceStats($ain);

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
