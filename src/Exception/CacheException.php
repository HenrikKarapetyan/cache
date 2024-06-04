<?php

namespace Henrik\Cache\Exception;

use Psr\Cache\CacheException as CacheExceptionInterface;
use RuntimeException;

abstract class CacheException extends RuntimeException implements CacheExceptionInterface {}
