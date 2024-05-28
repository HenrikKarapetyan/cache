<?php

namespace Henrik\Cache\Exception;

use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;
use Throwable;

class InvalidArgumentException extends CacheException implements PsrInvalidArgumentException, SimpleCacheInvalidArgumentException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}