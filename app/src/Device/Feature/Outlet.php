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

class Outlet extends Feature
{
    protected ?bool $switchState = null;

    protected ?string $switchMode = null;

    protected ?bool $switchLock = null;

    protected ?bool $switchDeviceLock = null;

    public function setXml(\SimpleXMLElement $xml): void
    {
        if ($node = $xml->switch) {
            if (isset($node->state)) {
                $this->setSwitchState((bool) (string) $node->state);
            }
            if (isset($node->mode)) {
                $this->setSwitchMode((string) $node->mode);
            }
            if (isset($node->lock)) {
                $this->setSwitchLock((bool) (string) $node->lock);
            }
            if (isset($node->devicelock)) {
                $this->setSwitchDeviceLock((bool) (string) $node->devicelock);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'switchDeviceLock' => $this->isSwitchDeviceLock(),
            'switchLock' => $this->isSwitchLock(),
            'switchMode' => $this->getSwitchMode(),
            'switchState' => $this->isSwitchState(),
        ];
    }

    public function isSwitchState(): ?bool
    {
        return $this->switchState;
    }

    public function setSwitchState(bool $switchState): self
    {
        $this->switchState = $switchState;

        return $this;
    }

    public function getSwitchMode(): ?string
    {
        return $this->switchMode;
    }

    /**
     * Mode is "auto" or "manual".
     */
    public function setSwitchMode(string $switchMode): self
    {
        $switchMode = mb_strtolower($switchMode);

        $validSwitchModes = ['auto', 'manuell', 'manual', ''];
        if (!\in_array($switchMode, $validSwitchModes, true)) {
            throw new \UnexpectedValueException('Unknown switch mode "'.$switchMode.'"');
        }

        // translate into english
        if ($switchMode === 'manuell') {
            $switchMode = 'manual';
        }

        $this->switchMode = $switchMode;

        return $this;
    }

    public function isSwitchLock(): ?bool
    {
        return $this->switchLock;
    }

    public function setSwitchLock(bool $switchLock): self
    {
        $this->switchLock = $switchLock;

        return $this;
    }

    public function isSwitchDeviceLock(): ?bool
    {
        return $this->switchDeviceLock;
    }

    public function setSwitchDeviceLock(bool $switchDeviceLock): self
    {
        $this->switchDeviceLock = $switchDeviceLock;

        return $this;
    }
}
