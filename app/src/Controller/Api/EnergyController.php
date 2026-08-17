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

use App\Service\EnergyCostService;
use App\Service\StandbyService;
use App\Service\StatsRange;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Energy consumption expressed as money.
 *
 * Readable by any authenticated user — the `^/api` rule covers it. Only editing
 * the tariff is restricted, and that lives on SettingsController.
 */
#[Route('/api/energy')]
class EnergyController extends AbstractController
{
    public function __construct(
        private readonly EnergyCostService $energyCost,
        private readonly StandbyService $standbyService,
    ) {
    }

    /**
     * Per-device and household cost for a window, with its data coverage.
     *
     * Bounds are parsed through StatsRange so this answers questions about
     * exactly the same window the chart and the export do.
     */
    #[Route('/cost', methods: ['GET'])]
    public function cost(Request $request): JsonResponse
    {
        try {
            $from = StatsRange::parseBound($request->query->getString('from', ''), '-30 days', endOfDay: false);
            $to = StatsRange::parseBound($request->query->getString('to', ''), 'now', endOfDay: true);
        } catch (\Exception) {
            return $this->json(
                ['error' => 'from and to must be an ISO 8601 instant or a Y-m-d date'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->json($this->energyCost->costForRange($from, $to));
    }

    /**
     * Today, month-to-date and the top consumer.
     *
     * One request on purpose: the dashboard would otherwise need one per device.
     */
    #[Route('/summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return $this->json($this->energyCost->summary());
    }

    /**
     * One device's idle draw, for its detail page.
     *
     * 204 rather than an invented zero when there is not enough data to quote a
     * figure — a device with a day of history has no meaningful weekly floor.
     */
    #[Route('/standby/{ain}', methods: ['GET'])]
    public function standby(string $ain): JsonResponse
    {
        $estimate = $this->standbyService->forDevice($ain);

        return $estimate === null
            ? new JsonResponse(null, Response::HTTP_NO_CONTENT)
            : $this->json($estimate);
    }
}
