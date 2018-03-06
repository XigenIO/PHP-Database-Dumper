<?php

namespace App\Service;

use Ifsnop\Mysqldump as IMysqldump;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local;

class DatabaseDumper
{
    protected $kernel;

    protected $hostname = 'kestrel.xigenhosting.co.uk';

    protected $database = 'signatur_prod';

    protected $username = 'signatur_prod';

    protected $password = 'g&cbNl?~x1;iI*fr0G';

    protected $dumpFolder = 'var/dumps/';

    public function __construct(\Symfony\Component\HttpKernel\KernelInterface $kernel)
    {
        $this->kernel = $kernel;
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

    private function getFilesystem()
    {
        $adapter = new Local($this->getDumpDir());
        $filesystem = new Filesystem($adapter);

        return $filesystem;
    }

    public function create()
    {
        $filename = time() . '.sql';

        try {
            $dump = new IMysqldump\Mysqldump($this->getDsn(), $this->username, $this->password);
            $dump->start($this->getDumpDir() . $filename);
        } catch (\Exception $e) {
            dump($e->getMessage());
            return false;
        }

        return true;
    }

    private function getDsn()
    {
        return "mysql:host={$this->hostname};dbname={$this->database}";
    }

    /**
     * List local dumps
     * @return array
     */
    public function listLocal()
    {
        $filesystem = $this->getFilesystem();

        return $filesystem->listContents();
    }

    public function gc()
    {
        $filesystem = $this->getFilesystem();

        $removed = [];
        foreach ($filesystem->listContents() as $object) {
            $filesystem->delete($object['path']);
            $removed[] = $object['basename'];
        }

        if ($removed === []) {
            return false;
        }

        return $removed;
    }
}
