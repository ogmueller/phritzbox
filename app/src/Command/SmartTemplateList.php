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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * A console command to list all SmartHome templates defined on the Fritz!Box.
 *
 * To use this command, open a terminal window, enter into your project
 * directory and execute the following:
 *
 *     $ php bin/console smart:template:list
 *
 * To print only the template identifiers (one per line), add --simple:
 *
 *     $ php bin/console smart:template:list --simple
 *
 * @author Oliver G. Mueller <oliver@teqneers.de>
 */
#[AsCommand(name: 'smart:template:list', description: 'List all available SmartHome templates')]
class SmartTemplateList extends Smart
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
        $templateList = $this->ahaApi->getTemplateListInfos();

        $rows = [];
        foreach ($templateList->template as $template) {
            $devices = [];
            foreach ($template->devices->device ?? [] as $device) {
                $devices[] = (string) $device['identifier'];
            }

            $rows[] = [
                (string) $template['identifier'],
                (string) $template['id'],
                self::templateName($template),
                \count($devices) > 0 ? implode(', ', $devices) : '-',
            ];
        }

        if ($rows === []) {
            $errOutput->writeln('No templates available');

            return 1;
        }

        if ($simpleOutput) {
            foreach ($rows as $row) {
                $this->io->writeln($row[0]);
            }

            return 0;
        }

        $table = new Table($output);
        $table->setHeaders(['Identifier', 'ID', 'Name', 'Devices']);
        $table->addRows($rows);
        $table->render();

        return 0;
    }

    /**
     * FRITZ!OS carries the template name in a <name> child element; some
     * firmware revisions expose it as a `name` attribute instead. Prefer the
     * element and fall back, so both shapes render.
     */
    private static function templateName(\SimpleXMLElement $template): string
    {
        $child = (string) $template->name;

        return $child !== '' ? $child : (string) $template['name'];
    }

    /**
     * The command help is usually included in the configure() method, but when
     * it's too long, it's better to define a separate method to maintain the
     * code readability.
     */
    private function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command lists the SmartHome templates stored on
the Fritz!Box, with the devices each one applies to:

  <info>php %command.full_name%</info>

To print only the template identifiers, one per line (useful for scripting),
add the <comment>--simple</comment> option:

  <info>php %command.full_name%</info> <comment>--simple</comment>

HELP;
    }
}
