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
 * The household's comfort zone, which colours temperature charts: cold up to
 * `coldBelow`, warm from `warmFrom`, a blend in between. A singleton like Tariff —
 * the fixed id makes the primary key enforce it.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[ORM\Entity]
#[ORM\Table(name: 'comfort_zone')]
class ComfortZone
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    private int $id = self::SINGLETON_ID;

    /** °C below which a reading counts as cold. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $coldBelow;

    /** °C from which a reading counts as warm. Never below `coldBelow`. */
    #[ORM\Column(type: Types::FLOAT)]
    private float $warmFrom;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(float $coldBelow, float $warmFrom)
    {
        $this->coldBelow = $coldBelow;
        $this->warmFrom = $warmFrom;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getColdBelow(): float
    {
        return $this->coldBelow;
    }

    public function getWarmFrom(): float
    {
        return $this->warmFrom;
    }

    public function update(float $coldBelow, float $warmFrom): self
    {
        $this->coldBelow = $coldBelow;
        $this->warmFrom = $warmFrom;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
