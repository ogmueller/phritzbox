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

use App\Entity\ComfortZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the comfort zone that colours temperature charts.
 *
 * Unlike the tariff there is no "not configured" state: without a stored row the
 * defaults apply, so every chart can colour itself from the first install on.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class ComfortZoneSettings
{
    public const DEFAULT_COLD_BELOW = 16.0;
    public const DEFAULT_WARM_FROM = 20.0;

    /** The range a Fritz!DECT sensor can plausibly report, with headroom. */
    public const MIN_TEMPERATURE = -30.0;
    public const MAX_TEMPERATURE = 40.0;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function coldBelow(): float
    {
        return $this->zone()?->getColdBelow() ?? self::DEFAULT_COLD_BELOW;
    }

    public function warmFrom(): float
    {
        return $this->zone()?->getWarmFrom() ?? self::DEFAULT_WARM_FROM;
    }

    /**
     * Callers validate first; this only stores. See SettingsController.
     */
    public function save(float $coldBelow, float $warmFrom): void
    {
        $zone = $this->zone();
        if ($zone === null) {
            $zone = new ComfortZone($coldBelow, $warmFrom);
        } else {
            $zone->update($coldBelow, $warmFrom);
        }

        $this->entityManager->persist($zone);
        $this->entityManager->flush();
    }

    /**
     * @return array{coldBelow: float, warmFrom: float}
     */
    public function toArray(): array
    {
        return [
            'coldBelow' => $this->coldBelow(),
            'warmFrom' => $this->warmFrom(),
        ];
    }

    private function zone(): ?ComfortZone
    {
        return $this->entityManager->find(ComfortZone::class, ComfortZone::SINGLETON_ID);
    }
}
