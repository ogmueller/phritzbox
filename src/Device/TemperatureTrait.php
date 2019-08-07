<?php

namespace App\Device;

use App\Device;
use App\Device\PowerMeterInterface;
use App\Device\SwitchInterface;
use App\Device\TemperatureInterface;
use http\Exception\UnexpectedValueException;

trait TemperatureTrait
{
    /**
     * @var float
     */
    protected $temperatureCelsius;

    /**
     * @var float
     */
    protected $temperatureOffset;


    public function temperature()
    {
        // TODO: Implement temperature() method.
    }

    /**
     * @return float
     */
    public function getTemperatureCelsius(): float
    {
        return $this->temperatureCelsius;
    }

    /**
     * @param  float  $temperatureCelsius
     * @return Device
     */
    public function setTemperatureCelsius(float $temperatureCelsius): Device
    {
        $this->temperatureCelsius = $temperatureCelsius;

        return $this;
    }

    /**
     * @return float
     */
    public function getTemperatureOffset(): float
    {
        return $this->temperatureOffset;
    }

    /**
     * @param  float  $temperatureOffset
     * @return Device
     */
    public function setTemperatureOffset(float $temperatureOffset): Device
    {
        $this->temperatureOffset = $temperatureOffset;

        return $this;
    }
}
