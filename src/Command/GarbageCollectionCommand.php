<?php

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GarbageCollectionCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('app:garbage-collection')
            ->setDescription('Run the garbage collection process')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $removed = $this->databaseDumper->gc();
        if (false !== $removed) {
            foreach ($removed as $file) {
                $output->writeln("Removed file <info>{$file}</info>");
            }
        }
    }
}
