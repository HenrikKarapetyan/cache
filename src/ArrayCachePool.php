<?php

namespace Henrik\Cache;

use Henrik\Cache\Exception\CacheItemNotFoundException;
use Psr\Cache\CacheItemInterface;

class ArrayCachePool extends AbstractCachePool
{
    /** @var array<string, CacheItemInterface> */
    private array $cacheData;

    /**
     * {@inheritDoc}
     */
    public function getItem(string $key): CacheItemInterface
    {
        return $this->cacheData[$key];
    }

    /**
     *{@inheritDoc}
     *
     * @throws CacheItemNotFoundException
     *
     * @return iterable<CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            if (!$this->hasItem($key)) {
                throw new CacheItemNotFoundException($key);
            }

            yield $this->getItem($key);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        $this->cacheData[$item->getKey()] = $item;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function deleteAllItems(): bool
    {
        $this->cacheData = [];

        return true;
    }
}