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

use App\Repository\AlertEventRepository;
use App\Service\AlertEvaluationService;
use App\Service\MetricUnits;
use App\Service\SmartStatsCollectionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/stats')]
class StatsController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SmartStatsCollectionService $collectionService,
        private readonly AlertEvaluationService $alertEvaluation,
    ) {
    }

    /**
     * Force an on-demand pull of fresh stats from the Fritz!Box for all devices,
     * then evaluate alert rules against the freshly collected readings so a manual
     * pull reflects in alerts immediately instead of waiting for the cron.
     */
    #[Route('/refresh', methods: ['POST'], priority: 10)]
    public function refresh(): JsonResponse
    {
        try {
            $result = $this->collectionService->collectAll();
        } catch (\Throwable $e) {
            return $this->json(
                ['error' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        // Evaluation is independent of collection: the data is already persisted,
        // so an alert failure must not turn the successful pull into an error.
        $alerts = null;
        try {
            $alerts = $this->alertEvaluation->evaluateAll();
        } catch (\Throwable) {
            // evaluateAll already logs and isolates per-rule failures internally.
        }

        return $this->json([
            'status' => 'ok',
            'devices' => $result['devices'],
            'rows' => $result['rows'],
            'alerts' => $alerts,
        ]);
    }

    /**
     * Alert events of a metric within a date range, restricted to rules involving
     * the given devices — used to overlay markers on the Reports chart.
     * Available to any authenticated user (unlike the admin-only alert config).
     */
    #[Route('/alert-events', methods: ['GET'], priority: 10)]
    public function alertEvents(Request $request, AlertEventRepository $events): JsonResponse
    {
        $type = $request->query->getString('type');
        if (!MetricUnits::isValidType($type)) {
            return $this->json(['error' => 'type must be one of: '.implode(', ', MetricUnits::TYPES)], Response::HTTP_BAD_REQUEST);
        }

        $devices = array_values(array_filter(
            array_map(strval(...), $request->query->all('devices')),
            static fn (string $d): bool => $d !== '',
        ));
        if ($devices === []) {
            return $this->json([]);
        }

        try {
            $from = $this->parseBound($request->query->getString('from'), '-24 hours', endOfDay: false)->format('Y-m-d H:i:s');
            $to = $this->parseBound($request->query->getString('to'), 'now', endOfDay: true)->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $this->json(['error' => 'from and to must be an ISO 8601 instant or a Y-m-d date'], Response::HTTP_BAD_REQUEST);
        }

        $data = array_map(static fn (array $e): array => [
            'ruleName' => $e['ruleName'],
            'state' => $e['state'],
            'sid' => $e['sid'],
            'compareSid' => $e['compareSid'],
            'valueDisplay' => $e['valueDisplay'],
            'compareDisplay' => $e['compareDisplay'],
            'createdAt' => (new \DateTimeImmutable($e['createdAt']))->format(\DateTimeInterface::ATOM),
        ], $events->findForReport($type, $from, $to, $devices));

        return $this->json($data);
    }

    #[Route('/{ain}', methods: ['GET'])]
    public function show(string $ain, Request $request): JsonResponse
    {
        $type = $request->query->getString('type', '');

        try {
            $from = $this->parseBound($request->query->getString('from', ''), '-24 hours', endOfDay: false);
            $to = $this->parseBound($request->query->getString('to', ''), 'now', endOfDay: true);
        } catch (\Exception) {
            return $this->json(['error' => 'from and to must be an ISO 8601 instant or a Y-m-d date'], Response::HTTP_BAD_REQUEST);
        }

        // The box reports energy once per day (grid 86400) and stamps each value
        // at midnight, so a window that starts mid-day can only ever clip a bar
        // off the left edge — never refine one. Widen the start to that midnight.
        // Only for an explicit energy request: on the all-types query ($type ===
        // '') this would drag in extra temperature and power rows.
        if ($type === 'energy') {
            $from = $from->setTime(0, 0);
        }

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

        $conn = $this->connection;
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
            // Collapse any duplicate (time, type) rows so each timestamp yields a
            // single point — otherwise the chart tooltip lists the value twice.
            // Duplicates hold the same value, so AVG is a no-op on clean data.
            $sql = 'SELECT time, AVG(value) AS value, type'
                .' FROM smart_device_data'
                .' WHERE sid = :ain AND time >= :from AND time <= :to';
            if ($type !== '') {
                $sql .= ' AND type = :type';
                $params['type'] = $type;
            }
            $sql .= ' GROUP BY time, type ORDER BY time ASC';
        }

        $rows = $conn->fetchAllAssociative($sql, $params);

        // The DB stores raw Fritz!Box units; MetricUnits owns the conversion so
        // adding a metric type does not mean hunting down a second divisor table.
        $data = array_map(static fn (array $r): array => [
            'time' => (new \DateTimeImmutable($r['time']))->format(\DateTimeInterface::ATOM),
            'value' => MetricUnits::toDisplay($r['type'], (float) $r['value']),
            'type' => $r['type'],
        ], $rows);

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

    /**
     * Parse a `from`/`to` query bound.
     *
     * Accepts an offset-bearing ISO 8601 instant — what the UI sends, so a
     * rolling window means the same moment regardless of where browser and
     * server sit — or a bare `Y-m-d`, kept for older clients and hand-made API
     * calls. A bare date carries no time, so an end bound is widened to that
     * day's last second (the long-standing behaviour, now applied only when the
     * value really is a bare date).
     *
     * The result is converted to the server timezone because readings are
     * stored formatted in it; without this an incoming +02:00 instant would
     * format two hours off and silently shift the window.
     *
     * @throws \Exception when the value cannot be parsed
     */
    private function parseBound(string $raw, string $fallback, bool $endOfDay): \DateTimeImmutable
    {
        $tz = new \DateTimeZone(date_default_timezone_get());

        if ($raw === '') {
            return new \DateTimeImmutable($fallback, $tz);
        }

        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw .= ' 23:59:59';
        }

        return (new \DateTimeImmutable($raw))->setTimezone($tz);
    }

    #[Route('/types/{ain}', methods: ['GET'])]
    public function types(string $ain): JsonResponse
    {
        $conn = $this->connection;
        $types = $conn->fetchFirstColumn(
            'SELECT DISTINCT type FROM smart_device_data WHERE sid = :ain ORDER BY type',
            ['ain' => $ain]
        );

        return $this->json(['ain' => $ain, 'types' => $types]);
    }
}
