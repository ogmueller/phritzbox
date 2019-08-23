<?php

namespace App\Device\Feature;

use App\Device;
use App\Device\Feature;
use UnexpectedValueException;

class Outlet extends Feature
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

    /**
     * @param  \SimpleXMLElement  $xml
     */
    public function setXml(\SimpleXMLElement $xml)
    {
        if ($node = $xml->switch) {
            if (isset($node->state)) {
                $this->setSwitchState((bool)(string)($node->state));
            }
            if (isset($node->mode)) {
                $this->setSwitchMode((string)$node->mode);
            }
            if (isset($node->lock)) {
                $this->setSwitchLock((bool)(string)$node->lock);
            }
            if (isset($node->devicelock)) {
                $this->setSwitchDeviceLock((bool)(string)$node->devicelock);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'switchDeviceLock' => $this->isSwitchDeviceLock(),
            'switchLock'       => $this->isSwitchLock(),
            'switchMode'       => $this->getSwitchMode(),
            'switchState'      => $this->isSwitchState(),
        ];
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
    public function setSwitchState(bool $switchState): Outlet
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
    public function setSwitchMode(string $switchMode): Outlet
    {
        $switchMode = strtolower($switchMode);

        $validSwitchModes = ['auto', 'manuell', 'manual', ''];
        if (!in_array($switchMode, $validSwitchModes)) {
            throw new UnexpectedValueException('Unknown switch mode "'.$switchMode.'"');
        }

        // translate into english
        if ($switchMode === 'manuell') {
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
    public function setSwitchLock(bool $switchLock): Outlet
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
    public function setSwitchDeviceLock(bool $switchDeviceLock): Outlet
    {
        $this->switchDeviceLock = $switchDeviceLock;

        return $this;
    }
}
