<?php

namespace App\Service;

use Ifsnop\Mysqldump as IMysqldump;

use OpenCloud\OpenStack;
use OpenCloud\Rackspace;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use League\Flysystem\Rackspace\RackspaceAdapter;

use Symfony\Component\HttpKernel\KernelInterface;

class DatabaseDumper
{
    /**
     * @var \Symfony\Component\HttpKernel\KernelInterface
     */
    protected $kernel;

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
        array $mysqlConfig,
        array $openstackConfig
    ) {
        $this->kernel = $kernel;
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

    private function getDsn()
    {
        return "mysql:host={$this->mysqlHostname};dbname={$this->mysqlDatabase}";
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
