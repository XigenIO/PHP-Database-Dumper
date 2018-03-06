<?php

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command
{
    /**
     * @var \App\Service\DatabaseDumper
     */
    protected $databaseDumper;

    public function __construct(DatabaseDumper $databaseDumper)
    {
        parent::__construct();
        $this->databaseDumper = $databaseDumper;
    }
}
