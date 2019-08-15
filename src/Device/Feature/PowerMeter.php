<?php

namespace App\Device\Feature;

use App\Device;
use App\Device\Feature;

class PowerMeter extends Feature
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

    public function setXml(\SimpleXMLElement $xml)
    {
        if ($node = $xml->powermeter) {
            if (isset($node->voltage)) {
                $this->setPowerMeterVoltage((float)$node->voltage / 1000);
            }
            if (isset($node->power)) {
                $this->setPowerMeterPower((float)$node->power / 1000);
            }
            if (isset($node->energy)) {
                $this->setPowerMeterEnergy((float)$node->energy / 1000);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'powerMeterEnergy'  => $this->getPowerMeterEnergy(),
            'powerMeterPower'   => $this->getPowerMeterPower(),
            'powerMeterVoltage' => $this->getPowerMeterVoltage(),
        ];
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
    public function setPowerMeterVoltage(float $powerMeterVoltage): PowerMeter
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
    public function setPowerMeterPower(float $powerMeterPower): PowerMeter
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
    public function setPowerMeterEnergy(float $powerMeterEnergy): PowerMeter
    {
        $this->powerMeterEnergy = $powerMeterEnergy;

        return $this;
    }
}
