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
        $output->writeln('Creating dump');
        $filename = $this->databaseDumper->create();

        $output->writeln('Compressing dump');
        $output->writeln('');
        $filename = $this->databaseDumper->compress($filename, $output);

        $output->writeln('Uploading dump');
        $this->databaseDumper->upload($filename);

        $output->writeln('Running GC');
        $this->databaseDumper->garbageCollection();
    }
}
