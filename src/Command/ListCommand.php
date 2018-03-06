<?php

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

use Carbon\Carbon;

class ListCommand extends BaseCommand
{
    protected function configure()
    {
        $this->setName('app:list')
            ->setDescription('...')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $rows = [];
        foreach ($this->databaseDumper->listLocal() as $object) {
            if ('file' === $object['type']) {
                $rows[] = [
                    $object['basename'],
                    Carbon::createFromTimestamp($object['timestamp'])->toDateTimeString(),
                    $this->toReadableSize($object['size']),
                ];
            }
        }

        $table = (new Table($output))
            ->setHeaders(array('Filename', 'Created', 'Size'))
            ->setRows($rows)
            ->render()
        ;
    }

    protected function toReadableSize($bytes, $decimals = 2)
    {
        $size = ['B','kB','MB','GB','TB','PB','EB','ZB','YB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }
}
