<?php

namespace App\Device\Avm;

use App\Device;
use App\Device\PowerMeterInterface;
use App\Device\SwitchInterface;
use App\Device\TemperatureInterface;

class FritzPowerline546E extends Device implements PowerMeterInterface, SwitchInterface
{
    use Device\PowerMeterTrait;
    use Device\SwitchTrait;

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        $array['powerMeterEnergy']   = $this->getPowerMeterEnergy();
        $array['powerMeterPower']    = $this->getPowerMeterPower();
        $array['powerMeterVoltage']  = $this->getPowerMeterVoltage();
        $array['switchDeviceLock']   = $this->isSwitchDeviceLock();
        $array['switchLock']         = $this->isSwitchLock();
        $array['switchMode']         = $this->getSwitchMode();
        $array['switchState']        = $this->isSwitchState();

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
    }
}
