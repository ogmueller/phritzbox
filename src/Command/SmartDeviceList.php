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

use App\Device;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to read out all available smart home devices.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:device:list', description: 'List all available SmartHome devices')]
class SmartDeviceList extends Smart
{
    protected function configure(): void
    {
        $this
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
        Stopwatch $stopwatch,
    ): int {
        $simpleOutput = $input->getOption('simple');
        $devices = $this->ahaApi->getDeviceListInfos();

        // cache devices to be used by other calls
        $valueItem = $this->cache->getItem('app.smart.devices');
        $valueItem->set($devices)
                  ->expiresAfter(900);
        $this->cache->save($valueItem);

        $table = new Table($output);
        $rows = [];

        /** @var Device $device */
        foreach ($devices as $device) {
            $identifier = $device->isPresent() ? '<fg=green>'.$device->getIdentifier().'</>' : $device->getIdentifier();
            $row = [
                $identifier,
                $device->getName(),
                $device->getManufacturer(),
                $device->getFirmwareVersion(),
            ];

            if ($device->hasTemperature()) {
                /** @var Device\Feature\Temperature $feature */
                $feature = $device->feature(Device::FEATURE_TEMPERATURE_SENSOR);
                $offset = $feature->getTemperatureOffset();
                $temperature = $feature->getTemperatureCelsius() + $offset;
                $row[] = \sprintf('%02.1f°C / %02.1f°C', $temperature, $offset);
            } else {
                $row[] = '-';
            }

            if ($device->hasOutlet()) {
                /** @var Device\Feature\Outlet $feature */
                $feature = $device->feature(Device::FEATURE_OUTLET);
                $tmp = [
                    $feature->isSwitchState() ? 'On ' : 'Off',
                    $feature->getSwitchMode() ?? ' ',
                    $feature->isSwitchLock() ? 'x' : '-',
                    $feature->isSwitchDeviceLock() ? 'x' : '-',
                ];
                $row[] = implode(' / ', $tmp);
            } else {
                $row[] = '-';
            }

            if ($device->hasPowerMeter()) {
                /** @var Device\Feature\PowerMeter $feature */
                $feature = $device->feature(Device::FEATURE_POWER_METER);
                $row[] = \sprintf('%03.1f W', $feature->getPowerMeterPower());
                $row[] = \sprintf('%03.1f Wh', $feature->getPowerMeterEnergy());
                $row[] = \sprintf('%03.1f V', $feature->getPowerMeterVoltage());
            } else {
                $row[] = new TableCell('-', ['colspan' => 3]);
            }

            $rows[] = $row;
        }

        if (!$simpleOutput) {
            $table->setHeaders(
                [
                    'Identifier (AIN)',
                    'Name',
                    'Mfr',
                    'Firmware',
                    'Temp / Offset',
                    'Switch / Mode / Locks',
                    'Power',
                    'Energy',
                    'Voltage',
                ]
            );
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
        $rightAligned->setPadType(\STR_PAD_LEFT);

        $table->setColumnStyle(6, $rightAligned);
        $table->setColumnStyle(7, $rightAligned);
        $table->setColumnStyle(8, $rightAligned);

        $table->setFooterTitle(\count($devices).' Devices found');
        $table->addRows($rows);
        $table->render();

        return 0;
    }

    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command will deliver basic information about all SmartHome devices:

  <info>php %command.full_name%</info>

By default the command will output a table with information about:
- Identifier (AIN): device identification number, if green the device is present
- Name: custom name defined in your Fritz!Box
- Mfr: hardware manufacturer
- Firmware: current version
- Temp / Offset: both values in Celsius
- Switch: show state of the outlet / mode / locked by API / locked at device
- Power: current power consumption, updated approx. every 2 min
- Energy: absolute consumption since first use or last reset
- Voltage: current voltage, updated approx. every 2 min

You can also use the <comment>-s</comment> option to get a simplified output:

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment>

HELP;
    }
}
