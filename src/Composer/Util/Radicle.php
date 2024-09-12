<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Radicle
{
    /** @var string|false|null */
    private static $version = false;

    /** @var IOInterface */
    protected $io;
    /** @var Config */
    protected $config;
    /** @var ProcessExecutor */
    protected $process;
    /** @var Filesystem */
    protected $filesystem;
    /** @var string */
    public $name;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process, Filesystem $fs)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process;
        $this->filesystem = $fs;
    }

    /**
     * Clone a radicle repo to $cwd
     *
     * @param string $rid
     * @param string  $cwd
     * @return boolean
     */
    public function clone(string $rid, string $cwd): bool
    {
        $this->process->execute(sprintf('rad clone %s', $rid), $output, $cwd);

        return $this->processOutput($output);
    }

    /**
     * Sync a radicle node with the rad network.
     *
     * @param string $rid
     * @param string $cwd
     * @return boolean
     */
    public function sync(string $rid, string $cwd): bool
    {
        $this->process->execute('rad sync', $output, $cwd.$this->getName($rid));

        return $this->processOutput($output);
    }

    /**
     * Get repository information.
     *
     * @param string $rid
     * @return array
     */
    public function getInfo(string $rid): array
    {
        $this->process->execute(sprintf('rad inspect --payload %s', $rid), $output);
        $j= json_decode($output, true);
        // var_dump($j);
        return $j;

    }

    /**
     * Get the name of the radicle project.
     *
     * @param string $rid
     * @return string
     */
    public function getName(string $rid): string
    {
        return  $this->getInfo($rid)['xyz.radicle.project']['name'];
    }

    /**
     * Get the default branch of the radicle project.
     *
     * @param string $rid
     * @return string
     */
    public function getDefaultBranch(string $rid): string
    {
        return  $this->getInfo($rid)['xyz.radicle.project']['name'];
    }

    /**
     * Process command output for errors.
     *
     * @param string $output
     * @return boolean|null
     */
    protected function processOutput(string $output): ?bool
    {
        // This means that all nodes are already synced.
        if(Preg::match('/Error: all seeds timed out/', $output, $matches)) {
            return true;
        }

        // if(Preg::match('/Error: (\w.+)/', $output, $matches)) {
        //     $this->throwException(ucfirst($matches[1]));
        //     return false;
        // }

        return true;
    }

    /**
     * Throw an exception.
     * 
     * @param non-empty-string $message
     * @throws RuntimeException
     * @return void
     */
    protected function throwException($message): void
    {
        // git might delete a directory when it fails and php will not know
        clearstatcache();

        if (0 !== $this->process->execute('rad --version', $ignoredOutput)) {
            throw new \RuntimeException(Url::sanitize('Failed to clone. rad was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput()));
        }

        throw new \RuntimeException(Url::sanitize($message));
    }

    /**
     * Get the current rad version.
     *
     * @return string|null The rad version number, if present.
     */
    public static function getVersion(ProcessExecutor $process): ?string
    {
        if (false === self::$version) {
            self::$version = null;
            if (0 === $process->execute('rad --version', $output) && Preg::isMatch('/^rad (\d+(?:\.\d+)+)/m', $output, $matches)) {
                self::$version = $matches[1];
            }
        }

        return self::$version;
    }

}
