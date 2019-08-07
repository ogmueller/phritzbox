<?php

namespace App;

use Symfony\Component\Yaml\Parser;

class Device
{
    const FUNCTION_BIT_HANFUN_DEVICE = 1 << 0;
    const FUNCTION_BIT_ALARM = 1 << 4;
    const FUNCTION_BIT_THERMOSTAT = 1 << 6;
    const FUNCTION_BIT_POWER_METER = 1 << 7;
    const FUNCTION_BIT_TEMPERATURE_SENSOR = 1 << 8;
    const FUNCTION_BIT_OUTLET = 1 << 9;
    const FUNCTION_BIT_DECT_REPEATER = 1 << 10;
    const FUNCTION_BIT_MICROFON = 1 << 11;
    const FUNCTION_BIT_HANFUN_UNIT = 1 << 13;

    /**
     * @var array
     */
    protected static $mapping;

    /**
     * @var bool
     */
    protected $present = false;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var string
     */
    protected $identifier;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var int
     */
    protected $functionBitMask;

    /**
     * @var string
     */
    protected $firmwareVersion;

    /**
     * @var string
     */
    protected $manufacturer;

    /**
     * @var string
     */
    protected $productName;

    public function __construct()
    {
    }

    /**
     * Setup device using fritzbox XML response
     *
     * @param  \SimpleXMLElement  $xml
     */
    public function setXml(\SimpleXMLElement $xml)
    {
        if ($attributes = $xml->attributes()) {
            if (isset($attributes['identifier'])) {
                $this->setIdentifier((string)$attributes['identifier']);
            }
            if (isset($attributes['id'])) {
                $this->setId((string)$attributes['id']);
            }
            if (isset($attributes['functionbitmask'])) {
                $this->setFunctionBitMask((int)$attributes['functionbitmask']);
            }
            if (isset($attributes['fwversion'])) {
                $this->setFirmwareVersion((string)$attributes['fwversion']);
            }
            if (isset($attributes['manufacturer'])) {
                $this->setManufacturer((string)$attributes['manufacturer']);
            }
            if (isset($attributes['productname'])) {
                $this->setProductName((string)$attributes['productname']);
            }
        }

        if($xml->present) {
            $this->setPresent((bool)$xml->present);
        }

        if($xml->name) {
            $this->setName((string)$xml->name);
        }
    }

    /**
     * Instantiate and return a device related to the XML element
     *
     * @param  \SimpleXMLElement  $xml
     * @return Device
     */
    static public function xmlFactory(\SimpleXMLElement $xml): Device
    {
        if (empty(self::$mapping)) {
            $yaml          = new Parser();
            self::$mapping = $yaml->parse(file_get_contents(__DIR__.'/../config/devices.yaml'));
        }

        // analyse XML to identify device
        $attr = $xml->attributes();

        $className = '\App\Device';
        if (isset($attr['manufacturer']) && isset($attr['productname'])) {
            $manufacturer = strtolower($attr['manufacturer']);
            $productName  = strtolower($attr['productname']);
            if (!empty(self::$mapping[$manufacturer][$productName])) {
                $className = self::$mapping[$manufacturer][$productName];
            }
        }

        /** @var Device $device */
        $device = new $className;
        $device->setXml($xml);

        return $device;
    }

    /**
     * @return bool
     */
    public function isPresent(): bool
    {
        return $this->present;
    }

    /**
     * @param  bool  $present
     * @return Device
     */
    public function setPresent(bool $present): Device
    {
        $this->present = $present;

        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param  string  $name
     * @return Device
     */
    public function setName(string $name): Device
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @param  string  $identifier
     * @return Device
     */
    public function setIdentifier(string $identifier): Device
    {
        $this->identifier = $identifier;

        return $this;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param  string  $id
     * @return Device
     */
    public function setId(string $id): Device
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @return int
     */
    public function getFunctionBitMask(): int
    {
        return $this->functionBitMask;
    }

    /**
     * @param  int  $functionBitMask
     * @return Device
     */
    public function setFunctionBitMask(int $functionBitMask): Device
    {
        $this->functionBitMask = $functionBitMask;

        return $this;
    }

    /**
     * @return string
     */
    public function getFirmwareVersion(): string
    {
        return $this->firmwareVersion;
    }

    /**
     * @param  string  $firmwareVersion
     * @return Device
     */
    public function setFirmwareVersion(string $firmwareVersion): Device
    {
        $this->firmwareVersion = $firmwareVersion;

        return $this;
    }

    /**
     * @return string
     */
    public function getManufacturer(): string
    {
        return $this->manufacturer;
    }

    /**
     * @param  string  $manufacturer
     * @return Device
     */
    public function setManufacturer(string $manufacturer): Device
    {
        $this->manufacturer = $manufacturer;

        return $this;
    }

    /**
     * @return string
     */
    public function getProductName(): string
    {
        return $this->productName;
    }

    /**
     * @param  string  $productName
     * @return Device
     */
    public function setProductName(string $productName): Device
    {
        $this->productName = $productName;

        return $this;
    }

    public function hasAlarm() {
        return ($this->functionBitMask & (self::FUNCTION_BIT_ALARM)) > 0;
    }

    public function hasTemperature() {
        return ($this->functionBitMask & (self::FUNCTION_BIT_TEMPERATURE_SENSOR)) > 0;
    }

    public function hasSwitch() {
        return ($this->functionBitMask & (self::FUNCTION_BIT_OUTLET)) > 0;
    }

    public function hasPowerMeter() {
        return ($this->functionBitMask & (self::FUNCTION_BIT_POWER_METER)) > 0;
    }
}
