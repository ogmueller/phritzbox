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

use App\Repository\SmartDeviceDataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SmartDeviceDataRepository::class)]
class SmartDeviceData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $dataId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $sid;

    #[ORM\Column(type: 'string', length: 255)]
    private string $type;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $time;

    #[ORM\Column(type: 'float')]
    private float $value;

    public function getDataId(): ?int
    {
        return $this->dataId ?? null;
    }

    public function setDataId(int $dataId): self
    {
        $this->dataId = $dataId;

        return $this;
    }

    public function getSid(): ?string
    {
        return $this->sid ?? null;
    }

    public function setSid(string $sid): self
    {
        $this->sid = $sid;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type ?? null;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTime(): ?\DateTimeInterface
    {
        return $this->time ?? null;
    }

    public function setTime(\DateTimeInterface $time): self
    {
        $this->time = $time;

        return $this;
    }

    public function getValue(): ?float
    {
        return $this->value ?? null;
    }

    public function setValue(float $value): self
    {
        $this->value = $value;

        return $this;
    }
}
