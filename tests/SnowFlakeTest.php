<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\SnowFlake;
use PHPUnit\Framework\TestCase;

class SnowFlakeTest extends TestCase
{
    public function testInit()
    {
        $sf = new SnowFlake(128);
        for ($i = 10; $i; $i--) {
            var_dump(decbin($sf->nextId()));
            $this->assertTrue($sf->nextId() < $sf->nextId());
        }
    }
}
