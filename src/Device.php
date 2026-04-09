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

namespace App;

use App\Device\Feature;

class Device
{
    public const FUNCTION_BIT_HANFUN_DEVICE = 1 << 0;

    public const FUNCTION_BIT_ALARM = 1 << 4;

    public const FUNCTION_BIT_THERMOSTAT = 1 << 6;

    public const FUNCTION_BIT_POWER_METER = 1 << 7;

    public const FUNCTION_BIT_TEMPERATURE_SENSOR = 1 << 8;

    public const FUNCTION_BIT_OUTLET = 1 << 9;

    public const FUNCTION_BIT_DECT_REPEATER = 1 << 10;

    public const FUNCTION_BIT_MICROFON = 1 << 11;

    public const FUNCTION_BIT_HANFUN_UNIT = 1 << 13;

    public const FEATURE_ALARM = 'alarm';

    public const FEATURE_POWER_METER = 'power';

    public const FEATURE_TEMPERATURE_SENSOR = 'temp';

    public const FEATURE_OUTLET = 'outlet';

    protected static array $mapping = [];

    protected bool $present = false;

    protected string $name;

    protected string $identifier;

    protected string $id;

    protected int $functionBitMask;

    protected string $firmwareVersion;

    protected string $manufacturer;

    protected string $productName;

    /** @var Feature[] */
    protected array $featureList = [];

    public function feature(string $name): ?Feature
    {
        return $this->featureList[$name] ?? null;
    }

    /**
     * Return all important values in array.
     */
    public function toArray(): array
    {
        $array = [
            'firmwareVersion' => $this->getFirmwareVersion(),
            'functionBitMask' => $this->getFunctionBitMask(),
            'id' => $this->getId(),
            'identifier' => $this->getIdentifier(),
            'manufacturer' => $this->getManufacturer(),
            'name' => $this->getName(),
            'present' => $this->isPresent(),
            'productName' => $this->getProductName(),
        ];

        foreach ($this->featureList as $feature) {
            $array += $feature->toArray();
        }

        return $array;
    }

    /**
     * Setup device using fritzbox XML response.
     */
    public function setXml(\SimpleXMLElement $xml): void
    {
        if ($attributes = $xml->attributes()) {
            if (isset($attributes['identifier'])) {
                $this->setIdentifier((string) $attributes['identifier']);
            }
            if (isset($attributes['id'])) {
                $this->setId((string) $attributes['id']);
            }
            if (isset($attributes['functionbitmask'])) {
                $this->setFunctionBitMask((int) $attributes['functionbitmask']);
            }
            if (isset($attributes['fwversion'])) {
                $this->setFirmwareVersion((string) $attributes['fwversion']);
            }
            if (isset($attributes['manufacturer'])) {
                $this->setManufacturer((string) $attributes['manufacturer']);
            }
            if (isset($attributes['productname'])) {
                $this->setProductName((string) $attributes['productname']);
            }
        }

        if ($xml->present) {
            $this->setPresent((bool) (string) $xml->present);
        }

        if ($xml->name) {
            $this->setName((string) $xml->name);
        }

        foreach ($this->featureList as $feature) {
            $feature->setXml($xml);
        }
    }

    /**
     * Instantiate and return a device related to the XML element.
     */
    public static function xmlFactory(\SimpleXMLElement $xml): self
    {
        $device = new self();
        $device->setXml($xml);

        return $device;
    }

    public function isPresent(): bool
    {
        return $this->present;
    }

    public function setPresent(bool $present): self
    {
        $this->present = $present;

        return $this;
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

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getFunctionBitMask(): int
    {
        return $this->functionBitMask;
    }

    public function setFunctionBitMask(int $functionBitMask): self
    {
        $this->functionBitMask = $functionBitMask;

        if ($this->hasPowerMeter()) {
            $this->featureList[self::FEATURE_POWER_METER] = new Feature\PowerMeter();
        }

        if ($this->hasOutlet()) {
            $this->featureList[self::FEATURE_OUTLET] = new Feature\Outlet();
        }

        if ($this->hasTemperature()) {
            $this->featureList[self::FEATURE_TEMPERATURE_SENSOR] = new Feature\Temperature();
        }

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

    public function hasAlarm(): bool
    {
        return ($this->functionBitMask & self::FUNCTION_BIT_ALARM) > 0;
    }

    public function hasTemperature(): bool
    {
        return ($this->functionBitMask & self::FUNCTION_BIT_TEMPERATURE_SENSOR) > 0;
    }

    public function hasOutlet(): bool
    {
        return ($this->functionBitMask & self::FUNCTION_BIT_OUTLET) > 0;
    }

    public function hasPowerMeter(): bool
    {
        return ($this->functionBitMask & self::FUNCTION_BIT_POWER_METER) > 0;
    }
}
