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

use App\Repository\SmartDeviceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SmartDeviceRepository::class)]
class SmartDevice
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $ain;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $manufacturer = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $productName = '';

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $firmwareVersion = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $functionBitMask = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(string $ain)
    {
        $this->ain = $ain;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getAin(): string
    {
        return $this->ain;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getManufacturer(): string
    {
        return $this->manufacturer;
    }

    public function setManufacturer(string $manufacturer): self
    {
        $this->manufacturer = $manufacturer;

        return $this;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): self
    {
        $this->productName = $productName;

        return $this;
    }

    public function getFirmwareVersion(): string
    {
        return $this->firmwareVersion;
    }

    public function setFirmwareVersion(string $firmwareVersion): self
    {
        $this->firmwareVersion = $firmwareVersion;

        return $this;
    }

    public function getFunctionBitMask(): int
    {
        return $this->functionBitMask;
    }

    public function setFunctionBitMask(int $functionBitMask): self
    {
        $this->functionBitMask = $functionBitMask;

        return $this;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): self
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): self
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }
}
