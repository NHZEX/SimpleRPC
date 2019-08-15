<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tests\Unit;

use HZEX\SimpleRpc\Transfer\Instance\RpcFacadeClass;

/**
 * Class TestFacade
 * @package HZEX\SimpleRpc\Tests\Unit
 * @method int add(int $a, int $b)
 */
class TestFacade extends RpcFacadeClass
{
    /**
     * @return string
     */
    protected function getFacadeClass(): string
    {
        return 'TestProvider';
    }
}
