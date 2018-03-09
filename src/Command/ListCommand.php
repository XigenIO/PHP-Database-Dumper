<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\DatabaseDumper;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

use Carbon\Carbon;

class ListCommand extends BaseCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:list')
            ->setDescription('List the backups stored remotely')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = $this->getHelper('question');
        $question = new ChoiceQuestion(
            'Please select a month to view. Default: <info>' . date('F') . '</info>',
            $this->getMonths(),
            date('m')
        );
        $listMonth = $helper->ask($input, $output, $question);

        $output->writeln('Fetching backups for that month (this can take a while)');
        $output->writeln('');

        $list = $this->databaseDumper->listRemote($listMonth);

        if ([] === $list) {
            $output->writeln('<error>There are no backups avalible for that month</error>');

            return 0;
        }

        $rows = [];
        foreach ($this->databaseDumper->listRemote($listMonth) as $object) {
            if ('file' === $object['type']) {
                $rows[] = [
                    $object['basename'],
                    Carbon::createFromTimestamp($object['timestamp'])->toDateTimeString(),
                    $this->toReadableSize($object['size']),
                ];
            }
        }

        (new Table($output))
            ->setHeaders(array('Filename', 'Created', 'Size'))
            ->setRows($rows)
            ->render()
        ;
    }

    /**
     * Return months of the year
     * @return array
     */
    private function getMonths()
    {
        return [
            '01' => 'January',
            '02' => 'February',
            '03' => 'March',
            '04' => 'April',
            '05' => 'May',
            '06' => 'June',
            '07' => 'July',
            '08' => 'August',
            '09' => 'September',
            '10' => 'October',
            '11' => 'November',
            '12' => 'December'
        ];
    }

    /**
     * Format file size to a human readable format
     * @link http://jeffreysambells.com/2012/10/25/human-readable-filesize-php
     * @param  integer $bytes
     * @param  integer $decimals
     * @return string
     */
    protected function toReadableSize($bytes, $decimals = 2)
    {
        $size = ['B','kB','MB','GB','TB','PB'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
    }
}
