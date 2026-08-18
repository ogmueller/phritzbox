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
        // Clearing the tariff must be deliberate. A blank value says so; a
        // missing key says nothing, and treating it as "clear" means any caller
        // that drops the field silently deletes a configured price and is told
        // it succeeded.
        if (!\array_key_exists('pricePerKwh', $body)) {
            return 'pricePerKwh is required — send an empty string to clear the tariff';
        }

        $rawPrice = self::normalizeDecimal($body['pricePerKwh']);

        // Null or blank clears the tariff back to "not configured" — which is a
        // real state, not an error.
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
        $rawStanding = self::normalizeDecimal($body['standingChargeMonth'] ?? 0);
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

    /**
     * A German-typed amount, turned into something is_numeric() accepts.
     *
     * "0,35" is what a German keyboard produces and what the price field's own
     * hint asks for ("z. B. 0,35 für 35 Cent"), but is_numeric() rejects it — so
     * the tariff was refused for a reason the operator could not see, and the
     * field kept looking unset. Parsing belongs here rather than in the browser:
     * the endpoint is the only thing that decides what a valid tariff is, and it
     * has more than one client.
     *
     * The last separator in the string is the decimal one, which resolves
     * "1.234,56" and "1,234.56" the same way a reader would. Anything that is
     * not a string is passed through untouched for is_numeric() to judge.
     */
    private static function normalizeDecimal(mixed $raw): mixed
    {
        if (!\is_string($raw)) {
            return $raw;
        }

        $value = mb_trim($raw);
        $comma = mb_strrpos($value, ',');
        if ($comma === false) {
            return $value;
        }

        $dot = mb_strrpos($value, '.');

        return $dot === false || $comma > $dot
            // Comma decides; any dots left are thousands grouping.
            ? str_replace(',', '.', str_replace('.', '', $value))
            // Dot decides; the commas are thousands grouping.
            : str_replace(',', '', $value);
    }
}
