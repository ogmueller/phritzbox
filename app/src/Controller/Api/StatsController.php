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

namespace App\Controller\Api;

use App\Repository\SmartDeviceDataRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/stats')]
class StatsController extends AbstractController
{
    // DB stores raw Fritz!Box values. These factors convert to display units.
    // voltage: mV → V (÷1000), power: cW → W (÷100), temperature/energy: already correct
    private const VALUE_DIVISORS = [
        'voltage' => 1000,
        'power' => 100,
    ];

    public function __construct(private readonly SmartDeviceDataRepository $repository)
    {
    }

    #[Route('/{ain}', methods: ['GET'])]
    public function show(string $ain, Request $request): JsonResponse
    {
        $type = $request->query->getString('type', '');
        $fromStr = $request->query->getString('from', '');
        $toStr = $request->query->getString('to', '');

        $from = $fromStr ? new \DateTimeImmutable($fromStr) : new \DateTimeImmutable('-24 hours');
        $to = $toStr ? new \DateTimeImmutable($toStr.' 23:59:59') : new \DateTimeImmutable();

        $diffDays = (int) $from->diff($to)->days;

        // For large ranges, aggregate at the database level to avoid loading
        // tens of thousands of rows into PHP memory.
        if ($diffDays > 30) {
            $groupExpr = "strftime('%Y-%m-%d', time)";
        } elseif ($diffDays > 2) {
            $groupExpr = "strftime('%Y-%m-%dT%H', time)";
        } else {
            $groupExpr = null;
        }

        $conn = $this->repository->getEntityManager()->getConnection();
        $params = [
            'ain' => $ain,
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
        ];

        if ($groupExpr !== null) {
            $sql = 'SELECT MIN(time) AS time, AVG(value) AS value, type'
                .' FROM smart_device_data'
                .' WHERE sid = :ain AND time >= :from AND time <= :to';
            if ($type !== '') {
                $sql .= ' AND type = :type';
                $params['type'] = $type;
            }
            $sql .= ' GROUP BY '.$groupExpr.', type ORDER BY MIN(time) ASC';
        } else {
            $sql = 'SELECT time, value, type'
                .' FROM smart_device_data'
                .' WHERE sid = :ain AND time >= :from AND time <= :to';
            if ($type !== '') {
                $sql .= ' AND type = :type';
                $params['type'] = $type;
            }
            $sql .= ' ORDER BY time ASC';
        }

        $rows = $conn->fetchAllAssociative($sql, $params);

        $data = array_map(static function (array $r) {
            $value = (float) $r['value'];
            $divisor = self::VALUE_DIVISORS[$r['type']] ?? null;
            if ($divisor !== null) {
                $value /= $divisor;
            }

            return [
                'time' => (new \DateTimeImmutable($r['time']))->format(\DateTimeInterface::ATOM),
                'value' => $value,
                'type' => $r['type'],
            ];
        }, $rows);

        // If energy is requested and the date range includes today, compute
        // today's partial energy from power readings (trapezoidal integration).
        if (($type === '' || $type === 'energy') && $to >= new \DateTimeImmutable('today')) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            $hasEnergyToday = false;
            foreach ($data as $row) {
                if ($row['type'] === 'energy' && str_starts_with($row['time'], $today)) {
                    $hasEnergyToday = true;
                    break;
                }
            }

            if (!$hasEnergyToday) {
                $todayStart = $today.' 00:00:00';
                $todayEnd = $today.' 23:59:59';
                $powerRows = $conn->fetchAllAssociative(
                    'SELECT time, value FROM smart_device_data'
                    .' WHERE sid = :ain AND type = :ptype AND time >= :from AND time <= :to'
                    .' ORDER BY time ASC',
                    ['ain' => $ain, 'ptype' => 'power', 'from' => $todayStart, 'to' => $todayEnd]
                );

                if (\count($powerRows) >= 2) {
                    // Integrate power (in centiwatts) over time to get energy in Wh
                    $energyWs = 0.0;
                    for ($i = 1, $n = \count($powerRows); $i < $n; ++$i) {
                        $t0 = (new \DateTimeImmutable($powerRows[$i - 1]['time']))->getTimestamp();
                        $t1 = (new \DateTimeImmutable($powerRows[$i]['time']))->getTimestamp();
                        $dt = $t1 - $t0;
                        // Average of two adjacent power readings (cW), convert to W
                        $avgW = ((float) $powerRows[$i - 1]['value'] + (float) $powerRows[$i]['value']) / 2.0 / 100.0;
                        $energyWs += $avgW * $dt;
                    }
                    $energyWh = round($energyWs / 3600.0, 1);

                    $data[] = [
                        'time' => (new \DateTimeImmutable($today))->format(\DateTimeInterface::ATOM),
                        'value' => $energyWh,
                        'type' => 'energy',
                    ];
                }
            }
        }

        return $this->json(['ain' => $ain, 'type' => $type, 'data' => $data]);
    }

    #[Route('/types/{ain}', methods: ['GET'])]
    public function types(string $ain): JsonResponse
    {
        $conn = $this->repository->getEntityManager()->getConnection();
        $types = $conn->fetchFirstColumn(
            'SELECT DISTINCT type FROM smart_device_data WHERE sid = :ain ORDER BY type',
            ['ain' => $ain]
        );

        return $this->json(['ain' => $ain, 'types' => $types]);
    }
}
