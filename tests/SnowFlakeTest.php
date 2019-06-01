<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests;

use HZEX\SimpleRpc\SnowFlake;
use LengthException;
use PHPUnit\Framework\TestCase;

class SnowFlakeTest extends TestCase
{
    public function testInit()
    {
        $sf = new SnowFlake(128);
        for ($i = 10; $i; $i--) {
            $this->assertTrue($sf->nextId() < $sf->nextId());
        }
    }

    public function workerIdProvider()
    {
        return [
            [-9999], [9999]
        ];
    }

    /**
     * 测试工作Id
     * @dataProvider workerIdProvider
     * @param $wid
     */
    public function testWorkerId($wid)
    {
        $this->expectException(LengthException::class);

        new SnowFlake($wid);
    }
}
