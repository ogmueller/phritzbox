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

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

#[AsCommand(name: 'smart:device:xml', description: 'Dump raw AHA XML for a device (useful for bug reports)')]
class SmartDeviceXml extends Smart
{
    protected function configure(): void
    {
        $this
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command dumps the raw XML that the Fritz!Box
returns for a device. This is useful for sharing device data in bug reports
or feature requests — it contains device identity, capabilities, and current
readings in one block.

  <info>php %command.full_name% 08761\ 0372830</info>

Without an AIN, the full device list XML is dumped:

  <info>php %command.full_name%</info>

HELP)
            ->addArgument('ain', InputArgument::OPTIONAL, 'AIN of the device (omit for full list)');
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch,
    ): int {
        $ain = $input->getArgument('ain');

        $xml = $this->ahaApi->getDeviceXml($ain);
        $output->writeln($xml);

        return 0;
    }
}
