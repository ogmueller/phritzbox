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

use App\Client\Helper;
use App\Device;
use noximo\PHPColoredAsciiLinechart\Colorizers\AsciiColorizer;
use noximo\PHPColoredAsciiLinechart\Linechart;
use noximo\PHPColoredAsciiLinechart\Settings;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputArgument;
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
class SmartDeviceStats extends Smart
{
    // to make your command lazily loaded, configure the $defaultName static property,
    // so it will be instantiated only when the command is actually called.
    protected static $defaultName = 'smart:device:stats';

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->setDescription('Show basic information of a SmartHome devices')
            ->setHelp($this->getCommandHelp())
            ->addOption(
                'simple',
                's',
                InputOption::VALUE_NONE,
                'No header output'
            )
            ->addArgument('ain', InputArgument::REQUIRED, 'Actor identification number');
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch
    ): int {
        $simpleOutput = $input->getOption('simple');
        $ain          = $input->getArgument('ain');
        $stats        = $this->ahaApi->getBasicDeviceStats($ain);
        $helper       = new Helper();

        if (!$simpleOutput) {
            $settings = new Settings();
            $settings->setColorizer(new AsciiColorizer())
                     ->setPadding(7, ' ')  // Set lenght of a padding and character used
                     ->setOffset(6)  // Offset left border
                     ->setFormat(  // Control how y axis labels will be printed out
                    function ($x, Settings $settings) {
                        $padding       = $settings->getPadding();
                        $paddingLength = \strlen($padding);

                        return substr($padding.number_format($x, 2, '.', ''), -$paddingLength);
                    }
                );

            #
            # temperature
            #
            if (isset($stats['temperature'][0]['values'])) {
                $data   = $stats['temperature'][0];
                $values = $data['values'];

                $temperatures = new Linechart();
                $settings->setHeight(min(20, (max($values) - min($values)) * 2));
                $temperatures->setSettings($settings);

                $temperatures->addMarkers(
                    array_reverse($values),
                    [AsciiColorizer::RED, AsciiColorizer::BOLD],
                    [AsciiColorizer::BLUE, AsciiColorizer::BOLD]
                );

                $this->io->writeln(
                    'Temperature [C] every '.$this->humanReadableTime(
                        $data['interval']
                    ).' within last '.$this->timeRange(
                        $data['interval'],
                        count($values)
                    )
                );
                $this->io->writeln($temperatures->chart());
            }

            #
            # voltage
            #
            if (isset($stats['voltage'][0]['values'])) {
                $data   = $stats['voltage'][0];
                $values = $data['values'];
                $milli  = 1000 / $data['factor'];
                $unit   = $helper->bestFactor(max($values) * $milli, $data['unit']);
//dump($unit, $data['factor'],max($values),max($values)/$data['factor']*1000);

                [$voltages, $values] = $this->createChart($data['values'], $unit['factor'] / $milli, $settings);

                $voltages->addMarkers(
                    array_reverse($values),
                    [AsciiColorizer::YELLOW, AsciiColorizer::BOLD]
                );

                $this->io->writeln(
                    'Voltage ['.$unit['unit'].'] every '.$this->humanReadableTime(
                        $data['interval']
                    ).' within last '.$this->timeRange(
                        $data['interval'],
                        count($values)
                    )
                );
                $this->io->writeln($voltages->chart());
            }

            #
            # power
            #
            if (isset($stats['power'][0]['values'])) {
                $data   = $stats['power'][0];
                $values = $data['values'];
                $milli  = 1000 / $data['factor'];
                $unit   = $helper->bestFactor(max($values) * $milli, $data['unit']);

                [$powers, $values] = $this->createChart($data['values'], $unit['factor'] / $milli, $settings);

                $powers->addMarkers(
                    array_reverse($values),
                    [AsciiColorizer::GREEN, AsciiColorizer::BOLD]
                );

                $this->io->writeln(
                    'Power ['.$unit['unit'].'] every '.$this->humanReadableTime(
                        $data['interval']
                    ).' within last '.$this->timeRange(
                        $data['interval'],
                        count($values)
                    )
                );
                $this->io->writeln($powers->chart());
            }

            #
            # energy [year]
            #
            if (isset($stats['energy'][0]['values'])) {
                $data   = $stats['energy'][0];
                $values = $data['values'];
                $milli  = 1000 / $data['factor'];
                $unit   = $helper->bestFactor(max($values) * $milli, $data['unit']);

                [$energyYear, $values] = $this->createChart($data['values'], $unit['factor'] / $milli, $settings);

                $energyYear->addMarkers(
                    array_reverse($values),
                    [AsciiColorizer::CYAN, AsciiColorizer::BOLD]
                );

                $this->io->writeln(
                    'Energy ['.$unit['unit'].'] every '.$this->humanReadableTime(
                        $data['interval']
                    ).' within last '.$this->timeRange(
                        $data['interval'],
                        count($values)
                    )
                );
                $this->io->writeln($energyYear->chart());
            }

            #
            # energy [month]
            #
            if (isset($stats['energy'][1]['values'])) {
                $data   = $stats['energy'][1];
                $values = $data['values'];
                $milli  = 1000 / $data['factor'];
                $unit   = $helper->bestFactor(max($values) * $milli, $data['unit']);

                [$energyMonth, $values] = $this->createChart($data['values'], $unit['factor'] / $milli, $settings);

                $energyMonth->addMarkers(
                    array_reverse($values),
                    [AsciiColorizer::CYAN, AsciiColorizer::BOLD]
                );

                $this->io->writeln(
                    'Energy ['.$unit['unit'].'] every '.$this->humanReadableTime(
                        $data['interval']
                    ).' within last '.$this->timeRange(
                        $data['interval'],
                        count($values)
                    )
                );
                $this->io->writeln($energyMonth->chart());
            }
        } else {
//            dump($stats);

            // CSV header
            $return = [
                ['name', 'unit', 'number_of_values', 'time_interval', 'values...'],
            ];

            #
            # temperature
            #
            if (isset($stats['temperature'][0]['values'])) {
                $data   = $stats['temperature'][0];
                $return[] = array_merge(['temperature', 'C', $data['count'], $data['interval']], $data['values']);
            }

            #
            # voltage
            #
            if (isset($stats['voltage'][0]['values'])) {
                $data   = $stats['voltage'][0];
                dump($data);
                $return[] = array_merge(['voltage', 'mV', $data['count'], $data['interval']], $data['values']);
            }

            #
            # power
            #
            if (isset($stats['power'][0]['values'])) {
                $data   = $stats['power'][0];
                $return[] = array_merge(['power', 'cW', $data['count'], $data['interval']], $data['values']);
            }

            #
            # energy [year]
            #
            if (isset($stats['energy'][0]['values'])) {
                $data   = $stats['energy'][0];
                $return[] = array_merge(['energy_year', 'Wh', $data['count'], $data['interval']], $data['values']);
            }

            #
            # energy [month]
            #
            if (isset($stats['energy'][1]['values'])) {
                $data   = $stats['energy'][1];
                $return[] = array_merge(['energy_month', 'Wh', $data['count'], $data['interval']], $data['values']);
            }

            foreach($return as $line) {

                $stream = $output->getStream();
//                dump($stream);
                fputcsv($stream, $line);
            }
            exit;
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
        }

        return 0;
    }

    /**
     * @param  array     $values
     * @param  int       $factor
     * @param  Settings  $settings
     * @return array
     */
    protected function createChart(array $values, int $factor, Settings $settings): array
    {
        $terminalWidth   = getenv('COLUMNS');
        $chartWidth      = $terminalWidth - $settings->getOffset() - 6;
        $maxXScaleHeight = 20;

        if (count($values) > $chartWidth) {
            // get most current values printable at given console width
            $values = array_slice($values, 0, $chartWidth);
        }

        if ($factor != 0) {
            // convert values to best readable unit
            $values = array_map(
                function ($value) use ($factor) {
                    return $value / $factor;
                },
                $values
            );
        }

        $chart = new Linechart();
        $settings->setHeight(min($maxXScaleHeight, ceil(max($values)) - floor(min($values))));
        $chart->setSettings($settings);

        return [$chart, $values];
    }

    /**
     * Convert seconds into e.g. 5days 3h 40sec
     *
     * @param  int  $value  Seconds
     * @return string
     */
    protected function humanReadableTime(int $value): string
    {
        $prefixList = [
            'yr'  => 32140800,
            'mo'  => 2678400,
            'wk'  => 604800,
            'd'   => 86400,
            'hr'  => 3600,
            'min' => 60,
            'sec' => 1,
        ];

        $ret = [];
        foreach ($prefixList as $prefix => $factor) {
            $mod   = $value % $factor;
            $value /= $factor;
            if ($value >= 1) {
                $ret[] = floor($value).$prefix;
            }
            $value = $mod;
        }

        return implode(' ', $ret);
    }

    /**
     * @param  int  $resolution
     * @param  int  $count
     * @return string
     */
    protected function timeRange(int $resolution, int $count): string
    {
        $seconds = $count * $resolution;
        if ($seconds < 86400) {
            $format = 'H:i:s';
        } elseif ($seconds < 604800) {
            $format = 'D, H:i:s';
        } elseif ($seconds < 3024000) {
            $format = 'd M';
        } else {
            $format = 'M Y';
        }

        $time = (-1 * $seconds).' seconds';

        $latest = new \DateTime('NOW');
        $oldest = new \DateTime($time);

        $latest = $latest->format($format);
        $oldest = $oldest->format($format);

        $unit = $this->humanReadableTime($resolution * $count);

        return $unit.' ('.$oldest.' - '.$latest.')';
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
