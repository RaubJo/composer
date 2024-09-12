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

namespace Composer\Repository\Vcs;

use Composer\Cache;
use Composer\Config;
use Composer\Pcre\Preg;
use Composer\Json\JsonFile;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Radicle as RadicleUtil;

/**
 * @author Joseph Raub <josephraub@proton.me>
 */
class RadicleDriver extends VcsDriver
{
    /** @var array<int|string, string> Map of tag name (can be turned to an int by php if it is a numeric name) to identifier */
    protected $tags;
    /** @var array<int|string, string> Map of branch name (can be turned to an int by php if it is a numeric name) to identifier */
    protected $branches;
    /** @var string */
    protected $rootIdentifier;
    /** @var string */
    protected $repoDir;
    /** @var string */
    protected $name;
    /** @var string */
    protected $rid;


    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        $fs = new Filesystem();
        $radicle = new RadicleUtil($this->io, $this->config, $this->process, $fs);
        $this->rid = $this->url;

        if (!Cache::isUsable($this->config->get('cache-vcs-dir'))) {
            throw new \RuntimeException('RadicleDriver requires a usable cache directory, and it looks like you set it to be disabled');
        }

        $this->repoDir = $this->config->get('cache-vcs-dir') . '/' . Preg::replace('{[^a-z0-9.]}i', '-', $this->url) . '/';

        if ($fs->ensureDirectoryExists(dirname($this->repoDir)) && !is_writable(dirname($this->repoDir))) {
            throw new \RuntimeException('Can not clone '.$this->url.' to access package information. The "'.dirname($this->repoDir).'" directory is not writable by the current user.');
        }

        if(!is_dir($this->repoDir)) {
            mkdir($this->repoDir);
        }

        if($fs->isDirEmpty($this->repoDir)) {
            $radicle->clone($this->url, $this->repoDir);
        } else {
            if (!$radicle->sync($this->url, $this->repoDir)) {
                if (!is_dir($this->repoDir)) {
                    throw new \RuntimeException('Failed to clone '.$this->url.' to read package information from it');
                }

                $this->io->writeError('<error>Failed to update '.$this->url.', package information from this repository may be outdated</error>');
            }
        }

        $this->getTags();
        $this->getBranches();

        $this->cache = new Cache($this->io, $this->config->get('cache-repo-dir').'/'.Preg::replace('{[^a-z0-9.]}i', '-', $this->url));
        $this->cache->setReadOnly($this->config->get('cache-read-only'));

       
        $this->url = $this->repoDir.'/'.$radicle->getName($this->rid);
    }

    /**
     * @inheritDoc
     */
    public function getFileContent(string $file, string $identifier): ?string
    {
        if (isset($identifier[0]) && $identifier[0] === '-') {
            throw new \RuntimeException('Invalid git identifier detected. Identifier must not start with a -, given: ' . $identifier);
        }

        $resource = sprintf('%s:%s', 'master', $file);
        $this->process->execute(sprintf('git show %s', $resource), $content, $this->getPath());

        if (trim($content) === '') {
            return null;
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        $this->process->execute(sprintf(
            'git -c log.showSignature=false log -1 --format=%%at %s',
            ProcessExecutor::escape($identifier)
        ), $output, $this->getPath());

        return new \DateTimeImmutable('@'.trim($output), new \DateTimeZone('UTC'));
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier(): string
    {
        if (null === $this->rootIdentifier) {

            $radicle = new RadicleUtil($this->io, $this->config, $this->process, new Filesystem());

            return  $this->rootIdentifier = $radicle->getDefaultBranch($this->rid);
        }

        return $this->rootIdentifier;
    }

    /**
     * Get the vcs cache path.
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->config->get('cache-vcs-dir') . '/' . Preg::replace('{[^a-z0-9.]}i', '-', $this->rid) . '/';
    }

    /**
     * @inheritDoc
     */
    public function getBranches(): array
    {
        if (null === $this->branches) {
            $branches = [];
            $this->process->execute('git branch --no-color --no-abbrev -v', $output, $this->getPath());
            foreach ($this->process->splitLines($output) as $branch) {
                if ($branch !== '' && !Preg::isMatch('{^ *[^/]+/HEAD }', $branch)) {
                    if (Preg::isMatchStrictGroups('{^(?:\* )? *(\S+) *([a-f0-9]+)(?: .*)?$}', $branch, $match) && $match[1][0] !== '-') {
                        $branches[$match[1]] = $match[2];
                    }
                }
            }

            $this->branches = $branches;
        }

        return $this->branches;
    }

    /**
     * @inheritDoc
     */
    public function getTags(): array
    {
        if (null === $this->tags) {
            $this->tags = [];

            $this->process->execute('git show-ref --tags --dereference', $output, $this->getPath());
            foreach ($this->process->splitLines($output) as $tag) {
                if ($tag !== '' && Preg::isMatch('{^([a-f0-9]{40}) refs/tags/(\S+?)(\^\{\})?$}', $tag, $match)) {
                    $this->tags[$match[2]] = $match[1];
                }
            }
        }

        return $this->tags;
    }

    /**
     * @inheritDoc
     */
    public function getDist(string $identifier): ?array
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSource(string $identifier): array
    {
        return ['type' => 'radicle', 'url' => $this->getUrl(), 'reference' => $identifier];
    }

    /**
     * @inheritDoc
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function hasComposerFile(string $identifier): bool
    {
        $radicle = new RadicleUtil($this->io, $this->config, $this->process, new Filesystem());

        return is_file($this->getPath().$radicle->getName($this->rid).'/composer.json');
    }

    /**
     * @inheritDoc
     */
    public function cleanup(): void
    {
        if(!is_dir($this->repoDir)) {
            rmdir($this->repoDir);
        }
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        return Preg::isMatch('/rad:.+/', $url);
    }

}
