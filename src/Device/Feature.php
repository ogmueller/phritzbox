<?php

namespace App\Device;

use App\Device;

abstract class Feature
{
    abstract public function setXml(\SimpleXMLElement $xml);

    abstract public function toArray(): array;
}
