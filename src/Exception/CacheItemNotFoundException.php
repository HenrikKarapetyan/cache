<?php

namespace Henrik\Cache\Exception;

use Throwable;

class CacheItemNotFoundException extends CacheException
{
    public function __construct(string $key, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('The key `%s` not found!', $key), $code, $previous);
    }
}