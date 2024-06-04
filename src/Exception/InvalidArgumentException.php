<?php

namespace Henrik\Cache\Exception;

use Psr\Cache\InvalidArgumentException as CacheInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;

class InvalidArgumentException extends CacheException implements CacheInvalidArgumentException, SimpleCacheInvalidArgumentException {}
