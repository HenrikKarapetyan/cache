<?php

namespace Henrik\Cache\Tests;

use Henrik\Cache\Adapters\ArrayCachePool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\InvalidArgumentException;

class ArrayCachePoolTest extends TestCase
{
    /**
     * @throws InvalidArgumentException
     */
    public function testLimit()
    {
        $pool = new ArrayCachePool(2);
        $item = $pool->getItem('key1')->set('value1');
        $pool->save($item);

        $item = $pool->getItem('key2')->set('value2');
        $pool->save($item);

        // Both items should be in the pool, nothing strange yet
        $this->assertTrue($pool->hasItem('key1'));
        $this->assertTrue($pool->hasItem('key2'));

        $item = $pool->getItem('key3')->set('value3');
        $pool->save($item);

        // First item should be dropped
        $this->assertFalse($pool->hasItem('key1'));
        $this->assertTrue($pool->hasItem('key2'));
        $this->assertTrue($pool->hasItem('key3'));

        $this->assertFalse($pool->getItem('key1')->isHit());
        $this->assertTrue($pool->getItem('key2')->isHit());
        $this->assertTrue($pool->getItem('key3')->isHit());

        $item = $pool->getItem('key4')->set('value4');
        $pool->save($item);

//        // Only the last two items should be in place
        $this->assertFalse($pool->hasItem('key1'));
        $this->assertFalse($pool->hasItem('key2'));
        $this->assertTrue($pool->hasItem('key3'));
        $this->assertTrue($pool->hasItem('key4'));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testRemoveListItem()
    {
        $pool = new ArrayCachePool();
        // Add a tagged item to test list removal
        $item = $pool->getItem('key1')->set('value1');
        $pool->save($item);

        $this->assertTrue($pool->hasItem('key1'));
        $this->assertTrue($pool->deleteItem('key1'));
        $this->assertFalse($pool->hasItem('key1'));

    }
}