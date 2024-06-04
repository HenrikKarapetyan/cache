<?php

namespace Henrik\Cache;

use DateTimeInterface;

class EmptyCacheItem extends CacheItem
{
    public function __construct(string $key, mixed $value, ?DateTimeInterface $expiration = null)
    {
        parent::__construct($key, $value, $expiration);
        $this->hasValue = false;
    }
}