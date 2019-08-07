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

    /**
     * @inheritDoc
     */
    public function setXml(\SimpleXMLElement $xml)
    {
        parent::setXml($xml);

        if ($node = $xml->switch) {
            if (isset($node->state)) {
                $this->setSwitchState((bool)$node->state);
            }
            if (isset($node->mode)) {
                $this->setSwitchMode((string)$node->mode);
            }
            if (isset($node->lock)) {
                $this->setSwitchLock((bool)$node->lock);
            }
            if (isset($node->devicelock)) {
                $this->setSwitchDeviceLock((bool)$node->devicelock);
            }
        }

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

        if ($node = $xml->temperature) {
            if (isset($node->celsius)) {
                $this->setTemperatureCelsius((float)$node->celsius / 10);
            }
            if (isset($node->offset)) {
                $this->setTemperatureOffset((float)$node->offset / 10);
            }
        }
    }

}
