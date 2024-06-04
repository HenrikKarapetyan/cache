<?php

namespace Henrik\Cache\Adapters;

use Henrik\Cache\AbstractCachePool;
use Henrik\Cache\EmptyCacheItem;
use Henrik\Cache\Interfaces\BaseCacheItemInterface;

class VoidCachePool extends AbstractCachePool
{
    protected function storeItemInCache(BaseCacheItemInterface $item, ?int $ttl): bool
    {
        return true;
    }

    protected function fetchObjectFromCache(string $key): BaseCacheItemInterface
    {
        return new EmptyCacheItem($key, null);
    }

    protected function clearAllObjectsFromCache(): bool
    {
        return true;
    }

    protected function clearOneObjectFromCache(string $key): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function getList(string $name): array
    {
        return [];
    }

    protected function removeList(string $name): bool
    {
        return true;
    }

    protected function appendListItem(string $name, string $key): void {}

    protected function removeListItem(string $name, string $key): void {}
}