<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Stub;

class Tests
{
    public static function runStaticTest()
    {
        return 'success';
    }

    public function runTest()
    {
        return 'success';
    }
}
