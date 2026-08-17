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

use App\Service\DataLifecycle\AppState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/health')]
class HealthController extends AbstractController
{
    public function __construct(private readonly AppState $appState)
    {
    }

    /**
     * Reports how fresh the collected data is, so the UI can flag staleness
     * (e.g. when the host slept and scheduled collection was skipped).
     */
    #[Route('', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $iso = $this->appState->get(AppState::LAST_COLLECTION_AT);
        $ageMinutes = $iso !== null
            ? (int) floor(((new \DateTimeImmutable())->getTimestamp() - (new \DateTimeImmutable($iso))->getTimestamp()) / 60)
            : null;

        return $this->json([
            'lastCollectedAt' => $iso,
            'ageMinutes' => $ageMinutes,
            // Scheduled jobs come from cronado labels in the operator's compose
            // file, not from the image, so an install that predates a job never
            // runs it. Reporting these makes that visible instead of silent.
            'lastRollupAt' => $this->appState->get(AppState::LAST_ROLLUP_AT),
            'lastBackupAt' => $this->appState->get(AppState::LAST_BACKUP_AT),
        ]);
    }
}
