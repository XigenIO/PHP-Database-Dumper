<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\DatabaseDumper;

use Psr\Log\LoggerInterface;

use Symfony\Component\Console\Command\Command;

abstract class BaseCommand extends Command
{
    /**
     * @var \App\Service\DatabaseDumper
     */
    protected $databaseDumper;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * {@inheritdoc}
     */
    public function __construct(
        DatabaseDumper $databaseDumper,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->databaseDumper = $databaseDumper;
        $this->logger = $logger;
    }
}
