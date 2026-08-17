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

use App\Service\TariffSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Application settings. Currently just the electricity tariff.
 *
 * Reading is open to any authenticated user because every cost figure in the UI
 * needs the price and currency to render; only writing is restricted. That split
 * is by HTTP method, so it lives on the method as an attribute rather than in
 * security.yaml — the same choice DeviceController::setProtection already makes.
 * Path-based rules would have to be ordered above the `^/api` catch-all, which is
 * exactly the trap that once locked non-admins out of changing their own password.
 */
#[Route('/api/settings')]
class SettingsController extends AbstractController
{
    public function __construct(private readonly TariffSettings $tariff)
    {
    }

    #[Route('/tariff', methods: ['GET'])]
    public function showTariff(): JsonResponse
    {
        return $this->json($this->tariff->toArray());
    }

    #[Route('/tariff', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function updateTariff(Request $request): JsonResponse
    {
        $body = (array) json_decode($request->getContent(), true);

        $error = $this->applyPayload($body);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->tariff->toArray());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return string|null an error message, or null when the payload was applied
     */
    private function applyPayload(array $body): ?string
    {
        $rawPrice = $body['pricePerKwh'] ?? null;

        // Absent, null or blank clears the tariff back to "not configured" —
        // which is a real state, not an error.
        if ($rawPrice === null || $rawPrice === '') {
            $price = null;
        } elseif (!is_numeric($rawPrice)) {
            return 'pricePerKwh must be a number';
        } else {
            $price = (float) $rawPrice;
            if ($price < 0) {
                return 'pricePerKwh must not be negative';
            }
            if ($price > TariffSettings::MAX_PRICE_PER_KWH) {
                return \sprintf(
                    'pricePerKwh must be at most %s — that is a price per kWh, not per MWh or in cents',
                    TariffSettings::MAX_PRICE_PER_KWH,
                );
            }
        }

        // ?? already covers both an absent key and an explicit null; a blank
        // string is what an emptied form field sends.
        $rawStanding = $body['standingChargeMonth'] ?? 0;
        if ($rawStanding === '') {
            $rawStanding = 0;
        }
        if (!is_numeric($rawStanding)) {
            return 'standingChargeMonth must be a number';
        }
        $standing = (float) $rawStanding;
        if ($standing < 0) {
            return 'standingChargeMonth must not be negative';
        }
        if ($standing > TariffSettings::MAX_STANDING_CHARGE_MONTH) {
            return \sprintf('standingChargeMonth must be at most %s (per month)', TariffSettings::MAX_STANDING_CHARGE_MONTH);
        }

        $currency = mb_strtoupper(mb_trim((string) ($body['currency'] ?? TariffSettings::DEFAULT_CURRENCY)));
        if ($currency === '') {
            $currency = TariffSettings::DEFAULT_CURRENCY;
        }
        if (!\in_array($currency, TariffSettings::CURRENCIES, true)) {
            return 'currency must be one of: '.implode(', ', TariffSettings::CURRENCIES);
        }

        $this->tariff->save($price, $standing, $currency);

        return null;
    }
}
