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

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/health')]
class HealthController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Reports how fresh the collected data is, so the UI can flag staleness
     * (e.g. when the host slept and scheduled collection was skipped).
     */
    #[Route('', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $last = $this->connection->fetchOne(
            "SELECT value FROM app_state WHERE name = 'last_collection_at'",
        );

        $iso = \is_string($last) && $last !== '' ? $last : null;
        $ageMinutes = $iso !== null
            ? (int) floor(((new \DateTimeImmutable())->getTimestamp() - (new \DateTimeImmutable($iso))->getTimestamp()) / 60)
            : null;

        return $this->json([
            'lastCollectedAt' => $iso,
            'ageMinutes' => $ageMinutes,
        ]);
    }
}
