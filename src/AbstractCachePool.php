<?php

namespace Henrik\Cache;

use Exception;
use Generator;
use Henrik\Cache\Exception\CacheException;
use Henrik\Cache\Exception\CachePoolException;
use Henrik\Cache\Interfaces\BaseCacheItemInterface;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Traversable;

abstract class AbstractCachePool implements LoggerAwareInterface, CacheInterface
{
    /**
     * @var BaseCacheItemInterface[] deferred
     */
    protected array $deferred = [];

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Make sure to commit before we destruct.
     */
    public function __destruct()
    {
        $this->commit();
    }

    public function getItem($key): BaseCacheItemInterface
    {
        $this->validateKey($key);

        if (isset($this->deferred[$key])) {
            /** @var CacheItem $item */
            return clone $this->deferred[$key];
        }

        $func = function () use ($key) {
            try {
                return $this->fetchObjectFromCache($key);
            } catch (Exception $e) {
                $this->handleException($e, __FUNCTION__);
            }
        };

        return new CacheItem($key, $func);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = []): array
    {
        $items = [];

        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key)
    {
        try {
            return $this->getItem($key)->isHit();
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        // Clear the deferred items
        $this->deferred = [];

        try {
            return $this->clearAllObjectsFromCache();
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem($key)
    {
        try {
            return $this->deleteItems([$key]);
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $this->validateKey($key);

            // Delete form deferred
            unset($this->deferred[$key]);

            // We have to commit here to be able to remove deferred hierarchy items
            $this->commit();
            $this->preRemoveItem($key);

            if (!$this->clearOneObjectFromCache($key)) {
                $deleted = false;
            }
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item)
    {
        if (!$item instanceof BaseCacheItemInterface) {
            $e = new InvalidArgumentException('Cache items are not transferable between pools. Item MUST implement BaseCacheItemInterface.');
            $this->handleException($e, __FUNCTION__);
        }

        $timeToLive = null;

        if (null !== $timestamp = $item->getExpirationTimestamp()) {
            $timeToLive = $timestamp - time();

            if ($timeToLive < 0) {
                return $this->deleteItem($item->getKey());
            }
        }

        try {
            return $this->storeItemInCache($item, $timeToLive);
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): true
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

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, $default = null): mixed
    {
        $item = $this->getItem($key);

        if (!$item->isHit()) {
            return $default;
        }

        return $item->get();
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttl);

        return $this->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key): bool
    {
        return $this->deleteItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple($keys, $default = null): iterable
    {
        if (!is_array($keys)) {
            if (!$keys instanceof Traversable) {
                throw new InvalidArgumentException('$keys is neither an array nor Traversable');
            }

            // Since we need to throw an exception if *any* key is invalid, it doesn't
            // make sense to wrap iterators or something like that.
            $keys = iterator_to_array($keys, false);
        }

        $items = $this->getItems($keys);

        return $this->generateValues($default, $items);
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple($values, $ttl = null): bool
    {
        if (!is_array($values)) {
            if (!$values instanceof Traversable) {
                throw new InvalidArgumentException('$values is neither an array nor Traversable');
            }
        }

        $keys        = [];
        $arrayValues = [];

        foreach ($values as $key => $value) {
            if (is_int($key)) {
                $key = (string) $key;
            }
            $this->validateKey($key);
            $keys[]            = $key;
            $arrayValues[$key] = $value;
        }

        $items       = $this->getItems($keys);
        $itemSuccess = true;

        foreach ($items as $key => $item) {
            $item->set($arrayValues[$key]);

            try {
                $item->expiresAfter($ttl);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
            }

            $itemSuccess = $itemSuccess && $this->saveDeferred($item);
        }

        return $itemSuccess && $this->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple($keys): bool
    {
        if (!is_array($keys)) {
            if (!$keys instanceof Traversable) {
                throw new InvalidArgumentException('$keys is neither an array nor Traversable');
            }

            // Since we need to throw an exception if *any* key is invalid, it doesn't
            // make sense to wrap iterators or something like that.
            $keys = iterator_to_array($keys, false);
        }

        return $this->deleteItems($keys);
    }

    /**
     * {@inheritdoc}
     */
    public function has($key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * @param string $key
     *
     * @return $this
     */
    protected function preRemoveItem(string $key): static
    {
        $item = $this->getItem($key);

        return $this;
    }

    /**
     * @param BaseCacheItemInterface $item
     * @param int|null               $ttl  seconds from now
     *
     * @return bool true if saved
     */
    abstract protected function storeItemInCache(BaseCacheItemInterface $item, ?int $ttl): bool;

    /**
     * Fetch an object from the cache implementation.
     *
     * If it is a cache miss, it MUST return [false, null, [], null]
     *
     * @param string $key
     *
     * @return array with [isHit, value, tags[], expirationTimestamp]
     */
    abstract protected function fetchObjectFromCache(string $key): array;

    /**
     * Clear all objects from cache.
     *
     * @return bool false if error
     */
    abstract protected function clearAllObjectsFromCache(): bool;

    /**
     * Remove one object from cache.
     *
     * @param string $key
     *
     * @return bool
     */
    abstract protected function clearOneObjectFromCache(string $key): bool;

    /**
     * Get an array with all the values in the list named $name.
     *
     * @param string $name
     *
     * @return array
     */
    abstract protected function getList(string $name): array;

    /**
     * Remove the list.
     *
     * @param string $name
     *
     * @return bool
     */
    abstract protected function removeList(string $name): bool;

    /**
     * Add a item key on a list named $name.
     *
     * @param string $name
     * @param string $key
     */
    abstract protected function appendListItem(string $name, string $key);

    /**
     * Remove an item from the list.
     *
     * @param string $name
     * @param string $key
     */
    abstract protected function removeListItem(string $name, string $key);

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     */
    protected function validateKey($key): void
    {
        if (!is_string($key)) {
            $e = new InvalidArgumentException(sprintf(
                'Cache key must be string, "%s" given',
                gettype($key)
            ));
            $this->handleException($e, __FUNCTION__);
        }

        if (!isset($key[0])) {
            $e = new InvalidArgumentException('Cache key cannot be an empty string');
            $this->handleException($e, __FUNCTION__);
        }

        if (preg_match('|[{}()/@:]|', $key)) {
            $e = new InvalidArgumentException(sprintf(
                'Invalid key: "%s". The key contains one or more characters reserved for future extension: {}()/\@:',
                $key
            ));
            $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * Logs with an arbitrary level if the logger exists.
     *
     * @param mixed  $level
     * @param string $message
     * @param array  $context
     */
    protected function log(mixed $level, string $message, array $context = [])
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Log exception and rethrow it.
     *
     * @param Exception $e
     * @param string    $function
     *
     * @throws CachePoolException|Exception
     */
    private function handleException(Exception $e, string $function)
    {
        $level = 'alert';

        if ($e instanceof InvalidArgumentException) {
            $level = 'warning';
        }

        $this->log($level, $e->getMessage(), ['exception' => $e]);

        if (!$e instanceof CacheException) {
            $e = new CachePoolException(sprintf('Exception thrown when executing "%s". ', $function), 0, $e);
        }

        throw $e;
    }

    /**
     * @param $default
     * @param $items
     *
     * @return Generator
     */
    private function generateValues($default, $items): Generator
    {
        foreach ($items as $key => $item) {
            /** @var CacheItemInterface $item */
            if (!$item->isHit()) {
                yield $key => $default;
            } else {
                yield $key => $item->get();
            }
        }
    }
}
