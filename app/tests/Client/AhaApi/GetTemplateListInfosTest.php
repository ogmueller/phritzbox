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

namespace App\Tests\Client\AhaApi;

use PHPUnit\Framework\TestCase;

class GetTemplateListInfosTest extends TestCase
{
    public function testReturnsSimpleXmlElement(): void
    {
        $xml = '<?xml version="1.0"?><templatelist><template identifier="tmp-1" id="1" name="Night mode"/></templatelist>';
        $aha = \App\Tests\Helper::mockClientHelper($this, $xml);

        $result = $aha->getTemplateListInfos();

        self::assertInstanceOf(\SimpleXMLElement::class, $result);
    }

    public function testParsesTemplateName(): void
    {
        $xml = '<?xml version="1.0"?><templatelist><template identifier="tmp-1" id="1" name="Night mode"/></templatelist>';
        $aha = \App\Tests\Helper::mockClientHelper($this, $xml);

        $result = $aha->getTemplateListInfos();

        self::assertSame('Night mode', (string) $result->template['name']);
    }
}
