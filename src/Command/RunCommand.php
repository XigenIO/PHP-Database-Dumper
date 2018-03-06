<?php

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('app:run')
            ->setDescription('...')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Creating dump");
        $path = $this->databaseDumper->create();

        $output->writeln("Upload dump");
        $this->databaseDumper->upload($path);

        $output->writeln("Removing dump locally");
        $this->databaseDumper->garbageCollection();
    }
}
