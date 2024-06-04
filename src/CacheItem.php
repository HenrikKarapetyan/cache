<?php

namespace Henrik\Cache;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Henrik\Cache\Interfaces\BaseCacheItemInterface;
use InvalidArgumentException;

class CacheItem implements BaseCacheItemInterface
{
    protected bool $hasValue = true;
    private string $key;

    private mixed $value;
    private ?int $expirationTimestamp;

    /**
     * @param string                 $key
     * @param mixed                  $value
     * @param DateTimeInterface|null $expiration
     */
    public function __construct(string $key, mixed $value, ?DateTimeInterface $expiration = null)
    {
        $this->key = $key;
        $this->set($value);
        $this->expiresAt($expiration);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {

        if (!$this->hasValue) {
            return false;
        }

        if ($this->expirationTimestamp !== null) {
            return $this->expirationTimestamp > time();
        }

        return true;
    }

    public function set(mixed $value): static
    {
        $this->value    = $value;
        $this->hasValue = true;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expirationTimestamp = null;

        if ($expiration instanceof DateTimeInterface) {
            $this->expirationTimestamp = $expiration->getTimestamp();
        }

        return $this;
    }

    public function expiresAfter(null|DateInterval|int $time): static
    {
        if ($time === null) {
            $this->expirationTimestamp = null;
        } elseif ($time instanceof DateInterval) {
            $date = new DateTime();
            $date->add($time);
            $this->expirationTimestamp = $date->getTimestamp();
        } elseif (is_int($time)) {
            $this->expirationTimestamp = time() + $time;
        } else {
            throw new InvalidArgumentException('Cache item ttl/expiresAfter must be of type integer or \DateInterval.');
        }

        return $this;
    }

    public function getExpirationTimestamp(): ?int
    {
        return $this->expirationTimestamp;
    }
}