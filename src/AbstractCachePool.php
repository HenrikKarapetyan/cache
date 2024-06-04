<?php

namespace Henrik\Cache;

use DateInterval;
use Exception;
use Generator;
use Henrik\Cache\Exception\CacheException;
use Henrik\Cache\Exception\CachePoolException;
use Henrik\Cache\Exception\InvalidArgumentException;
use Henrik\Cache\Interfaces\BaseCacheItemInterface;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Traversable;

abstract class AbstractCachePool implements LoggerAwareInterface, CacheInterface, CacheItemPoolInterface
{
    /**
     * @var BaseCacheItemInterface[] deferred
     */
    protected array $deferred = [];

    /**
     * @var ?LoggerInterface
     */
    private ?LoggerInterface $logger;

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
            return clone $this->deferred[$key];
        }

        return $this->fetchObjectFromCache($key);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<BaseCacheItemInterface>
     */
    public function getItems(array $keys = []): array
    {
        $items = [];

        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function hasItem($key): bool
    {
        try {
            return $this->getItem($key)->isHit();
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

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

    public function deleteItem(string $key): bool
    {
        try {
            return $this->deleteItems([$key]);
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
        }
    }

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

    public function save(CacheItemInterface $item): bool
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

    public function saveDeferred(CacheItemInterface $item): true
    {
        if (!$item instanceof BaseCacheItemInterface) {
            $e = new InvalidArgumentException('Cache items are not transferable between pools. Item MUST implement BaseCacheItemInterface.');
            $this->handleException($e, __FUNCTION__);
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

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
    public function get(string $key, $default = null): mixed
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
    public function set(string $key, mixed $value, null|DateInterval|int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttl);

        return $this->save($item);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        return $this->deleteItem($key);
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
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
    public function setMultiple(iterable $values, $ttl = null): bool
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
     * @throws \Psr\Cache\InvalidArgumentException
     *
     * @return $this
     */
    protected function preRemoveItem(string $key): static
    {
        $this->getItem($key);

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
     * @return BaseCacheItemInterface
     */
    abstract protected function fetchObjectFromCache(string $key): BaseCacheItemInterface;

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
     * @return array<string, mixed>
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
    abstract protected function appendListItem(string $name, string $key): void;

    /**
     * Remove an item from the list.
     *
     * @param string $name
     * @param string $key
     */
    abstract protected function removeListItem(string $name, string $key): void;

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException|Exception
     */
    protected function validateKey(string $key): void
    {
        if (!isset($key[0])) {
            $e = new InvalidArgumentException('Cache key cannot be an empty string');
            $this->handleException($e, __FUNCTION__);
        }

        if (preg_match('#[{}()/@:]#u', $key)) {
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
    protected function log(mixed $level, string $message, array $context = []): void
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
    private function handleException(Exception $e, string $function): void
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
     * @param mixed                         $default
     * @param array<BaseCacheItemInterface> $items
     *
     * @return Generator
     */
    private function generateValues(mixed $default, array $items): Generator
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
