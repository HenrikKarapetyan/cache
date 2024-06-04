<?php

namespace Henrik\Cache\Adapters;

use FilesystemIterator;
use Generator;
use Henrik\Cache\AbstractCachePool;
use Henrik\Cache\CacheItem;
use Henrik\Cache\EmptyCacheItem;
use Henrik\Cache\Exception\InvalidArgumentException;
use Henrik\Cache\Interfaces\BaseCacheItemInterface;
use Henrik\Contracts\Filesystem\FileSystemExceptionInterface;
use Henrik\Filesystem\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class FileCachePool extends AbstractCachePool
{
    /**
     * @var string
     */
    private string $cachePath;

    /**
     * @var int
     */
    private int $defaultTtl;

    /**
     * @var int
     */
    private int $dirMode;

    /**
     * @var int
     */
    private int $fileMode;

    /**
     * @param string $cachePath  absolute root path of cache-file folder
     * @param int    $defaultTtl default time-to-live (in seconds)
     * @param int    $dirMode    permission mode for created dirs
     * @param int    $fileMode   permission mode for created files
     *
     * @throws InvalidArgumentException|FileSystemExceptionInterface
     */
    public function __construct(string $cachePath, int $defaultTtl, int $dirMode = 0o775, int $fileMode = 0o664)
    {
        $this->defaultTtl = $defaultTtl;
        $this->dirMode    = $dirMode;
        $this->fileMode   = $fileMode;

        if (!file_exists($cachePath) && file_exists(dirname($cachePath))) {
            Filesystem::mkdir($cachePath, $this->dirMode); // ensure that the parent path exists
        }

        $path = realpath($cachePath);

        if ($path === false) {
            throw new InvalidArgumentException("cache path does not exist: {$cachePath}");
        }

        if (!is_writable($path . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("cache path is not writable: {$cachePath}");
        }

        $this->cachePath = $path;
    }

    public function increment($key, $step = 1)
    {
        $path = $this->getPath($key);

        $dir = dirname($path);

        if (!file_exists($dir)) {
            Filesystem::mkdir($dir); // ensure that the parent path exists
        }

        $lock_path = $dir . DIRECTORY_SEPARATOR . '.lock'; // allows max. 256 client locks at one time

        $lock_handle = fopen($lock_path, 'w');

        flock($lock_handle, LOCK_EX);

        $value = $this->get($key, 0) + $step;

        $ok = $this->set($key, $value);

        flock($lock_handle, LOCK_UN);

        return $ok ? $value : false;
    }

    public function decrement($key, $step = 1)
    {
        return $this->increment($key, -$step);
    }

    /**
     * Clean up expired cache-files.
     *
     * This method is outside the scope of the PSR-16 cache concept, and is specific to
     * this implementation, being a file-cache.
     *
     * In scenarios with dynamic keys (such as Session IDs) you should call this method
     * periodically - for example from a scheduled daily cron-job.
     *
     * @return void
     */
    public function cleanExpired(): void
    {
        $now = $this->getTime();

        $paths = $this->listPaths();

        foreach ($paths as $path) {
            if ($now > filemtime($path)) {
                unlink($path);
            }
        }
    }

    /**
     * For a given cache key, obtain the absolute file path.
     *
     * @param string $key
     *
     * @throws InvalidArgumentException if the specified key contains a character reserved by PSR-16
     *
     * @return string absolute path to cache-file
     */
    protected function getPath(string $key)
    {
        $this->validateKey($key);

        $hash = hash('sha256', $key);

        return $this->cachePath
            . DIRECTORY_SEPARATOR
            . strtoupper($hash[0])
            . DIRECTORY_SEPARATOR
            . strtoupper($hash[1])
            . DIRECTORY_SEPARATOR
            . substr($hash, 2);
    }

    /**
     * @return int current timestamp
     */
    protected function getTime(): int
    {
        return time();
    }

    /**
     * @return Generator|string[]
     */
    protected function listPaths(): array|Generator
    {
        $iterator = new RecursiveDirectoryIterator(
            $this->cachePath,
            FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS
        );

        $iterator = new RecursiveIteratorIterator($iterator);

        foreach ($iterator as $path) {
            if (is_dir($path)) {
                continue; // ignore directories
            }

            yield $path;
        }
    }

    protected function storeItemInCache(BaseCacheItemInterface $item, ?int $ttl): bool
    {
        $key   = $item->getKey();
        $value = $item->get();
        $path  = $this->getPath($key);

        $dir = dirname($path);

        if (!file_exists($dir)) {
            // ensure that the parent path exists:
            Filesystem::mkdir($dir);
        }

        $temp_path = $this->cachePath . DIRECTORY_SEPARATOR . uniqid('', true);

        if (is_int($ttl)) {
            $expires_at = $this->getTime() + $ttl;
        } elseif ($ttl === null) {
            $expires_at = $this->getTime() + $this->defaultTtl;
        } else {
            throw new InvalidArgumentException('invalid TTL: ' . print_r($ttl, true));
        }

        if (file_put_contents($temp_path, serialize($value)) === false) {
            return false;
        }

        if (chmod($temp_path, $this->fileMode) === false) {
            return false;
        }

        if (touch($temp_path, $expires_at) && rename($temp_path, $path)) {
            return true;
        }

        unlink($temp_path);

        return false;
    }

    protected function fetchObjectFromCache(string $key): BaseCacheItemInterface
    {
        $path = $this->getPath($key);

        $expires_at = filemtime($path);

        if ($expires_at === false) {
            return new EmptyCacheItem($key, null);
        }

        if ($this->getTime() >= $expires_at) {
            unlink($path); // file expired

            return new EmptyCacheItem($key, null);

        }

        $data = file_get_contents($path);

        if ($data === false) {
            return new EmptyCacheItem($key, null);
            // race condition: file not found
        }

        if ($data === 'b:0;') {
            return new EmptyCacheItem($key, null);
            // because we can't otherwise distinguish a FALSE return-value from unserialize()
        }

        $value = unserialize($data);

        if ($value === false) {
            return new EmptyCacheItem($key, null);  // unserialize() failed
        }

        // TODO we must add expiration date into cache
        //        return new CacheItem($key,$value, $expires_at);
        return new CacheItem($key, $value);
    }

    protected function clearAllObjectsFromCache(): bool
    {
        $success = true;

        $paths = $this->listPaths();

        foreach ($paths as $path) {
            if (!unlink($path)) {
                $success = false;
            }
        }

        return $success;
    }

    protected function clearOneObjectFromCache(string $key): bool
    {
        $path = $this->getPath($key);

        return !file_exists($path) || @unlink($path);
    }

    protected function getList(string $name): array
    {
        return [];
    }

    protected function removeList(string $name): bool
    {
        return true;
    }

    protected function appendListItem(string $name, string $key): void
    {
        // TODO: Implement appendListItem() method.
    }

    protected function removeListItem(string $name, string $key): void
    {
        // TODO: Implement removeListItem() method.
    }
}