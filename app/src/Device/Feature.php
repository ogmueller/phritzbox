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

namespace App\Device;

abstract class Feature
{
    abstract public function setXml(\SimpleXMLElement $xml): void;

    abstract public function toArray(): array;
}
