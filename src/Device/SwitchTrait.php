<?php

namespace App\Device;

use App\Device;
use App\Device\PowerMeterInterface;
use App\Device\SwitchInterface;
use App\Device\TemperatureInterface;
use http\Exception\UnexpectedValueException;

trait SwitchTrait
{
    /**
     * @var bool
     */
    protected $switchState;

    /**
     * @var string
     */
    protected $switchMode;

    /**
     * @var bool
     */
    protected $switchLock;

    /**
     * @var bool
     */
    protected $switchDeviceLock;


    public function switchState()
    {
        // TODO: Implement switchState() method.
    }

    public function setSwitch()
    {
        // TODO: Implement setSwitch() method.
    }

    /**
     * @param  \SimpleXMLElement  $xml
     */
    protected function setXmlForSwitch(\SimpleXMLElement $xml) {
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
    }

    /**
     * @return bool
     */
    public function isSwitchState(): bool
    {
        return $this->switchState;
    }

    /**
     * @param  bool  $switchState
     * @return Device
     */
    public function setSwitchState(bool $switchState): Device
    {
        $this->switchState = $switchState;

        return $this;
    }

    /**
     * @return string
     */
    public function getSwitchMode()
    {
        return $this->switchMode;
    }

    /**
     * Mode is "auto" or "manual"
     *
     * @param  string  $switchMode
     * @return Device
     */
    public function setSwitchMode(string $switchMode): Device
    {
        $switchMode = strtolower($switchMode);

        $validSwitchModes = ['auto', 'manuell', 'manual'];
        if( !in_array($switchMode, $validSwitchModes) ) {
            throw new UnexpectedValueException('Unknow switch mode');
        }

        // translate into english
        if($switchMode == 'manuell') {
            $switchMode = 'manual';
        }

        $this->switchMode = $switchMode;

        return $this;
    }

    /**
     * @return bool
     */
    public function isSwitchLock(): bool
    {
        return $this->switchLock;
    }

    /**
     * @param  bool  $switchLock
     * @return Device
     */
    public function setSwitchLock(bool $switchLock): Device
    {
        $this->switchLock = $switchLock;

        return $this;
    }

    /**
     * @return bool
     */
    public function isSwitchDeviceLock(): bool
    {
        return $this->switchDeviceLock;
    }

    /**
     * @param  bool  $switchDeviceLock
     * @return Device
     */
    public function setSwitchDeviceLock(bool $switchDeviceLock): Device
    {
        $this->switchDeviceLock = $switchDeviceLock;

        return $this;
    }
}
