<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Stub;

use HZEX\SimpleRpc\RpcFacade;
use HZEX\SimpleRpc\Transfer;

/**
 * Class TestsRpcFacade
 * @package HZEX\SimpleRpc\Stub
 * @method Transfer runAutoUpdate(string $a, int $b, bool $c)
 */
class TestsRpcFacade extends RpcFacade
{
    protected function getFacadeClass(): string
    {
        return 'Tests1';
    }
}
