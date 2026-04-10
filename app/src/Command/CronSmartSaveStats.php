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
use App\Entity\SmartDeviceData;
use noximo\PHPColoredAsciiLinechart\Linechart;
use noximo\PHPColoredAsciiLinechart\Settings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to store all stats from all available devices.
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'cron:smart:savestats', description: 'Collect and store all stats from all available devices')]
class CronSmartSaveStats extends Smart
{
    protected function configure(): void
    {
        $this->setHelp($this->getCommandHelp());
    }

    protected function executeSmart(
        InputInterface $input,
        OutputInterface $output,
        OutputInterface $errOutput,
        Stopwatch $stopwatch,
    ): int {
        $devices = $this->ahaApi->getDeviceListInfos();
        $now = new \DateTimeImmutable();

        /** @var Device $device */
        foreach ($devices as $device) {
            $stats = $this->ahaApi->getBasicDeviceStats($device->getIdentifier());

            $output->isVerbose() && $this->io->writeln("\nDevice ".$device->getName().' ['.$device->getIdentifier().']');

            foreach ($stats as $category => $statsList) {
                if (\count($statsList) > 1) {
                    // find values with shortest interval
                    $tmp = 0;
                    foreach ($statsList as $key => $data) {
                        if (empty($tmp) || $data['interval'] < $tmp) {
                            $tmp = $data['interval'];
                            $index = $key;
                        }
                    }
                } else {
                    $index = 0;
                }
                $data = $statsList[$index];
                $intervalSeconds = $data['interval'];

                // calculate current interval starting point
                // go to the beginning of the last full time slot
                $seconds = (int) $now->format('U');
                $back = $intervalSeconds + $seconds % $intervalSeconds;
                $end = $now->modify('-'.$back.' seconds');
                $start = $end->modify('-'.($intervalSeconds * ($data['count'] - 1)).' seconds');

                // get last data point of each device and category
                $lastRaw = $this->entityManager->createQueryBuilder()
                                               ->select('d.type, max(d.time) as last')
                                               ->from(SmartDeviceData::class, 'd')
                                               ->where('d.sid = :sid')
                                               ->addGroupBy('d.type')
                                               ->setParameter('sid', $device->getIdentifier())
                                               ->getQuery()
                                               ->getArrayResult();

                $last = array_column($lastRaw, null, 'type');

                $step = new \DateInterval('PT'.$intervalSeconds.'S');

                $sdd = new SmartDeviceData();
                $sdd->setType($category);
                $sdd->setSid($device->getIdentifier());

                $count = 0;
                foreach (array_reverse($data['values']) as $value) {
                    // only save newer data points
                    if (empty($last[$category]) || $start->format('Y-m-d H:i:s') > $last[$category]['last']) {
                        $insert = clone $sdd;
                        $insert->setTime($start);
                        $insert->setValue($value);
                        $this->entityManager->persist($insert);
                        ++$count;
                    }

                    // next interval
                    $start = $start->add($step);
                }
                $this->entityManager->flush();
                $output->isVerbose() && $this->io->writeln('- saved '.$count.' new '.$category.' entries');
            }
        }

        return 0;
    }

    protected function createChart(array $values, int $factor, Settings $settings): array
    {
        $terminalWidth = getenv('COLUMNS');
        $chartWidth = $terminalWidth - $settings->getOffset() - 6;
        $maxXScaleHeight = 20;

        if (\count($values) > $chartWidth) {
            // get most current values printable at given console width
            $values = \array_slice($values, 0, $chartWidth);
        }

        if ($factor !== 0) {
            // convert values to best readable unit
            $values = array_map(
                static function ($value) use ($factor) {
                    return $value / $factor;
                },
                $values
            );
        }

        $chart = new Linechart();
        $height = ceil(max($values)) - floor(min($values));
        $settings->setHeight(max(1, min($maxXScaleHeight, $height)));
        $chart->setSettings($settings);

        return [$chart, $values];
    }

    /**
     * Convert seconds into e.g. 5days 3h 40sec.
     *
     * @param int $value Seconds
     */
    protected function humanReadableTime(int $value): string
    {
        $prefixList = [
            'yr' => 32140800,
            'mo' => 2678400,
            'wk' => 604800,
            'd' => 86400,
            'hr' => 3600,
            'min' => 60,
            'sec' => 1,
        ];

        $ret = [];
        foreach ($prefixList as $prefix => $factor) {
            $mod = $value % $factor;
            $value /= $factor;
            if ($value >= 1) {
                $ret[] = floor($value).$prefix;
            }
            $value = $mod;
        }

        return implode(' ', $ret);
    }

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

        $latest = (new \DateTimeImmutable('NOW'))->format($format);
        $oldest = (new \DateTimeImmutable($time))->format($format);

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
The <info>%command.name%</info> command shows basic information of a SmartHome device:

  <info>php %command.full_name%</info> <comment>ain</comment>

By default the command will output show graphs depending on the features of the device (e.g. temperature, power, energy, ...).
Each graph has a headline with the type of information and its unit as well as the time range and interval it represents.
The line from left to right is from older to newer values.

You can also use the <comment>-s</comment> option to get a simplified output. In this case the information will be shown
as comma seperated values (CSV):

  # command will simplify output
  <info>php %command.full_name%</info> <comment>-s</comment> <comment>ain</comment>

The CSVs will always start with the following information: name, unit , number of values, time interval.
After that all the values will be appended in a descending time order (latest first, oldest last).

HELP;
    }
}
