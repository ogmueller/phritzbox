<?php

namespace App\Device;

interface PowerMeterInterface
{
    public function voltage();
    public function power();
    public function energy();
}
