<?php

namespace App\Device;

use App\Device;
use App\Device\PowerMeterInterface;
use App\Device\SwitchInterface;
use App\Device\TemperatureInterface;
use http\Exception\UnexpectedValueException;

trait PowerMeterTrait
{
    /**
     * @var float
     */
    protected $powerMeterVoltage;

    /**
     * @var float
     */
    protected $powerMeterPower;

    /**
     * @var float
     */
    protected $powerMeterEnergy;


    public function voltage()
    {
        // TODO: Implement voltage() method.
    }

    public function power()
    {
        // TODO: Implement power() method.
    }

    public function energy()
    {
        // TODO: Implement energy() method.
    }

    /**
     * @return float
     */
    public function getPowerMeterVoltage(): float
    {
        return $this->powerMeterVoltage;
    }

    /**
     * @param  float  $powerMeterVoltage
     * @return Device
     */
    public function setPowerMeterVoltage(float $powerMeterVoltage): Device
    {
        $this->powerMeterVoltage = $powerMeterVoltage;

        return $this;
    }

    /**
     * @return float
     */
    public function getPowerMeterPower(): float
    {
        return $this->powerMeterPower;
    }

    /**
     * @param  float  $powerMeterPower
     * @return Device
     */
    public function setPowerMeterPower(float $powerMeterPower): Device
    {
        $this->powerMeterPower = $powerMeterPower;

        return $this;
    }

    /**
     * @return float
     */
    public function getPowerMeterEnergy(): float
    {
        return $this->powerMeterEnergy;
    }

    /**
     * @param  float  $powerMeterEnergy
     * @return Device
     */
    public function setPowerMeterEnergy(float $powerMeterEnergy): Device
    {
        $this->powerMeterEnergy = $powerMeterEnergy;

        return $this;
    }
}
