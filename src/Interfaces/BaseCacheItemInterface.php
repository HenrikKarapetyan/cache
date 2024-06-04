<?php

namespace Henrik\Cache\Interfaces;

use Psr\Cache\CacheItemInterface;

interface BaseCacheItemInterface extends CacheItemInterface
{
    public function getExpirationTimestamp(): ?int;
}