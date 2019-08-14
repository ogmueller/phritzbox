<?php

namespace App\Device\Avm;

use App\Device;
use App\Device\PowerMeterInterface;
use App\Device\SwitchInterface;
use App\Device\TemperatureInterface;

class FritzDect200 extends Device implements PowerMeterInterface, SwitchInterface, TemperatureInterface
{
    use Device\PowerMeterTrait;
    use Device\SwitchTrait;
    use Device\TemperatureTrait;

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        $array['powerMeterEnergy'] = $this->getPowerMeterEnergy();
        $array['powerMeterPower'] = $this->getPowerMeterPower();
        $array['powerMeterVoltage'] = $this->getPowerMeterVoltage();
        $array['switchDeviceLock'] = $this->isSwitchDeviceLock();
        $array['switchLock'] = $this->isSwitchLock();
        $array['switchMode'] = $this->getSwitchMode();
        $array['switchState'] = $this->isSwitchState();
        $array['temperatureCelsius'] = $this->getTemperatureCelsius();
        $array['temperatureOffset'] = $this->getTemperatureOffset();

        return $array;
    }

    /**
     * @inheritDoc
     */
    public function setXml(\SimpleXMLElement $xml)
    {
        parent::setXml($xml);

        $this->setXmlForSwitch($xml);
        $this->setXmlForPowerMeter($xml);
        $this->setXmlForTemperature($xml);
    }

}
