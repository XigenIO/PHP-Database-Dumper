<?php

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

    public function __construct(
        KernelInterface $kernel,
        Notifier $notifier,
        array $mysqlConfig,
        array $openstackConfig
    ) {
        $this->kernel = $kernel;
        $this->notifier = $notifier;
        $this->setConfigs($mysqlConfig, $openstackConfig);
    }

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

    private function getDumpDir()
    {
        $fullPath = $this->kernel->getProjectDir() . '/' . $this->dumpFolder;

        // Check to make sure that the dump folder has been created
        if (!file_exists($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        return $fullPath;
    }

    private function getLocalFilesystem()
    {
        $adapter = new Local($this->getDumpDir());
        $filesystem = new Filesystem($adapter);

        return $filesystem;
    }

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

    public function upload($path)
    {
        $local = $this->getLocalFilesystem();
        $remote = $this->getRemoteFilesystem();
        $remotePath = date('Y/m/d_G-i');

        $fileStream = $local->readStream($path);
        $remote->writeStream($remotePath . '.sql', $fileStream);

        return true;
    }

    private function getDsn()
    {
        return "mysql:host={$this->mysqlHostname};dbname={$this->mysqlDatabase}";
    }

    public function create()
    {
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

        return $filename;
    }

    /**
     * Compress a database dump to
     * @param  string $path
     * @param  OutputInterface $output
     * @return boolean
     */
    public function compress($path, OutputInterface $output = null)
    {
        $local = $this->getLocalFilesystem();
        $fileSize = $local->getSize($path);
        $fileStream = $local->readStream($path);
        $compressedName = $path . '.gz';
        $gzHandle = gzopen($compressedName, 'w9');

        $progressBar = null;
        if (null !== $output) {
            $progressBar = new ProgressBar($output, ($fileSize / self::BYTES_64));
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

        return $compressedName;
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
