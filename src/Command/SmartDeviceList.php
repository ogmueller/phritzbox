<?php

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Device;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to read out all available smart home devices
 *
 * To use this command, open a terminal window, enter into your project
 * directory and execute the following:
 *
 *     $ php bin/console smart:device:list
 *
 * To output detailed information, increase the command verbosity:
 *
 *     $ php bin/console smart:device:list -vv
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
class SmartDeviceList extends Smart
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:device:list';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('List all available SmartHome devices')
            ->setHelp($this->getCommandHelp())
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'No header output'
            );
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch
    ): int {
        $simpleOutput = $input->getOption('simple');
        $devices      = $this->ahaApi->getDeviceListInfos();

        $table = new Table($output);
        $rows  = [];

        /** @var Device $device */
        foreach ($devices as $device) {
            $row = [
                $device->getIdentifier(),
                $device->getName(),
            ];

            if ($device->hasTemperature()) {
                /** @var Device\Feature\Temperature $feature */
                $feature     = $device->feature(Device::FEATURE_TEMPERATURE_SENSOR);
                $offset      = $feature->getTemperatureOffset();
                $temperature = $feature->getTemperatureCelsius() + $offset;
                $row[]       = sprintf('%02.1fC / %02.1fC', $temperature, $offset);
            } else {
                $row[] = '-';
            }

            if ($device->hasOutlet()) {
                /** @var Device\Feature\Outlet $feature */
                $feature = $device->feature(Device::FEATURE_OUTLET);
                $status  = $feature->isSwitchState();
                $row[]   = $status ? 'On' : 'Off';
            } else {
                $row[] = '-';
            }

            if ($device->hasPowerMeter()) {
                /** @var Device\Feature\PowerMeter $feature */
                $feature = $device->feature(Device::FEATURE_POWER_METER);
                $row[]   = sprintf('%03.1fV', $feature->getPowerMeterVoltage());
                $row[]   = sprintf('%03.1fV', $feature->getPowerMeterPower());
                $row[]   = sprintf('%03.1fV', $feature->getPowerMeterEnergy());
            } else {
                $row[] = new TableCell('-', ['colspan' => 3]);
            }

            $rows[] = $row;
        }

        if (!$simpleOutput) {
            $table->setHeaders(['Identifier', 'Name', 'Temp / Offset', 'Switch', 'Voltage', 'Power', 'Energy']);
//            $table->set
        } else {
            $borderless = new TableStyle();
            $borderless
                ->setHorizontalBorderChars('')
                ->setVerticalBorderChars('')
                ->setDefaultCrossingChar('')
                ->setBorderFormat('');

            $table->setStyle($borderless);
        }

        $rightAligned = new TableStyle();
        $rightAligned->setPadType(STR_PAD_LEFT);

        $table->setColumnStyle(5, $rightAligned);
        $table->setColumnStyle(6, $rightAligned);
        $table->setColumnStyle(7, $rightAligned);

        $table->setFooterTitle(count($devices).' Devices found');
        $table->addRows($rows);
        $table->render();

        return 0;
    }

    /**
     * The command help is usually included in the configure() method, but when
     * it's too long, it's better to define a separate method to maintain the
     * code readability.
     */
    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command creates new users and saves them in the database:

  <info>php %command.full_name%</info> <comment>username password email</comment>

By default the command creates regular users. To create administrator users,
add the <comment>--admin</comment> option:

  <info>php %command.full_name%</info> username password email <comment>--admin</comment>

If you omit any of the three required arguments, the command will ask you to
provide the missing values:

  # command will ask you for the email
  <info>php %command.full_name%</info> <comment>username password</comment>

  # command will ask you for the email and password
  <info>php %command.full_name%</info> <comment>username</comment>

  # command will ask you for all arguments
  <info>php %command.full_name%</info>

HELP;
    }
}
