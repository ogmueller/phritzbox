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

class PowerMeter extends Feature
{
    protected float $powerMeterVoltage;

    protected float $powerMeterPower;

    protected float $powerMeterEnergy;

    public function setXml(\SimpleXMLElement $xml): void
    {
        if ($node = $xml->powermeter) {
            if (isset($node->voltage)) {
                $this->setPowerMeterVoltage((float) $node->voltage / 1000);
            }
            if (isset($node->power)) {
                $this->setPowerMeterPower((float) $node->power / 1000);
            }
            if (isset($node->energy)) {
                $this->setPowerMeterEnergy((float) $node->energy / 1000);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'powerMeterEnergy' => $this->getPowerMeterEnergy(),
            'powerMeterPower' => $this->getPowerMeterPower(),
            'powerMeterVoltage' => $this->getPowerMeterVoltage(),
        ];
    }

    public function getPowerMeterVoltage(): float
    {
        return $this->powerMeterVoltage;
    }

    public function setPowerMeterVoltage(float $powerMeterVoltage): self
    {
        $this->powerMeterVoltage = $powerMeterVoltage;

        return $this;
    }

    public function getPowerMeterPower(): float
    {
        return $this->powerMeterPower;
    }

    public function setPowerMeterPower(float $powerMeterPower): self
    {
        $this->powerMeterPower = $powerMeterPower;

        return $this;
    }

    public function getPowerMeterEnergy(): float
    {
        return $this->powerMeterEnergy;
    }

    public function setPowerMeterEnergy(float $powerMeterEnergy): self
    {
        $this->powerMeterEnergy = $powerMeterEnergy;

        return $this;
    }
}
