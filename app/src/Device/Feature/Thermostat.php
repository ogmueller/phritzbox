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

namespace App\Device\Feature;

use App\Device\Feature;

/**
 * Smart radiator control (HKR) state, parsed from the <hkr> subtree of a
 * getdevicelistinfos response.
 *
 * Temperatures arrive in half-degree steps (a raw 42 means 21 °C), with two
 * values reserved as sentinels rather than readings: 253 means the valve is
 * closed ("off") and 254 means it is fully open ("max"). Decoding those as
 * temperatures would render 126.5 °C and 127 °C, so getSetpointCelsius()
 * returns null for both and getMode() reports which one it was.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class Thermostat extends Feature
{
    /** Raw tsoll value meaning "valve closed". */
    public const RAW_OFF = 253;

    /** Raw tsoll value meaning "valve fully open". */
    public const RAW_MAX = 254;

    public const MODE_OFF = 'off';
    public const MODE_MAX = 'max';
    public const MODE_TEMPERATURE = 'temperature';

    protected ?float $setpointCelsius = null;

    protected ?float $comfortCelsius = null;

    protected ?float $savingCelsius = null;

    protected string $mode = self::MODE_TEMPERATURE;

    /** Charge level in percent. Only newer FRITZ!OS reports it. */
    protected ?int $battery = null;

    protected ?bool $batteryLow = null;

    protected ?bool $windowOpen = null;

    protected ?bool $boostActive = null;

    protected ?bool $holidayActive = null;

    protected ?bool $summerActive = null;

    protected ?bool $lock = null;

    protected ?bool $deviceLock = null;

    /** 0 = no error; 1-6 are AVM's mounting/valve diagnostics. */
    protected ?int $errorCode = null;

    public function setXml(\SimpleXMLElement $xml): void
    {
        if (!($node = $xml->hkr)) {
            return;
        }

        if (isset($node->tsoll)) {
            $raw = (int) (string) $node->tsoll;
            $this->mode = match ($raw) {
                self::RAW_OFF => self::MODE_OFF,
                self::RAW_MAX => self::MODE_MAX,
                default => self::MODE_TEMPERATURE,
            };
            $this->setpointCelsius = self::decodeTemperature($raw);
        }

        if (isset($node->komfort)) {
            $this->comfortCelsius = self::decodeTemperature((int) (string) $node->komfort);
        }
        if (isset($node->absenk)) {
            $this->savingCelsius = self::decodeTemperature((int) (string) $node->absenk);
        }

        // Older firmware omits <battery> and only reports the low flag.
        if (isset($node->battery)) {
            $this->battery = (int) (string) $node->battery;
        }
        if (isset($node->batterylow)) {
            $this->batteryLow = (bool) (string) $node->batterylow;
        }

        if (isset($node->windowopenactiv)) {
            $this->windowOpen = (bool) (string) $node->windowopenactiv;
        }
        if (isset($node->boostactive)) {
            $this->boostActive = (bool) (string) $node->boostactive;
        }
        if (isset($node->holidayactive)) {
            $this->holidayActive = (bool) (string) $node->holidayactive;
        }
        if (isset($node->summeractive)) {
            $this->summerActive = (bool) (string) $node->summeractive;
        }
        if (isset($node->lock)) {
            $this->lock = (bool) (string) $node->lock;
        }
        if (isset($node->devicelock)) {
            $this->deviceLock = (bool) (string) $node->devicelock;
        }
        if (isset($node->errorcode)) {
            $this->errorCode = (int) (string) $node->errorcode;
        }
    }

    /**
     * Half-degree steps to °C, or null for the off/max sentinels.
     */
    private static function decodeTemperature(int $raw): ?float
    {
        if ($raw === self::RAW_OFF || $raw === self::RAW_MAX) {
            return null;
        }

        return $raw / 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'thermostatSetpoint' => $this->getSetpointCelsius(),
            'thermostatComfort' => $this->getComfortCelsius(),
            'thermostatSaving' => $this->getSavingCelsius(),
            'thermostatMode' => $this->getMode(),
            'thermostatBattery' => $this->getBattery(),
            'thermostatBatteryLow' => $this->isBatteryLow(),
            'thermostatWindowOpen' => $this->isWindowOpen(),
            'thermostatBoostActive' => $this->isBoostActive(),
            'thermostatHolidayActive' => $this->isHolidayActive(),
            'thermostatSummerActive' => $this->isSummerActive(),
            'thermostatLock' => $this->isLock(),
            'thermostatDeviceLock' => $this->isDeviceLock(),
            'thermostatErrorCode' => $this->getErrorCode(),
        ];
    }

    /** Null when the valve is off or fully open — see getMode(). */
    public function getSetpointCelsius(): ?float
    {
        return $this->setpointCelsius;
    }

    public function getComfortCelsius(): ?float
    {
        return $this->comfortCelsius;
    }

    public function getSavingCelsius(): ?float
    {
        return $this->savingCelsius;
    }

    /** One of MODE_OFF, MODE_MAX, MODE_TEMPERATURE. */
    public function getMode(): string
    {
        return $this->mode;
    }

    public function getBattery(): ?int
    {
        return $this->battery;
    }

    public function isBatteryLow(): ?bool
    {
        return $this->batteryLow;
    }

    public function isWindowOpen(): ?bool
    {
        return $this->windowOpen;
    }

    public function isBoostActive(): ?bool
    {
        return $this->boostActive;
    }

    public function isHolidayActive(): ?bool
    {
        return $this->holidayActive;
    }

    public function isSummerActive(): ?bool
    {
        return $this->summerActive;
    }

    public function isLock(): ?bool
    {
        return $this->lock;
    }

    public function isDeviceLock(): ?bool
    {
        return $this->deviceLock;
    }

    public function getErrorCode(): ?int
    {
        return $this->errorCode;
    }
}
