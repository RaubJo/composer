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
 * @author Joseph Raub <josephraub@proton.me>
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
     * Clone a radicle repo to a directory.
     *
     * @param string $rid  The Radicle Repository ID. (RID)
     * @param string  $dir  The Cache directory to clone into.
     * @param null|array $seeds  The specified seed nodes to clone from.
     * 
     * @return boolean
     */
    public function clone(string $rid, string $dir, array $seeds = []): bool
    {
        if (!empty($seeds)) {
            $seeds = implode(' ', array_map(fn($seed) => sprintf('--seed %s', $seed), $seeds));
        }

        $rid = $this->parseRid($rid);

        $this->process->execute(trim(sprintf('rad clone %s %s %s', $rid, $dir, $seeds ?: '')), $output);

        return $this->processOutput($output);
    }

    /**
     * Sync a radicle node with the rad network potentially fetching new state.
     *
     * @param string $rid
     * @param string $cwd
     * @return boolean
     */
    public function sync(string $rid, string $dir, array $seeds = []): bool
    {
        if (!empty($seeds)) {
            $seeds = implode(' ', array_map(fn($seed) => sprintf('--seed %s', $seed), $seeds));
        }

        $this->process->execute(trim(sprintf('rad sync --fetch %s %s %s', $rid, $dir, $seeds ?: '')), $output, $dir);

        return $this->processOutput($output);
    }

    /**
    * Parse the rid and return the id without the 'rid:' prefix
     */
    protected function parseRid(string $rid): string
    {
        $parts = explode('rid:', $rid);

        return (string) end($parts);
    }

    /**
     * Get repository information.
     *
     * @param string $rid
     * @return array
     */
    public function getInfo(string $rid): array
    {
        $rid = $this->parseRid($rid);

        $this->process->execute(sprintf('rad inspect --payload %s', $rid), $output);

        return json_decode($output, true);
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
        return  $this->getInfo($rid)['xyz.radicle.project']['defaultBranch'];
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
