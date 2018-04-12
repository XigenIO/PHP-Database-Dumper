<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GarbageCollectionCommand extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:garbage-collection')
            ->setAliases(['app:gc'])
            ->setDescription('Run the garbage collection process to remove old files')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $removed = $this->databaseDumper->garbageCollection();
        foreach ($removed['local'] as $file) {
            $output->writeln("Removed local file <info>{$file}</info>");
        }

        foreach ($removed['remote'] as $file) {
            $output->writeln("Removed remote file <info>{$file}</info>");
        }

        return 0;
    }
}
