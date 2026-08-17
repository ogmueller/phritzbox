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

use App\Entity\Tariff;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the electricity tariff.
 *
 * The load-bearing contract is that `pricePerKwh()` returns `?float` and `null`
 * means **not configured** — never conflated with `0.0`, which somebody
 * generating their own power may legitimately set. Every cost in the application
 * is nullable for the same reason: a household that has not entered a price must
 * see "—", not a confident "0.00 €".
 *
 * Formatting is not this class's job. It returns numbers and an ISO 4217 code,
 * the same way MetricUnits returns numbers and a unit string — the browser knows
 * the locale, the server does not.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class TariffSettings
{
    public const DEFAULT_CURRENCY = 'EUR';

    /**
     * Roughly thirty times any European retail price. Above this the operator
     * has almost certainly typed cents instead of units.
     */
    public const MAX_PRICE_PER_KWH = 10.0;

    public const MAX_STANDING_CHARGE_MONTH = 1000.0;

    /**
     * ISO 4217 codes only.
     *
     * An allowlist rather than free text because the browser formats with
     * Intl.NumberFormat, which throws RangeError on an unknown code — that would
     * blank the page rather than merely look wrong.
     */
    public const CURRENCIES = ['EUR', 'CHF', 'GBP', 'USD', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** Null when no price has been configured — see the class docblock. */
    public function pricePerKwh(): ?float
    {
        return $this->tariff()?->getPricePerKwh();
    }

    public function standingChargePerMonth(): float
    {
        return $this->tariff()?->getStandingChargeMonth() ?? 0.0;
    }

    public function currency(): string
    {
        return $this->tariff()?->getCurrency() ?? self::DEFAULT_CURRENCY;
    }

    public function isConfigured(): bool
    {
        return $this->pricePerKwh() !== null;
    }

    /**
     * @param float|null $pricePerKwh null clears the tariff back to unconfigured
     */
    public function save(?float $pricePerKwh, float $standingChargeMonth, string $currency): void
    {
        $tariff = $this->tariff() ?? new Tariff();
        $tariff->setPricePerKwh($pricePerKwh)
            ->setStandingChargeMonth($standingChargeMonth)
            ->setCurrency($currency)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($tariff);
        $this->entityManager->flush();
    }

    /**
     * @return array{pricePerKwh: float|null, standingChargeMonth: float, currency: string, configured: bool}
     */
    public function toArray(): array
    {
        return [
            'pricePerKwh' => $this->pricePerKwh(),
            'standingChargeMonth' => $this->standingChargePerMonth(),
            'currency' => $this->currency(),
            'configured' => $this->isConfigured(),
        ];
    }

    private function tariff(): ?Tariff
    {
        return $this->entityManager->find(Tariff::class, Tariff::SINGLETON_ID);
    }
}
