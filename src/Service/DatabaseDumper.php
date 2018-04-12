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

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

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
     * Location to store the database dumps relative to the project root
     * @var string
     */
    protected $dumpFolder = 'var/dumps/';

    private $dbh;

    public function __construct(
        KernelInterface $kernel,
        Notifier $notifier,
        array $mysqlConfig,
        array $openstackConfig
    ) {
        $this->kernel = $kernel;
        $this->notifier = $notifier;
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
        $local = $this->getLocalFilesystem();
        $remote = $this->getRemoteFilesystem();
        $remotePath = date('Y/m/d_G-i');

        $fileStream = $local->readStream($path);
        $remote->writeStream($remotePath . '.sql.gz', $fileStream);

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
        // Puase the replication
        if (true !== $this->pauseReplication()) {
            dump('unable to pause repliction');
            return false;
        };

        $filename = time() . '.sql';
        $path = $this->getDumpDir() . $filename;

        try {
            $dump = new IMysqldump\Mysqldump(
                $this->getDsn(),
                $this->mysqlUsername,
                $this->mysqlPassword
            );
            $dump->start($path);
        } catch (\Exception $e) {
            dump($e->getMessage());
            return false;
        }

        // Enable the replication again
        if (true !== $this->resumeReplication()) {
            dump('unable to pause repliction');
            return false;
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
        $filesystem = $this->getLocalFilesystem();

        $removed = [];
        foreach ($filesystem->listContents() as $object) {
            $filesystem->delete($object['path']);
            $removed[] = $object['basename'];
        }

        return $removed;
    }
}
