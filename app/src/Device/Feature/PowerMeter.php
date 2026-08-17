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
    protected ?float $powerMeterVoltage = null;

    protected ?float $powerMeterPower = null;

    protected ?float $powerMeterEnergy = null;

    public function setXml(\SimpleXMLElement $xml): void
    {
        if ($node = $xml->powermeter) {
            if (isset($node->voltage)) {
                $this->setPowerMeterVoltage((float) $node->voltage / 1000);
            }
            if (isset($node->power)) {
                $this->setPowerMeterPower((float) $node->power / 1000);
            }
            // Not divided: AHA reports <voltage> in mV and <power> in mW, but
            // <energy> already in Wh. Dividing it too made a device with 8 Wh of
            // lifetime energy read as "0.008 Wh" — see the 546E fixture, whose
            // <voltage>229075</voltage> in the same block is a real 229 V mains
            // reading and does need the /1000.
            if (isset($node->energy)) {
                $this->setPowerMeterEnergy((float) $node->energy);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'powerMeterEnergy' => $this->getPowerMeterEnergy(),
            'powerMeterPower' => $this->getPowerMeterPower(),
            'powerMeterVoltage' => $this->getPowerMeterVoltage(),
        ];
    }

    public function getPowerMeterVoltage(): ?float
    {
        return $this->powerMeterVoltage;
    }

    public function setPowerMeterVoltage(float $powerMeterVoltage): self
    {
        $this->powerMeterVoltage = $powerMeterVoltage;

        return $this;
    }

    public function getPowerMeterPower(): ?float
    {
        return $this->powerMeterPower;
    }

    public function setPowerMeterPower(float $powerMeterPower): self
    {
        $this->powerMeterPower = $powerMeterPower;

        return $this;
    }

    public function getPowerMeterEnergy(): ?float
    {
        return $this->powerMeterEnergy;
    }

    public function setPowerMeterEnergy(float $powerMeterEnergy): self
    {
        $this->powerMeterEnergy = $powerMeterEnergy;

        return $this;
    }
}
