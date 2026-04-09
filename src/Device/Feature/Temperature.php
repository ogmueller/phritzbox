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

class Temperature extends Feature
{
    protected float $temperatureCelsius;

    protected float $temperatureOffset;

    public function setXml(\SimpleXMLElement $xml): void
    {
        if ($node = $xml->temperature) {
            if (isset($node->celsius)) {
                $this->setTemperatureCelsius((float) $node->celsius / 10);
            }
            if (isset($node->offset)) {
                $this->setTemperatureOffset((float) $node->offset / 10);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'temperatureCelsius' => $this->getTemperatureCelsius(),
            'temperatureOffset' => $this->getTemperatureOffset(),
        ];
    }

    public function getTemperatureCelsius(): float
    {
        return $this->temperatureCelsius;
    }

    public function setTemperatureCelsius(float $temperatureCelsius): self
    {
        $this->temperatureCelsius = $temperatureCelsius;

        return $this;
    }

    public function getTemperatureOffset(): float
    {
        return $this->temperatureOffset;
    }

    public function setTemperatureOffset(float $temperatureOffset): self
    {
        $this->temperatureOffset = $temperatureOffset;

        return $this;
    }
}
