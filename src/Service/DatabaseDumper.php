<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

use Ifsnop\Mysqldump as IMysqldump;

use OpenCloud\OpenStack;
use OpenCloud\Rackspace;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\Rackspace\RackspaceAdapter;

use Psr\Log\LoggerInterface;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class DatabaseDumper
{
    /**
     * The amount of bytes in 64MB
     * @var integer
     */
    const BYTES_64 = 64000000;

    /**
     * @var \Symfony\Component\HttpKernel\KernelInterface
     */
    protected $kernel;

    /**
     * @var \App\Service\Notifier
     */
    protected $notifier;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $mysqlHostname;

    /**
     * @var string
     */
    protected $mysqlDatabase;

    /**
     * @var string
     */
    protected $mysqlUsername;

    /**
     * @var string
     */
    protected $mysqlPassword;

    /**
     * @var string
     */
    protected $openstackUsername;

    /**
     * @var string
     */
    protected $openstackAuth;

    /**
     * @var string
     */
    protected $openstackPassword;

    /**
     * @var string
     */
    protected $openstackTenantId;

    /**
     * @var string
     */
    protected $openstackLocation;

    /**
     * @var string
     */
    protected $openstackContainer;

    /**
     * Number of weeks to keep backups. Default: 2
     * @var int
     */
    protected $keepDurationInWeeks;

    /**
     * Location to store the database dumps relative to the project root
     * @var string
     */
    protected $dumpFolder = 'var/dumps/';

    private $dbh;

    public function __construct(
        KernelInterface $kernel,
        Notifier $notifier,
        LoggerInterface $logger,
        array $mysqlConfig,
        array $openstackConfig,
        int $keepDurationInWeeks
    ) {
        $this->kernel = $kernel;
        $this->notifier = $notifier;
        $this->logger = $logger;
        $this->keepDurationInWeeks = $keepDurationInWeeks;

        $this->setConfigs($mysqlConfig, $openstackConfig);
        $this->configureMysqlConnection();
    }

    /**
     * Handle setting the configs from arrays
     * @param array $mysqlConfig
     * @param array $openstackConfig
     */
    private function setConfigs(array $mysqlConfig, array $openstackConfig)
    {
        $this->mysqlHostname = $mysqlConfig['hostname'];
        $this->mysqlDatabase = $mysqlConfig['database'];
        $this->mysqlUsername = $mysqlConfig['username'];
        $this->mysqlPassword = $mysqlConfig['password'];

        $this->openstackAuth = $openstackConfig['auth'];
        $this->openstackUsername = $openstackConfig['username'];
        $this->openstackPassword = $openstackConfig['password'];
        $this->openstackTenantId = $openstackConfig['tenantId'];
        $this->openstackLocation = $openstackConfig['location'];
        $this->openstackContainer = $openstackConfig['container'];
    }

    /**
     * Configure the MySQL connection using PDO
     */
    private function configureMysqlConnection()
    {
        $this->dbh = new PDO($this->getDsn(), $this->mysqlUsername, $this->mysqlPassword);
    }

    /**
     * Start the MySQL replication slave
     * @return bool
     */
    private function resumeReplication()
    {
        $this->dbh->prepare('START SLAVE;')->execute();

        // Allow 5 seconds for the replication to start
        sleep(5);

        return $this->checkSlaveStatus('Yes');
    }

    /**
     * Stop the MySQL replication slave
     * @return bool
     */
    private function pauseReplication()
    {
        $this->dbh->prepare('STOP SLAVE;')->execute();

        return $this->checkSlaveStatus('No');
    }

    /**
     * [checkSlaveStatus description]
     * @param  string $status What should the current status match. Yes|No
     * @return bool
     */
    public function checkSlaveStatus($status = 'Yes')
    {
        $stmt = $this->dbh->prepare('SHOW SLAVE STATUS;');
        $stmt->execute();
        $slaveStatus = $stmt->fetch(PDO::FETCH_ASSOC);

        return ($slaveStatus['Slave_IO_Running'] === $status && $slaveStatus['Slave_SQL_Running'] === $status);
    }

    /**
     * Return the location to store the database dumps temporarily
     * @return string
     */
    private function getDumpDir()
    {
        $fullPath = $this->kernel->getProjectDir() . '/' . $this->dumpFolder;

        // Check to make sure that the dump folder has been created
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        return $fullPath;
    }

    /**
     * Return the local filesystem adapter
     * @return \League\Flysystem\Filesystem
     */
    private function getLocalFilesystem()
    {
        $adapter = new Local($this->getDumpDir());
        $filesystem = new Filesystem($adapter);

        return $filesystem;
    }

    /**
     * Return the remote filesystem adapter
     * @return \League\Flysystem\Filesystem
     */
    private function getRemoteFilesystem()
    {
        $client = new OpenStack($this->openstackAuth, [
            'username'  => $this->openstackUsername,
            'password'  => $this->openstackPassword,
            'tenantId'  => $this->openstackTenantId,
        ]);

        $store = $client->objectStoreService('swift', $this->openstackLocation);
        $container = $store->getContainer($this->openstackContainer);
        $filesystem = new Filesystem(new RackspaceAdapter($container));

        return $filesystem;
    }

    /**
     * Upload a file from the local filesystem adapter to the remote
     * @param  string $path Relative path of the file being uploaded
     * @return bool
     */
    public function upload($path)
    {
        $this->logger->debug("Starting upload of file: {$path}");

        $local = $this->getLocalFilesystem();
        $remote = $this->getRemoteFilesystem();
        $remotePath = date('Y/m/d_G-i');

        $fileStream = $local->readStream($path);
        $remote->writeStream($remotePath . '.sql.gz', $fileStream);

        $this->logger->debug("Upload complete");

        return true;
    }

    /**
     * Return the DSN of the configured MySQL server
     * @return string
     */
    private function getDsn()
    {
        return "mysql:host={$this->mysqlHostname};dbname={$this->mysqlDatabase}";
    }

    /**
     * Create a fresh new database dump. This will pause and then resume replication
     * @return string|bool Will return the filename or false if unsuccessful
     */
    public function create()
    {
        $this->logger->debug("Creating new dump of the configured database");

        // Puase the replication
        $this->logger->debug("Pausing the MySQL repliction");
        if (true !== $this->pauseReplication()) {
            $this->logger->critical("Unable to pause repliction and the backup has stopped");

            return false;
        };

        $filename = time() . '.sql';
        $path = $this->getDumpDir() . $filename;

        try {
            $this->logger->debug("Starting new database dump");
            $dump = new IMysqldump\Mysqldump(
                $this->getDsn(),
                $this->mysqlUsername,
                $this->mysqlPassword
            );
            $dump->start($path);
        } catch (\Exception $e) {
            $this->logger->critical("Unable to create database dump", [$e]);

            return false;
        }

        $this->logger->debug("Database dump complete");

        // Enable the replication again, continue with the upload even if it fails
        if (true !== $this->resumeReplication()) {
            $this->logger->alert("Unable to resume replication");
        };

        return $filename;
    }

    /**
     * Compress a database dump using gzip
     * @param  string|bool $filename
     * @param  OutputInterface|null $output Provide the OutputInterface to get output progress
     * @return string Returns the filename of the compressed file
     */
    public function compress($filename, OutputInterface $output = null)
    {
        $this->logger->debug("Starting compression of file: {$filename}");

        $local = $this->getLocalFilesystem();
        $fileSize = $local->getSize($filename);
        $fileStream = $local->readStream($filename);
        $compressedFilename = $filename . '.gz';
        $compressedPath = $this->getDumpDir() . $compressedFilename;
        $gzHandle = gzopen($compressedPath, 'w9');

        $progressBar = null;
        if (null !== $output) {
            $progressBar = new ProgressBar($output, $this->intoSteps($fileSize));
            $progressBar->setFormat('[%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% (%remaining:-6s%) %memory:6s%');
            $progressBar->start();
        }

        while ($buffer = fread($fileStream, self::BYTES_64)) {
            if ($progressBar) {
                $progressBar->advance();
            }
            gzwrite($gzHandle, $buffer);
        }

        gzclose($gzHandle);

        if ($progressBar) {
            $progressBar->finish();
        }

        $this->logger->debug("Compression complete. File saved as: {$compressedFilename}");

        return $compressedFilename;
    }

    /**
     * Calculate the number of steps to display the console progress bar
     * @param  int $fileSize
     * @return int Number of steps
     */
    private function intoSteps(int $fileSize)
    {
        $steps = intval(round($fileSize / self::BYTES_64));
        if (0 === $steps) {
            return 1;
        }

        return $steps;
    }

    /**
     * List local dumps
     * @return array
     */
    public function listLocal()
    {
        $filesystem = $this->getLocalFilesystem();

        return $filesystem->listContents();
    }

    /**
     * List database dumps on the remote storage
     * @param  string $month
     * @param  string $year
     * @return array
     */
    public function listRemote($month, $year = null)
    {
        if (null === $year) {
            $year = date('Y');
        }
        $path = "{$year}/{$month}";
        $filesystem = $this->getRemoteFilesystem();

        return $filesystem->listContents($path, true);
    }

    /**
     * Clear up temporary files
     * @return array List of files that have been removed
     */
    public function garbageCollection()
    {
        $this->logger->debug("Running garbage collection");

        $localFilesystem = $this->getLocalFilesystem();
        $remoteFilesystem = $this->getRemoteFilesystem();
        $oldTimestamp = (new \DateTime("-{$this->keepDurationInWeeks} weeks"))->getTimestamp();
        $removed = ['local' => [], 'remote' => []];

        // Remove remote files older than the configured threshold
        foreach ($remoteFilesystem->listContents('', true) as $file) {
            if ($file['type'] === 'dir') {
                continue;
            }

            // If file is older than the configured threshold
            if ($file['timestamp'] < $oldTimestamp) {
                dump($file);

                $removed['remote'][] = $file['path'];
            }
        }

        // Remove local temporary files
        foreach ($localFilesystem->listContents() as $object) {
            $localFilesystem->delete($object['path']);
            $filename = $object['basename'];

            $this->logger->debug("Removed local file {$filename}");
            $removed['local'][] = $filename;
        }

        $removedTotal =  count($removed['local']) + count($removed['remote']);
        $this->logger->debug("Finished garbage collection. Removed a total of {$removedTotal} files", $removed);

        return $removed;
    }
}
