<?php declare(strict_types=1);

namespace Composer\Downloader;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Composer\Util\Radicle;
use Composer\Util\ProcessExecutor;
use React\Promise\PromiseInterface;

/**
 * @author Joseph Raub <josephraub@proton.me>
 */
class RadicleDownloader extends GitDownloader implements DvcsDownloaderInterface
{
    /**
     * @var Radicle
     */
    protected $radUtil;

    public function __construct(IOInterface $io, Config $config, ?ProcessExecutor $process = null, ?Filesystem $fs = null)
    {
        parent::__construct($io, $config, $process, $fs);
        $this->radUtil = new Radicle($this->io, $this->config, $this->process, $this->filesystem);
    }

    /**
     * @inheritDoc
     */
    protected function doDownload(PackageInterface $package, string $path, string $url, ?PackageInterface $prevPackage = null): PromiseInterface
    {
        // Satisfy the abstract parent.

        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doInstall(PackageInterface $package, string $path, string $url): PromiseInterface
    {
        $this->radUtil->clone($url, $path, []);
        
        return \React\Promise\resolve(null);
    }

    /**
     * @inheritDoc
     */
    protected function doUpdate(PackageInterface $initial, PackageInterface $target, string $path, string $url): PromiseInterface
    {
        $this->radUtil->sync($url, $path, []);

        return \React\Promise\resolve(null);
    }
}
