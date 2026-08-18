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
use App\Service\StatsQueryService;
use App\Service\StatsRange;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
        private readonly StatsQueryService $statsQuery,
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

    /**
     * The same readings as show(), as a downloadable file.
     *
     * A separate route rather than a `format` parameter on show(), which is
     * typed JsonResponse. Volume is bounded by the same resolution ladder, so
     * even a multi-year export is a few thousand rows.
     */
    #[Route('/{ain}/export', methods: ['GET'])]
    public function export(string $ain, Request $request): Response
    {
        $type = $request->query->getString('type', '');
        $format = mb_strtolower($request->query->getString('format', 'csv'));
        if (!\in_array($format, ['csv', 'json'], true)) {
            return $this->json(['error' => 'format must be csv or json'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $from = $this->parseBound($request->query->getString('from', ''), '-24 hours', endOfDay: false);
            $to = $this->parseBound($request->query->getString('to', ''), 'now', endOfDay: true);
        } catch (\Exception) {
            return $this->json(['error' => 'from and to must be an ISO 8601 instant or a Y-m-d date'], Response::HTTP_BAD_REQUEST);
        }

        // Same widening as show(), so an energy export covers exactly the days
        // the chart shows. Without it a mid-day `from` clipped the first day
        // here but not there.
        $from = StatsRange::widenForEnergy($from, $type);

        $resolution = $this->statsQuery->resolutionFor($from, $to);
        $data = $this->statsQuery->fetch($ain, $type, $from, $to, $resolution);

        $filename = \sprintf(
            'phritzbox-%s-%s-%s_%s.%s',
            preg_replace('/[^A-Za-z0-9]+/', '', $ain) ?? 'device',
            $type !== '' ? $type : 'all',
            $from->format('Ymd'),
            $to->format('Ymd'),
            $format,
        );

        if ($format === 'json') {
            $response = new JsonResponse([
                'ain' => $ain,
                'type' => $type,
                'resolution' => $resolution,
                'data' => $data,
            ]);
            $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ));

            return $response;
        }

        // Excel in a German locale reads ';' as the separator and ',' as the
        // decimal mark, so the UI asks for ';' when the interface is German.
        $delimiter = $request->query->getString('delimiter', ',');
        $delimiter = $delimiter === '' ? ',' : mb_substr($delimiter, 0, 1);
        $aggregated = $resolution !== StatsQueryService::RESOLUTION_RAW;

        // Built in memory rather than streamed: the resolution ladder caps a
        // request at daily buckets beyond 30 days, so even a decade of every
        // metric is a few thousand rows. Streaming would buy nothing and makes
        // the response harder to test.
        $header = ['time', 'type', 'value', 'unit'];
        if ($aggregated) {
            $header[] = 'min';
            $header[] = 'max';
        }

        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new \RuntimeException('Could not open an in-memory buffer for the CSV export');
        }

        fputcsv($buffer, $header, $delimiter, '"', '');
        foreach ($data as $point) {
            $row = [$point['time'], $point['type'], $point['value'], MetricUnits::unit($point['type'])];
            if ($aggregated) {
                $row[] = $point['min'] ?? '';
                $row[] = $point['max'] ?? '';
            }
            fputcsv($buffer, $row, $delimiter, '"', '');
        }
        rewind($buffer);
        $csv = (string) stream_get_contents($buffer);
        fclose($buffer);

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
        ));

        return $response;
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

        $from = StatsRange::widenForEnergy($from, $type);

        $now = new \DateTimeImmutable();
        $resolution = $this->statsQuery->resolutionFor($from, $to, $now);
        $data = $this->statsQuery->fetch($ain, $type, $from, $to, $resolution);

        // If energy is requested and the range includes today, fill in the day
        // the box has not yet reported.
        if (($type === '' || $type === MetricUnits::TYPE_ENERGY) && $to >= $now->setTime(0, 0)) {
            $today = $now->format('Y-m-d');
            $hasEnergyToday = false;
            foreach ($data as $row) {
                if ($row['type'] === MetricUnits::TYPE_ENERGY && str_starts_with($row['time'], $today)) {
                    $hasEnergyToday = true;
                    break;
                }
            }

            if (!$hasEnergyToday) {
                $synthetic = $this->statsQuery->synthesiseTodayEnergy($ain, $now);
                if ($synthetic !== null) {
                    $data[] = $synthetic;
                }
            }
        }

        return $this->json([
            'ain' => $ain,
            'type' => $type,
            // Which tier answered this, so a client can say whether it is
            // looking at readings or at summaries.
            'resolution' => $resolution,
            'data' => $data,
        ]);
    }

    /**
     * @see StatsRange::parseBound() — shared so every endpoint that answers
     *      questions about a window resolves it identically
     *
     * @throws \Exception when the value cannot be parsed
     */
    private function parseBound(string $raw, string $fallback, bool $endOfDay): \DateTimeImmutable
    {
        return StatsRange::parseBound($raw, $fallback, $endOfDay);
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
