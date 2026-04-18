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

namespace App\Service;

class ProductImageMap
{
    private const MAP = [
        'Comet DECT' => 'comet-dect.png',
        'FRITZ!DECT 200' => 'fritz/fritzsmart_energy_200.png',
        // new name FRITZ!Smart Energy 210
        'FRITZ!DECT 210' => 'fritz/fritzdect_210.png',
        'FRITZ!DECT 250' => 'fritz/fritzsmart_energy_250.png',
        'FRITZ!DECT 301' => 'fritz/fritzdect_301.png',
        'FRITZ!DECT 302' => 'fritz/fritz-dect-302.png',
        'FRITZ!DECT 440' => 'fritz/fritzsmart_control_440.png',
        'FRITZ!DECT Repeater 100' => 'fritz/fritzdect_repeater_100.png',
        //        'FRITZ!Powerline 546E'    => 'fritz/fritz-powerline-546e.png',
        'FRITZ!Smart Control 350' => 'fritz/fritzsmart_control_350.png',
        'FRITZ!Smart Control 440' => 'fritz/fritzsmart_control_440.png',
        'FRITZ!Smart Energy 200' => 'fritz/fritzsmart_energy_200.png',
        'FRITZ!Smart Energy 210' => 'fritz/fritzdect_210.png',
        'FRITZ!Smart Energy 250' => 'fritz/fritzsmart_energy_250.png',
        'FRITZ!Smart Gateway' => 'fritz/fritzsmart_gateway.png',
        'FRITZ!Smart Thermo 302' => 'fritz/fritzsmart_thermo_302.png',
    ];

    public static function getImage(string $productName): ?string
    {
        return self::MAP[$productName] ?? null;
    }

    public static function getImageUrl(string $productName): ?string
    {
        $file = self::getImage($productName);

        return $file !== null ? 'images/products/'.$file : null;
    }
}
