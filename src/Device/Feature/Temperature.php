<?php

namespace App\Device\Feature;

use App\Device;

class Temperature extends Device\Feature
{
    /**
     * @var float
     */
    protected $temperatureCelsius;

    /**
     * @var float
     */
    protected $temperatureOffset;

    public function setXml(\SimpleXMLElement $xml)
    {
        if ($node = $xml->temperature) {
            if (isset($node->celsius)) {
                $this->setTemperatureCelsius((float)$node->celsius / 10);
            }
            if (isset($node->offset)) {
                $this->setTemperatureOffset((float)$node->offset / 10);
            }
        }
    }

    public function toArray(): array
    {
        return [
            'temperatureCelsius' => $this->getTemperatureCelsius(),
            'temperatureOffset'  => $this->getTemperatureOffset(),
        ];
    }

    /**
     * @return float
     */
    public function getTemperatureCelsius(): float
    {
        return (float)$this->temperatureCelsius;
    }

    /**
     * @param  float  $temperatureCelsius
     * @return Device
     */
    public function setTemperatureCelsius(float $temperatureCelsius): Temperature
    {
        $this->temperatureCelsius = $temperatureCelsius;

        return $this;
    }

    /**
     * @return float
     */
    public function getTemperatureOffset(): float
    {
        return (float)$this->temperatureOffset;
    }

    /**
     * @param  float  $temperatureOffset
     * @return Device
     */
    public function setTemperatureOffset(float $temperatureOffset): Temperature
    {
        $this->temperatureOffset = $temperatureOffset;

        return $this;
    }
}
