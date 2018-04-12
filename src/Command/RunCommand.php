<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:run')
            ->setDescription('...')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Creating dump');
        $filename = $this->databaseDumper->create();

        if (false === $filename) {
            $output->writeln('<error>Unable to create dump</error>');
            $output->writeln('Check the logs for more info');

            return 1;
        }

        $output->writeln('Compressing dump');
        $filename = $this->databaseDumper->compress($filename, $output);

        $output->writeln('');
        $output->writeln('');
        $output->writeln('Uploading dump');
        $this->databaseDumper->upload($filename);

        $output->writeln('Running GC');
        $this->databaseDumper->garbageCollection();

        return 0;
    }
}
