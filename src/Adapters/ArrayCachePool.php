<?php

namespace Henrik\Cache\Adapters;

use Henrik\Cache\AbstractCachePool;
use Henrik\Cache\CacheItem;
use Henrik\Cache\EmptyCacheItem;
use Henrik\Cache\Interfaces\BaseCacheItemInterface;

class ArrayCachePool extends AbstractCachePool
{
    /**
     * @var BaseCacheItemInterface[]
     */
    private array $cache;

    /**
     * @var array<int, string> A map to hold keys
     */
    private array $keyMap = [];

    /**
     * @var ?int The maximum number of keys in the map
     */
    private ?int $limit;

    /**
     * @var int The next key that we should remove from the cache
     */
    private int $currentPosition = 0;

    /**
     * @param int|null                           $limit the amount if items stored in the cache. Using a limit will reduce memory leaks.
     * @param array<int, BaseCacheItemInterface> $cache
     */
    public function __construct(?int $limit = null, array &$cache = [])
    {
        $this->cache = &$cache;
        $this->limit = $limit;
    }

    protected function getItemWithoutGenerateCacheKey(string $key): BaseCacheItemInterface
    {
        if (isset($this->deferred[$key])) {
            return clone $this->deferred[$key];
        }

        return $this->fetchObjectFromCache($key);
    }

    /**
     * {@inheritdoc}
     */
    protected function fetchObjectFromCache(string $key): BaseCacheItemInterface
    {
        if (isset($this->cache[$key])) {
            return clone $this->cache[$key];
        }

        return new EmptyCacheItem($key, null);

    }

    /**
     * {@inheritdoc}
     */
    protected function clearAllObjectsFromCache(): bool
    {
        $this->cache = [];

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function clearOneObjectFromCache($key): bool
    {
        unset($this->cache[$key]);
        $this->commit();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function storeItemInCache(BaseCacheItemInterface $item, $ttl): bool
    {
        $key   = $item->getKey();
        $value = $item->get();

        if (is_object($value)) {
            $value = clone $value;
        }

        $this->cache[$key] = new CacheItem($key, $value, null);

        if ($this->limit !== null) {
            // Remove the oldest value
            if (isset($this->keyMap[$this->currentPosition])) {
                unset($this->cache[$this->keyMap[$this->currentPosition]]);
            }

            // Add the new key to the current position
            $this->keyMap[$this->currentPosition] = $key;

            // Increase the current position
            $this->currentPosition = ($this->currentPosition + 1) % $this->limit;
        }

        return true;
    }

    /**
     * {@inheritdDoc}.
     *
     * @param string $name
     *
     * @return array
     */
    protected function getList(string $name): array
    {
        if (!isset($this->cache[$name])) {
            $this->cache[$name] = [];
        }

        return $this->cache[$name];
    }

    /**
     * {@inheritdoc}
     */
    protected function removeList($name): bool
    {
        unset($this->cache[$name]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function appendListItem($name, $key): void
    {
        $this->cache[$name][] = $key;
    }

    /**
     * {@inheritdoc}
     */
    protected function removeListItem($name, $key): void
    {
        if (isset($this->cache[$name])) {
            foreach ($this->cache[$name] as $i => $item) {
                if ($item === $key) {
                    unset($this->cache[$name][$i]);
                }
            }
        }
    }
}