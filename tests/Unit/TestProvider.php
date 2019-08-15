<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests\Unit;

class TestProvider
{
    public function add($a, $b)
    {
        return $a + $b;
    }
}
