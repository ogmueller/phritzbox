<?php

namespace App\Device;

use App\Device;

trait TemperatureTrait
{
    /**
     * @var float
     */
    protected $temperatureCelsius;

    /**
     * @var float
     */
    protected $temperatureOffset;

    protected function setXmlForTemperature(\SimpleXMLElement $xml)
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

    public function temperature()
    {
        // TODO: Implement temperature() method.
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
    public function setTemperatureCelsius(float $temperatureCelsius): Device
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
    public function setTemperatureOffset(float $temperatureOffset): Device
    {
        $this->temperatureOffset = $temperatureOffset;

        return $this;
    }
}
