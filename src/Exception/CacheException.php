<?php

namespace Henrik\Cache\Exception;

use Exception;
use Psr\Cache\CacheException as PsrCacheException;
use Throwable;

class CacheException extends Exception implements PsrCacheException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}