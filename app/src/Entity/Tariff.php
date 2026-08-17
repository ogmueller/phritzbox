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

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The electricity tariff. A singleton — there is one household, one price.
 *
 * The id is fixed rather than generated, so a second row cannot appear through
 * an ordinary persist: the primary key itself enforces the singleton.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[ORM\Entity]
#[ORM\Table(name: 'tariff')]
class Tariff
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id = self::SINGLETON_ID;

    /**
     * Price of a kilowatt-hour, in the currency below.
     *
     * Null means **not configured**, which is deliberately distinct from 0.0 —
     * somebody generating their own power may legitimately set zero, and a cost
     * of "0.00 €" must never be shown for a household that simply has not
     * entered a price.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pricePerKwh = null;

    /**
     * Fixed charge per month, independent of consumption (a German bill's
     * Grundpreis). Per *month* because that is the period bills quote.
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $standingChargeMonth = 0.0;

    /** ISO 4217 code. Formatting happens in the browser; the server only stores it. */
    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPricePerKwh(): ?float
    {
        return $this->pricePerKwh;
    }

    public function setPricePerKwh(?float $pricePerKwh): self
    {
        $this->pricePerKwh = $pricePerKwh;

        return $this;
    }

    public function getStandingChargeMonth(): float
    {
        return $this->standingChargeMonth;
    }

    public function setStandingChargeMonth(float $standingChargeMonth): self
    {
        $this->standingChargeMonth = $standingChargeMonth;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
