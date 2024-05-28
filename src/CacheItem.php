<?php

namespace Henrik\Cache;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;

class CacheItem implements CacheItemInterface
{
    private string $key;

    private mixed $value;

    private ?int $expiration = null;

    public function __construct(string $key, mixed $value = null, ?DateTimeInterface $expiration = null)
    {
        $this->key   = $key;
        $this->value = $value;

        if ($expiration) {
            $this->expiresAt($expiration);
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        if ($this->isHit()) {
            return $this->value;
        }

        return null;
    }

    public function isHit(): bool
    {
        if ($this->expiration !== null) {
            return $this->expiration > time();
        }

        return true;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if (!is_null($expiration)) {
            $this->expiration = $expiration->getTimestamp();
        }

        return $this;
    }

    public function expiresAfter(null|DateInterval|int $time): static
    {
        if (is_int($time)) {
            $this->expiration = time() + $time;
        }

        if ($time instanceof DateInterval) {
            $this->expiration = (new DateTime())->add($time)->getTimestamp();
        }

        return $this;
    }

    public function getExpiration(): ?int
    {
        return $this->expiration;
    }
}