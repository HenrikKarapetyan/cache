<?php

namespace Henrik\Cache;

use Henrik\Cache\Exception\InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

abstract class AbstractCachePool implements CacheItemPoolInterface
{
    /** @var array<string, CacheItemInterface> */
    private array $deferred;
    private ?int $limit;

    public function __construct()
    {
        $this->deferred = [];
        $this->limit    = null;
    }

    public function __destruct()
    {
        $this->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->deleteAllItems();
    }

    public function deleteItem(string $key): bool
    {
        return $this->deleteItems([$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $this->checkKey($key);

            // Delete form deferred
            unset($this->deferred[$key]);

            // We have to commit here to be able to remove deferred hierarchy items
            $this->commit();
            $this->deleteItem($key);
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        $saved = true;

        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $saved = false;
            }
        }
        $this->deferred = [];

        return $saved;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function setLimit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    abstract protected function deleteAllItems(): bool;

    /**
     * @param mixed $key
     *
     * @throws InvalidArgumentException
     */
    private function checkKey(mixed $key): void
    {
        if (!is_string($key)) {
            throw new InvalidArgumentException(sprintf(
                'Cache key must be string, "%s" given',
                gettype($key)
            ));
        }

        if (!isset($key[0])) {
            throw new InvalidArgumentException('Cache key cannot be an empty string');
        }

        if (preg_match('|[{}()/@:]|', $key)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid key: "%s". The key contains one or more characters reserved for future extension: {}()/\@:',
                $key
            ));
        }
    }
}