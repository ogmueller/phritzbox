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

namespace App\Tests\Command;

use App\Command\Smart;
use App\Command\SmartTemplateList;
use Symfony\Component\Console\Command\Command;

class SmartTemplateListTest extends CommandTestCase
{
    protected function createCommand(): Smart
    {
        return new SmartTemplateList($this->ahaApi, $this->entityManager, $this->cache);
    }

    private function stubTemplates(string $xml): void
    {
        $this->ahaApi->method('getTemplateListInfos')->willReturn(new \SimpleXMLElement($xml));
    }

    public function testListsTemplatesWithDevices(): void
    {
        // The shape real FRITZ!OS returns: name as a child element.
        $this->stubTemplates(
            '<templatelist><template identifier="tmp-1" id="5000">'
            .'<name>Night mode</name>'
            .'<devices><device identifier="08761 0372830"/><device identifier="11630 0103875"/></devices>'
            .'</template></templatelist>'
        );

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('tmp-1', $display);
        self::assertStringContainsString('5000', $display);
        self::assertStringContainsString('Night mode', $display);
        self::assertStringContainsString('08761 0372830', $display);
        self::assertStringContainsString('11630 0103875', $display);
    }

    public function testFallsBackToNameAttribute(): void
    {
        // Some firmware revisions expose the name as an attribute instead.
        $this->stubTemplates('<templatelist><template identifier="tmp-1" id="1" name="Day mode"/></templatelist>');

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Day mode', $tester->getDisplay());
    }

    public function testTemplateWithoutDevicesRendersPlaceholder(): void
    {
        $this->stubTemplates('<templatelist><template identifier="tmp-1" id="1"><name>Empty</name></template></templatelist>');

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('-', $tester->getDisplay());
    }

    public function testSimpleOutputPrintsIdentifiersOnly(): void
    {
        $this->stubTemplates(
            '<templatelist>'
            .'<template identifier="tmp-1" id="1"><name>Night mode</name></template>'
            .'<template identifier="tmp-2" id="2"><name>Day mode</name></template>'
            .'</templatelist>'
        );

        $tester = $this->runCommand(['--simple' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('tmp-1', $display);
        self::assertStringContainsString('tmp-2', $display);
        // No table chrome and no names in scripting mode.
        self::assertStringNotContainsString('Night mode', $display);
        self::assertStringNotContainsString('Identifier', $display);
    }

    public function testErrorOnEmptyList(): void
    {
        $this->stubTemplates('<templatelist/>');

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
